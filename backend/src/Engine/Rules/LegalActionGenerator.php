<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\AbilityManager;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\AssignCombatDamage;
use App\Engine\Action\BottomCard;
use App\Engine\Action\CastSpell;
use App\Engine\Action\Discard;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Action\KeepHand;
use App\Engine\Action\KeepLegend;
use App\Engine\Action\Mulligan;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PlayLand;
use App\Engine\Action\PutTrigger;
use App\Engine\Card\SpellEffect;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\CardInstance;
use App\Engine\Game\CombatState;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PlayerState;
use App\Engine\Mana\ManaContext;
use App\Engine\Mana\ManaPayment;

/**
 * Calcule les actions légales d'un joueur À CET INSTANT (état du GameState fait foi).
 * Alimente à la fois les agents IA et l'ActionPanel du front.
 * TODO: coûts colorés, X, coûts alternatifs, capacités activées, vitesse « flash », ciblage riche.
 */
final class LegalActionGenerator
{
    /** Plus grand X proposé pour un sort à {X} (évite des centaines d'actions identiques). */
    private const MAX_X = 10;

    /** @return list<Action> */
    public function for(GameState $s, int $playerId): array
    {
        if ($s->mulligan !== null) {
            return $this->mulliganActions($s, $playerId);
        }
        if ($s->sbaResume !== null) {
            return $this->legendActions($s, $playerId);
        }
        if ($s->triggerResumeHolder !== null) {
            return $this->triggerActions($s, $playerId);
        }

        if ($s->combat->declaring === CombatState::DAMAGE) {
            return $this->assignmentActions($s, $playerId);
        }
        if ($s->combat->declaring !== null) {
            return $this->declarationActions($s, $playerId);
        }

        // Défausse du cleanup ; pendant une ronde de priorité du cleanup (règle 514.3a), priorité ordinaire.
        if ($s->phase === Phase::Cleanup && !$s->cleanupRound && $playerId === $s->activePlayer
            && count($s->player($playerId)->hand) > PlayerState::MAX_HAND_SIZE) {
            return $this->discardActions($s, $playerId);
        }

        $p = $s->player($playerId);
        $actions = [new PassPriority($playerId)];

        $sorcerySpeed = $playerId === $s->activePlayer && $s->phase->isMain() && $s->stack === [];

        // Terrain : action spéciale, une par tour, timing sorcery.
        if ($sorcerySpeed && $s->landsPlayed < 1) {
            $seen = [];
            foreach ($p->hand as $card) {
                if ($card->isLand() && !isset($seen[$card->name])) {
                    $seen[$card->name] = true;
                    if ($card->untappedLifeCost === 0) {
                        $actions[] = new PlayLand($playerId, $card->id);
                        continue;
                    }
                    if ($p->life >= $card->untappedLifeCost) {
                        $actions[] = new PlayLand($playerId, $card->id, true);
                    }
                    $actions[] = new PlayLand($playerId, $card->id, false);
                }
            }
        }

        // Sorts (main + zone de commandement), dédoublonnés par (nom, cibles) pour ne pas lister 4 Bolt identiques.
        // Le coût d'un commandant inclut la taxe : +2 par lancement précédent.
        $seen = [];
        $mana = ManaPayment::context($p, $s->turn); // champ de bataille scanné une seule fois pour tous les sorts et capacités
        foreach ([...$p->hand, ...$p->command] as $card) {
            if ($card->isLand() || (!$card->isInstantSpeed() && !$sorcerySpeed)) {
                continue;
            }
            // Payable avec les terrains non engagés (couleurs, hybrides) ; un sort à {X} offre une action par X payable.
            $cost = $p->castCost($card);
            if (ManaPayment::plan($p, $cost, 0, $s->turn, null, $card, $mana) === null) {
                continue;
            }
            $maxX = $cost->hasX() ? min(ManaPayment::maxX($p, $cost, $s->turn, $card, $mana), self::MAX_X) : 0;
            foreach ($this->targetSets($s, $card) as $targets) {
                for ($x = 0; $x <= $maxX; $x++) {
                    $key = $card->name . '|' . implode(',', $targets) . '|' . $x;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $actions[] = new CastSpell($playerId, $card->id, $targets, $x);
                }
            }
        }

        return [...$actions, ...$this->abilityActions($s, $p, $mana)];
    }

