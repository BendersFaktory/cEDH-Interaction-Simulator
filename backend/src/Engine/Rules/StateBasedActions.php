<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\KeepLegend;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Agent\AutoPassAgent;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\PlayerState;
use App\Engine\Game\PriorityState;

/**
 * Actions basées sur l'état (CR 704), vérifiées avant chaque octroi de priorité et au cleanup. Un passage relève tout ce
 * qui doit être fait sur l'état courant puis l'applique ; les passages se répètent tant qu'une SBA a agi (CR 704.3).
 *
 * Dans un passage :
 * - commandant au cimetière depuis la dernière vérification : retour en zone de commandement (CR 903.9a, toujours
 *   appliqué) ; un commandant qui meurt dans ce passage revient donc au passage suivant, après que ses triggers « meurt »
 *   ont été mis en file ;
 * - joueur à 0 PV ou moins (704.5a), qui a pioché dans une bibliothèque vide (704.5b), ou à 21 dégâts d'un même
 *   commandant (704.6c) : éliminé, et ses objets quittent la partie (800.4a, voir eliminate()) ;
 * - créature d'endurance nulle ou négative (704.5f), létalement blessée (704.5g) ou blessée par contact mortel (704.5h) :
 *   au cimetière ;
 * - règle des légendes (704.5j) : appliquée après les morts du même passage (approximation : une légende qui meurt
 *   n'est plus proposée au choix). Choix du contrôleur, décision hors priorité comme la défausse : il occupe
 *   `priority->holder`, la priorité d'avant est gardée dans `GameState::$sbaResume`. À la reprise, le passage reprend
 *   directement à la règle des légendes (le reste a déjà été appliqué).
 *
 * Un jeton hors du champ de bataille cesse d'exister (704.5d) : `GameState::sendToGraveyard()` ne l'envoie nulle part.
 */
final class StateBasedActions
{
    private const MAX_PASSES = 100;

    public function __construct(
        private readonly ?AgentRegistry $agents = null,
        private readonly LegalActionGenerator $legal = new LegalActionGenerator(),
    ) {
    }

    /** Peut lever DecisionRequired (règle des légendes ; état intact, repris via $injected). */
    public function check(GameState $s, ?Action &$injected = null): void
    {
        for ($i = 0; $i < self::MAX_PASSES && !$s->over; $i++) {
            $acted = $s->sbaResume === null && $this->simultaneous($s);
            $acted = $this->legendRule($s, $injected) || $acted;

            $alive = $s->alivePlayerIds();
            if (count($alive) <= 1) {
                $s->over = true;
                $s->winner = $alive[0] ?? null;
                $s->emit('GameOver', ['winner' => $s->winner, 'reason' => 'last_player_standing']);
            }
            if (!$acted) {
                return;
            }
        }
        if (!$s->over) {
            throw new \LogicException('Actions basées sur l\'état sans fin (garde-fou).');
        }
    }

    /** Commandants, joueurs, créatures : relevés sur l'état d'avant le passage, puis appliqués. @return bool true si une SBA a agi */
    private function simultaneous(GameState $s): bool
    {
        $commanders = [];
        $losers = [];
        $dying = [];
        foreach ($s->players as $p) {
            if ($p->lost) {
                continue;
            }
            foreach ($p->graveyard as $card) {
                if ($card->commander) {
                    $commanders[] = [$p, $card];
                }
            }
            if ($p->life <= 0) {
                $losers[$p->id] = 'life';
            } elseif ($p->drewFromEmpty) {
                $losers[$p->id] = 'decked';
            } elseif ($p->commanderDamage !== [] && max($p->commanderDamage) >= PlayerState::COMMANDER_DAMAGE_LIMIT) {
                $losers[$p->id] = 'commander_damage';
            }
            foreach ($p->battlefield as $card) {
                if (($reason = self::deathReason($card)) !== null) {
                    $dying[] = [$p, $card, $reason];
                }
            }
        }

        foreach ($commanders as [$p, $card]) {
            $p->graveyard = array_values(array_filter($p->graveyard, static fn (CardInstance $c) => $c !== $card));
            $p->command[] = $card;
            $s->emit('CommanderReturned', ['player' => $p->id, 'card' => $card->toPublic(), 'from' => 'graveyard']);
        }
        foreach ($losers as $id => $reason) {
            $this->eliminate($s, $id, $reason);
        }
        foreach ($dying as [$p, $card, $reason]) {
            if ($p->lost || $p->takeFromBattlefield($card->id) === null) {
                continue; // a quitté la partie avec son propriétaire
            }
            $card->damage = 0;
            $card->deathtouchDamage = false;
            $card->tapped = false;
            $destination = $s->sendToGraveyard($p->id, $card);
            $s->emit('CreatureDied', ['player' => $p->id, 'card' => $card->toPublic(), 'destination' => $destination, 'reason' => $reason]);
            $s->queueTriggers($p->id, $card, TriggeredAbility::DIES);
        }

        return $commanders !== [] || $losers !== [] || $dying !== [];
    }

