<?php

declare(strict_types=1);

namespace App\Engine\Ability;

use App\Engine\Action\ActivateAbility;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\PlayerState;
use App\Engine\Game\StackItem;
use App\Engine\Mana\ManaPayment;
use App\Engine\Mana\ManaPlan;

/**
 * Activation des capacités activées. Seul chemin d'activation (humain, IA) : une capacité de mana est résolue
 * immédiatement (règle 605 : ni pile, ni nouvelle ronde, la priorité reste au joueur) ; toute autre capacité
 * va sur la pile et remet les passes à zéro.
 *
 * Coûts payés à l'activation, dans cet ordre : mana (les autres sources), {T}, défausse, sacrifices. Les événements
 * de coût (`CardDiscarded`, `PermanentSacrificed`) suivent l'événement d'activation.
 */
final class AbilityManager
{
    /**
     * Peut-on activer cette capacité maintenant (timing, coût payable, source prête, choix de coût possibles) ? Hors
     * `sorcerySpeed`, le timing est celui de l'instant (priorité).
     */
    public static function canActivate(GameState $s, PlayerState $p, CardInstance $card, ActivatedAbility $ability): bool
    {
        if (!self::sourceReady($card, $ability, $s->turn)) {
            return false;
        }
        if ($ability->sorcerySpeed
            && !($p->id === $s->activePlayer && $s->phase->isMain() && $s->stack === [] && $s->combat->declaring === null)) {
            return false;
        }
        if (self::costChoices($p, $card, $ability) === []) {
            return false;
        }
        if ($ability->manaCost !== null && self::costPlan($s, $p, $card, $ability) === null) {
            return false;
        }

        return true;
    }

    /**
     * La source peut-elle payer le `{T}` de la capacité au tour `$turn` ? Non engagée, et pas une créature arrivée ce tour
     * (mal d'invocation). Partagée avec l'auto-paiement (`ManaPayment`) ; `$turn` null = mal d'invocation ignoré.
     */
    public static function sourceReady(CardInstance $card, ActivatedAbility $ability, ?int $turn): bool
    {
        if (!$ability->tap) {
            return true;
        }

        return !$card->tapped && !($turn !== null && $card->isCreature() && $card->enteredTurn >= $turn);
    }

    /**
     * Choix de coût possibles : créature à sacrifier (`sacrificeCreature`) puis cartes à défausser (`discard`), chaque choix
     * dans l'ordre du champ de bataille / de la main. `[[]]` si la capacité n'en demande aucun ; `[]` si impossible.
     *
     * @return list<list<CardInstance>>
     */
    public static function costChoices(PlayerState $p, CardInstance $card, ActivatedAbility $ability): array
    {
        $sacrifices = [[]];
        if ($ability->sacrificeCreature) {
            $sacrifices = array_map(
                static fn (CardInstance $c) => [$c],
                array_values(array_filter($p->battlefield, static fn (CardInstance $c) => $c->isCreature())),
            );
        }
        $discards = self::combinations($p->hand, $ability->discard);

        $out = [];
        foreach ($sacrifices as $sacrifice) {
            foreach ($discards as $discard) {
                $out[] = [...$sacrifice, ...$discard];
            }
        }

        return $out;
    }

    /**
     * Combinaisons de `$k` cartes parmi `$cards` (ordre conservé).
     *
     * @param list<CardInstance> $cards
     * @return list<list<CardInstance>>
     */
    private static function combinations(array $cards, int $k, int $from = 0): array
    {
        if ($k === 0) {
            return [[]];
        }
        $out = [];
        for ($i = $from; $i <= count($cards) - $k; $i++) {
            foreach (self::combinations($cards, $k - 1, $i + 1) as $rest) {
                $out[] = [$cards[$i], ...$rest];
            }
        }

        return $out;
    }

    /** Plan de paiement du coût de mana d'une capacité ; la source ne paie jamais son propre coût. */
    private static function costPlan(GameState $s, PlayerState $p, CardInstance $card, ActivatedAbility $ability): ?ManaPlan
    {
        return ManaPayment::plan($p, $ability->manaCost, 0, $s->turn, $card->id);
    }