    /**
     * Règle des légendes (voir StateBasedActions) : une action par permanent du premier groupe de légendaires homonymes.
     *
     * @return list<KeepLegend>
     */
    private function legendActions(GameState $s, int $playerId): array
    {
        return array_map(
            static fn (CardInstance $c) => new KeepLegend($playerId, $c->id),
            StateBasedActions::legendGroup($s->player($playerId)) ?? [],
        );
    }

    /**
     * Capacités activées des permanents du joueur, à tout moment où il a la priorité (ou seulement en rituel si
     * `sorcerySpeed`, voir AbilityManager::canActivate). Une action par (alternative, cibles, choix de coût), dédoublonnée
     * par noms : quatre Montagnes non engagées = une seule action ; sacrifier l'un ou l'autre de deux jetons Kobold aussi.
     *
     * @return list<ActivateAbility>
     */
    private function abilityActions(GameState $s, PlayerState $p, ManaContext $mana): array
    {
        $actions = [];
        $seen = [];
        foreach ($p->battlefield as $card) {
            foreach ($card->abilities() as $index => $ability) {
                if (!AbilityManager::canActivate($s, $p, $card, $ability, $mana)) {
                    continue;
                }
                $options = $ability->isManaAbility ? array_keys($ability->options) : [0];
                $targetSets = match ($ability->effect) {
                    'damage' => array_map(static fn (int $id) => ['player:' . $id], $s->alivePlayerIds()),
                    'fetch' => self::fetchTargets($p, $ability),
                    default => [[]],
                };
                foreach (AbilityManager::costChoices($p, $card, $ability) as $choices) {
                    $names = implode(',', array_map(static fn (CardInstance $c) => $c->name, $choices));
                    $ids = array_map(static fn (CardInstance $c) => $c->id, $choices);
                    foreach ($options as $option) {
                        foreach ($targetSets as $targets) {
                            $key = $card->name . '|' . $index . '|' . $option . '|' . implode(',', $targets) . '|' . $names;
                            if (!isset($seen[$key])) {
                                $seen[$key] = true;
                                $actions[] = new ActivateAbility($p->id, $card->id, $index, $option, $targets, $ability->isManaAbility, $ids);
                            }
                        }
                    }
                }
            }
        }

        return $actions;
    }

    /**
     * Défausse obligatoire (main > 7 au cleanup) : une action par nom de carte, pas de « passer ».
     *
     * @return list<Action>
     */
    /**
     * Avant-partie (voir MulliganManager) : garder ou prendre un mulligan (tant que moins de 7 pris), puis, main gardée,
     * une action par carte de la main à mettre au fond (pas de dédoublonnage par nom : le front propose chaque carte).
     *
     * @return list<Action>
     */
    private function mulliganActions(GameState $s, int $playerId): array
    {
        $m = $s->mulligan[$playerId] ?? null;
        if ($m === null) {
            return [];
        }
        if (!$m['kept']) {
            return $m['taken'] < MulliganManager::HAND_SIZE
                ? [new KeepHand($playerId), new Mulligan($playerId)]
                : [new KeepHand($playerId)];
        }
        if (MulliganManager::toBottom($s, $playerId) === 0) {
            return [];
        }

        return array_map(static fn (CardInstance $c) => new BottomCard($playerId, $c->id), $s->player($playerId)->hand);
    }

    private function discardActions(GameState $s, int $playerId): array
    {
        $actions = [];
        $seen = [];
        foreach ($s->player($playerId)->hand as $card) {
            if (!isset($seen[$card->name])) {
                $seen[$card->name] = true;
                $actions[] = new Discard($playerId, $card->id);
            }
        }

        return $actions;
    }

    /**
     * Triggers en attente du joueur : un choix par trigger et par cible, pas de « passer ». Deux triggers
     * identiques (même source par le nom, même capacité) ne donnent qu'un seul choix : leur ordre relatif est indifférent.
     *
     * @return list<PutTrigger>
     */
    public function triggerActions(GameState $s, int $playerId): array
    {
        $actions = [];
        $seen = [];
        foreach ($s->pendingTriggers as $pending) {
            if ($pending->controller !== $playerId) {
                continue;
            }
            $targetSets = $pending->ability()->targetsPlayer()
                ? array_map(static fn (int $id) => ['player:' . $id], $s->alivePlayerIds())
                : [[]];
            foreach ($targetSets as $targets) {
                $key = $pending->source->name . '|' . $pending->index . '|' . implode(',', $targets);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $actions[] = new PutTrigger($playerId, $pending->id, $targets);
                }
            }
        }

