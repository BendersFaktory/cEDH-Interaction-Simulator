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
 */
final class AbilityManager
{
    /** Peut-on activer cette capacité maintenant (coût payable, source prête) ? Le timing est celui de l'instant (priorité). */
    public static function canActivate(GameState $s, PlayerState $p, CardInstance $card, ActivatedAbility $ability): bool
    {
        if (!self::sourceReady($card, $ability, $s->turn)) {
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

        if ($ability->isManaAbility) {
            if (!isset($ability->options[$a->option])) {
                throw new IllegalActionException('Alternative de mana inconnue');
            }
            // Coût de mana (Signet) : payé par les autres sources avant l'activation ; elles sont journalisées dans `tapped`.
            $payers = [];
            $sacrifice = [];
            if ($ability->manaCost !== null) {
                $plan = self::costPlan($s, $p, $card, $ability) ?? throw new IllegalActionException('Mana insuffisant pour la capacité');
                ['tapped' => $payers, 'sacrifice' => $sacrifice] = $p->payWithPlan($plan);
            }
            if ($ability->sacrifice) {
                $sacrifice[] = $card->id; // Treasure : sacrifié une fois le mana produit
            }
            $mana = $p->activateManaSource($card, $ability, $a->option);
            $s->emit('ManaAbilityActivated', [
                'player' => $a->player(),
                'card' => $card->toPublic(),
                'abilityIndex' => $a->abilityIndex,
                'ability' => $ability->toArray(),
                'option' => $a->option,
                'mana' => $mana,
                'tapped' => [...($ability->tap ? [$card->id] : []), ...$payers],
                'pool' => $p->pool->toArray(),
            ], verbose: true);
            $s->sacrifice($a->player(), $sacrifice);

            return; // résolue sur-le-champ : la pile et les passes ne sont pas touchées
        }

        $tapped = [];
        $sacrifice = [];
        if ($ability->manaCost !== null) {
            $plan = self::costPlan($s, $p, $card, $ability) ?? throw new IllegalActionException('Mana insuffisant pour la capacité');
            ['tapped' => $tapped, 'sacrifice' => $sacrifice] = $p->payWithPlan($plan);
        }
        if ($ability->tap) {
            $card->tapped = true;
            array_unshift($tapped, $card->id);
        }

        $item = new StackItem('s' . $s->nextStackId++, $card, $a->player(), $a->targets, 0, $ability, $a->abilityIndex);
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
        $s->sacrifice($a->player(), $sacrifice);

        $s->priority->passed = [];
        $s->priority->announced = false; // l'activateur reçoit la priorité en premier
    }
}