    public static function activate(GameState $s, ActivateAbility $a): void
    {
        $p = $s->player($a->player());
        $card = $p->findOnBattlefield($a->cardId) ?? throw new IllegalActionException('Source de la capacité absente du champ de bataille');
        $ability = $card->abilities()[$a->abilityIndex] ?? throw new IllegalActionException('Capacité inconnue');
        if (!self::canActivate($s, $p, $card, $ability)) {
            throw new IllegalActionException('Capacité non activable');
        }
        if ($ability->isManaAbility && !isset($ability->options[$a->option])) {
            throw new IllegalActionException('Alternative de mana inconnue');
        }
        $valid = array_map(
            static fn (array $choice) => array_map(static fn (CardInstance $c) => $c->id, $choice),
            self::costChoices($p, $card, $ability),
        );
        if (!in_array($a->costChoices, $valid, true)) {
            throw new IllegalActionException('Choix de coût invalide');
        }

        // Mana : payé par les autres sources (Signet, Mind Stone) ; elles sont journalisées dans `tapped`.
        $payers = [];
        $sacrifice = [];
        if ($ability->manaCost !== null) {
            $plan = self::costPlan($s, $p, $card, $ability) ?? throw new IllegalActionException('Mana insuffisant pour la capacité');
            ['tapped' => $payers, 'sacrifice' => $sacrifice] = $p->payWithPlan($plan);
        }
        $tapped = [...($ability->tap ? [$card->id] : []), ...$payers];
        if ($ability->sacrifice) {
            $sacrifice[] = $card->id; // Treasure, Mind Stone : sacrifié une fois activé
        }
        $discard = $a->costChoices;
        if ($ability->sacrificeCreature) {
            $sacrifice[] = array_shift($discard);
        }

        if ($ability->isManaAbility) {
            $mana = $p->activateManaSource($card, $ability, $a->option);
            $s->emit('ManaAbilityActivated', [
                'player' => $a->player(),
                'card' => $card->toPublic(),
                'abilityIndex' => $a->abilityIndex,
                'ability' => $ability->toArray(),
                'option' => $a->option,
                'mana' => $mana,
                'tapped' => $tapped,
                'pool' => $p->pool->toArray(),
            ], verbose: true);
            self::payCardCosts($s, $a->player(), $discard, $sacrifice);

            return; // résolue sur-le-champ : la pile et les passes ne sont pas touchées
        }

        if ($ability->tap) {
            $card->tapped = true;
        }
        // Copie de la source : l'objet sur la pile ne doit pas partager l'état de la carte du champ de bataille (sinon un
        // bonus ultérieur le modifierait en partie réelle mais pas dans un état reconstruit depuis une ancre).
        $item = new StackItem('s' . $s->nextStackId++, clone $card, $a->player(), $a->targets, 0, $ability, $a->abilityIndex);
        $s->stack[] = $item;
        $s->emit('AbilityActivated', [
            'player' => $a->player(),
            'stackId' => $item->id,
            'card' => $card->toPublic(),
            'abilityIndex' => $a->abilityIndex,
            'ability' => $ability->toArray(),
            'targets' => $a->targets,
            'tapped' => $tapped,
        ]);
        self::payCardCosts($s, $a->player(), $discard, $sacrifice);

        $s->priority->passed = [];
        $s->priority->announced = false; // l'activateur reçoit la priorité en premier
    }

    /**
     * Coûts « cartes » journalisés après l'activation : défausses (`GameState::discard`), puis sacrifices.
     *
     * @param list<string> $discard
     * @param list<string> $sacrifice
     */
    private static function payCardCosts(GameState $s, int $player, array $discard, array $sacrifice): void
    {
        foreach ($discard as $id) {
            $s->discard($player, $id);
        }
        $s->sacrifice($player, $sacrifice);
    }
}