        return $actions;
    }

    /**
     * Répartition des dégâts de combat (contrôleur humain de l'attaquant) : une action par répartition légale du
     * prochain attaquant concerné (DamageAssignment::options, la répartition par défaut en tête), pas de « passer ».
     * Vide pour un siège IA (répartition par défaut, sans décision) ou quand il n'y a qu'une répartition possible.
     *
     * @return list<AssignCombatDamage>
     */
    public function assignmentActions(GameState $s, int $playerId): array
    {
        if ($playerId !== $s->activePlayer || !DamageAssignment::chooses($s)) {
            return [];
        }
        $firstStrike = $s->phase === Phase::FirstStrikeDamage;
        foreach (array_keys($s->combat->attackers) as $attackerId) {
            $attackerId = (string) $attackerId;
            if (isset($s->combat->assignments[$attackerId])) {
                continue;
            }
            $options = DamageAssignment::options($s, $attackerId, $firstStrike);
            if (count($options) > 1) {
                return array_map(
                    static fn (array $vector, int $i) => new AssignCombatDamage($playerId, $attackerId, $vector, $i === 0),
                    $options,
                    array_keys($options),
                );
            }
        }

        return [];
    }

    /**
     * Pendant une déclaration (attaquants ou bloqueurs) : un choix par action, puis FinishDeclaration.
     * Seul le déclarant courant a des choix ; la liste est recalculée après chaque déclaration.
     *
     * @return list<Action>
     */
    private function declarationActions(GameState $s, int $playerId): array
    {
        $choices = $s->combat->declaring === 'attackers' ? $this->attackActions($s, $playerId) : $this->blockActions($s, $playerId);
        if ($s->combat->declaring !== 'attackers') {
            $half = [];
            foreach ($s->combat->blockers as $attackerId => $blockers) {
                if (count($blockers) === 1 && $this->isMenaceAttacker($s, $attackerId)) {
                    $half[$attackerId] = true;
                }
            }
            if ($half !== []) {
                $completing = array_values(array_filter($choices, static fn ($c) => isset($half[$c->attackerId])));
                if ($completing !== []) {
                    return $completing;
                }
            }
        }

        return [...$choices, new FinishDeclaration($playerId)];
    }

    /**
     * Attaquants possibles du joueur actif : créature non engagée, arrivée avant ce tour (mal d'invocation),
     * pas déjà déclarée, vers chaque adversaire en vie.
     *
     * @return list<DeclareAttacker>
     */
    public function attackActions(GameState $s, int $playerId): array
    {
        if ($playerId !== $s->activePlayer) {
            return [];
        }
        $actions = [];
        foreach ($s->player($playerId)->battlefield as $card) {
            if (!$card->isCreature() || $card->tapped || $card->hasKeyword(CardInstance::DEFENDER)
                || ($card->enteredTurn >= $s->turn && !$card->hasKeyword(CardInstance::HASTE)) || isset($s->combat->attackers[$card->id])) {
                continue;
            }
            foreach ($s->alivePlayerIds() as $defender) {
                if ($defender !== $playerId) {
                    $actions[] = new DeclareAttacker($playerId, $card->id, $defender);
                }
            }
        }

        return $actions;
    }

    /**
     * Bloqueurs possibles : créature non engagée du défenseur, qui ne bloque pas déjà, sur un attaquant qui le vise.
     *
     * @return list<DeclareBlocker>
     */
    public function blockActions(GameState $s, int $playerId): array
    {
        $attackers = [];
        foreach ($s->combat->attackers as $attackerId => $defender) {
            if ($defender === $playerId && $this->onBattlefield($s, $s->activePlayer, $attackerId)) {
                $attackers[] = $attackerId;
            }
        }
        $actions = [];
        $free = [];
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->isCreature() && !$card->tapped && !$s->combat->isBlocking($card->id)) {
                $free[] = $card;
            }
        }
        $allowed = [];
        foreach ($attackers as $attackerId) {
            $attacker = $this->findCard($s, $s->activePlayer, $attackerId);
            $able = array_filter($free, static fn (CardInstance $c) => !$attacker->hasKeyword(CardInstance::FLYING)
                || $c->hasKeyword(CardInstance::FLYING) || $c->hasKeyword(CardInstance::REACH));
            if ($attacker->hasKeyword(CardInstance::MENACE) && count($s->combat->blockers[$attackerId] ?? []) === 0 && count($able) < 2) {
                continue;
            }
            foreach ($able as $card) {
                $allowed[$card->id][$attackerId] = true;
            }
        }
        foreach ($free as $card) {
            foreach ($attackers as $attackerId) {
                if (isset($allowed[$card->id][$attackerId])) {
                    $actions[] = new DeclareBlocker($playerId, $card->id, $attackerId);
                }
            }
        }

        return $actions;
    }

    private function isMenaceAttacker(GameState $s, string $attackerId): bool
    {
        return $this->findCard($s, $s->activePlayer, $attackerId)?->hasKeyword(CardInstance::MENACE) ?? false;
    }

    private function findCard(GameState $s, int $playerId, string $cardId): ?CardInstance
    {
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    private function onBattlefield(GameState $s, int $playerId, string $cardId): bool
    {
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->id === $cardId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrouve, parmi les actions légales, celle que $injected désigne (jamais de confiance au client).
     *
     * @param list<Action> $legal
     */
    public function match(Action $injected, int $holder, array $legal): Action
    {
        if ($injected->player() === $holder) {
            foreach ($legal as $candidate) {
                if ($candidate->id() === $injected->id()) {
                    return $candidate;
                }
            }
        }

        throw new IllegalActionException(sprintf('Action illégale pour le joueur %d : %s', $holder, $injected->id()));
    }

    /** @return list<list<string>> */
    private function targetSets(GameState $s, CardInstance $card): array
    {
        $effect = $card->targetedEffect();

        return match ($effect?->effect) {
            // Exil : tout permanent (ou toute créature) d'un joueur en vie, le sien compris (`permanent:<id>`).
            'exile' => self::permanentTargets($s, $effect),
            'damage' => array_map(static fn (int $id) => ['player:' . $id], $s->alivePlayerIds()),
            // Un contresort ne cible que des sorts, pas des capacités activées ; Dispel seulement les instants (`SpellEffect::canCounter()`).
            'counter' => array_map(static fn ($item) => ['stack:' . $item->id], array_values(array_filter($s->stack, static fn ($item) => !$item->isAbility() && ($effect?->canCounter($item->card) ?? false)))),
            default => [[]],
        };
    }

    /** @return list<list<string>> une cible `permanent:<id>` par permanent légal, joueurs dans l'ordre du tour */
    private static function permanentTargets(GameState $s, SpellEffect $effect): array
    {
        $out = [];
        foreach ($s->alivePlayerIds() as $id) {
            foreach ($s->player($id)->battlefield as $permanent) {
                if ($effect->canTargetPermanent($permanent)) {
                    $out[] = ['permanent:' . $permanent->id];
                }
            }
        }

        return $out;
    }

    /**
     * Cibles d'une fetchland : un terrain de la bibliothèque qui convient, dédoublonné par nom (`library:<id>`), suivi de `life`
     * pour une shockland dont on paie les PV ; `library:none` si rien ne convient (chercher sans trouver est permis).
     *
     * @return list<list<string>>
     */
    private static function fetchTargets(PlayerState $p, \App\Engine\Ability\ActivatedAbility $ability): array
    {
        $out = [];
        $seen = [];
        $tapped = $ability->fetchSpec()['tapped'];
        foreach ($p->library as $card) {
            if (isset($seen[$card->name]) || !$ability->fetches($card)) {
                continue;
            }
            $seen[$card->name] = true;
            // Shockland cherchée : payer ses PV (s'il en reste assez après le coût de la fetchland) ou l'avoir engagée.
            if (!$tapped && $card->untappedLifeCost > 0 && $p->life - $ability->lifeCost >= $card->untappedLifeCost) {
                $out[] = ['library:' . $card->id, 'life'];
            }
            $out[] = ['library:' . $card->id];
        }

        return $out === [] ? [['library:none']] : $out;
    }
}