    /** Raison pour laquelle une créature meurt maintenant (`toughness` | `damage` | `deathtouch`), ou null. */
    private static function deathReason(CardInstance $card): ?string
    {
        if (!$card->isCreature()) {
            return null;
        }
        if ($card->currentToughness() <= 0) {
            return 'toughness';
        }
        if ($card->damage > 0 && $card->damage >= $card->currentToughness()) {
            return 'damage';
        }

        return $card->damage > 0 && $card->deathtouchDamage ? 'deathtouch' : null;
    }

    /**
     * Règle des légendes, joueur par joueur dans l'ordre APNAP : tant qu'un joueur contrôle deux permanents légendaires
     * de même nom, il en garde un (KeepLegend) et les autres vont au cimetière. @return bool true si elle a agi
     */
    private function legendRule(GameState $s, ?Action &$injected): bool
    {
        $acted = false;
        $ids = array_keys($s->players);
        $start = (int) array_search($s->activePlayer, $ids, true);
        foreach ($ids as $k => $_) {
            $p = $s->player($ids[($start + $k) % count($ids)]);
            while (!$p->lost && self::legendGroup($p) !== null) {
                $s->sbaResume ??= $s->priority;
                $s->priority = new PriorityState($p->id, [], true);
                $legal = $this->legal->for($s, $p->id);
                if ($injected !== null) {
                    $action = $this->legal->match($injected, $p->id, $legal);
                    $injected = null;
                } else {
                    $agent = $this->agents?->get($s->controllers[$p->id]) ?? new AutoPassAgent();
                    $action = $agent->decide($s, $p->id, $legal);
                }
                if (!$action instanceof KeepLegend) {
                    throw new \LogicException('Un permanent légendaire doit être gardé : ' . $action::class);
                }
                $this->keepLegend($s, $p, $action->cardId);
                $acted = true;
            }
        }
        if ($s->sbaResume !== null) {
            $s->priority = $s->sbaResume;
            $s->sbaResume = null;
        }

        return $acted;
    }

    /**
     * Premier groupe (ordre du champ de bataille) d'au moins deux permanents légendaires de même nom contrôlés par le
     * joueur, ou null.
     *
     * @return list<CardInstance>|null
     */
    public static function legendGroup(PlayerState $p): ?array
    {
        $byName = [];
        foreach ($p->battlefield as $card) {
            if ($card->isLegendary()) {
                $byName[$card->name][] = $card;
            }
        }
        foreach ($byName as $cards) {
            if (count($cards) >= 2) {
                return $cards;
            }
        }

        return null;
    }

    private function keepLegend(GameState $s, PlayerState $p, string $keptId): void
    {
        $group = self::legendGroup($p) ?? [];
        $removed = [];
        foreach ($group as $card) {
            if ($card->id === $keptId) {
                continue;
            }
            $p->takeFromBattlefield($card->id);
            $card->damage = 0;
            $card->deathtouchDamage = false;
            $card->tapped = false;
            $removed[] = ['card' => $card->toPublic(), 'destination' => $s->sendToGraveyard($p->id, $card)];
            if ($card->isCreature()) {
                $s->queueTriggers($p->id, $card, TriggeredAbility::DIES);
            }
        }
        $s->emit('LegendRuleApplied', ['player' => $p->id, 'kept' => $keptId, 'removed' => $removed]);
    }

    /**
     * Élimine un joueur, puis ses objets quittent la partie (CR 800.4a) : ses permanents (le contrôle ne change jamais de
     * main ici : propriétaire = contrôleur) et ses objets sur la pile disparaissent sans passer par le cimetière ; ses
     * triggers en attente sont retirés par TriggerManager. Le combat n'est pas retouché : CombatResolver ignore les
     * créatures qui ne sont plus sur le champ de bataille et ne blesse pas un joueur éliminé.
     */
    public function eliminate(GameState $s, int $playerId, string $reason): void
    {
        $p = $s->player($playerId);
        $p->lost = true;
        $p->drewFromEmpty = false;
        $s->emit('PlayerLost', ['player' => $playerId, 'reason' => $reason]);

        $battlefield = array_map(static fn (CardInstance $c) => $c->id, $p->battlefield);
        $p->battlefield = [];
        $stack = [];
        foreach ($s->stack as $k => $item) {
            if ($item->controller === $playerId) {
                $stack[] = $item->id;
                unset($s->stack[$k]);
            }
        }
        $s->stack = array_values($s->stack);
        if ($battlefield !== [] || $stack !== []) {
            $s->emit('PlayerLeftGame', ['player' => $playerId, 'battlefield' => $battlefield, 'stack' => $stack]);
        }
    }
}
