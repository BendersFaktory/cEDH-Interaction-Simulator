<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\TriggeredAbility;
use App\Engine\Rules\CombatManager;

/** Enchaîne les étapes et les tours. */
final class TurnManager
{
    public function __construct(
        private readonly CombatManager $combat,
    ) {
    }

    /** Effets d'entrée dans l'étape courante, puis ouverture de la première ronde de priorité. */
    public function beginStep(GameState $s): void
    {
        $s->emit('PhaseChanged', ['turn' => $s->turn, 'phase' => $s->phase->value, 'activePlayer' => $s->activePlayer]);

        if ($s->phase === Phase::Untap) {
            $untapped = $s->player($s->activePlayer)->untapAll();
            if ($untapped !== []) {
                $s->emit('PermanentsUntapped', ['player' => $s->activePlayer, 'cards' => $untapped]);
            }
        }

        // Triggers « au début de votre entretien » : mis sur la pile avant la première priorité de l'étape.
        if ($s->phase === Phase::Upkeep) {
            $s->queuePlayerTriggers($s->activePlayer, TriggeredAbility::UPKEEP);
            $s->fireDelayedTriggers(TriggeredAbility::NEXT_UPKEEP); // Pact of Negation
        }
        // Triggers retardés « au début de votre prochaine phase principale » (Mana Drain), avant la première priorité.
        if ($s->phase->isMain()) {
            $s->fireDelayedTriggers(TriggeredAbility::NEXT_MAIN);
        }
        // Triggers « au début de votre étape de fin » : idem, à l'étape de fin du joueur actif.
        if ($s->phase === Phase::End) {
            $s->queuePlayerTriggers($s->activePlayer, TriggeredAbility::END_STEP);
        }

        $this->combat->beginStep($s);

        if ($s->phase === Phase::Cleanup) {
            foreach ($s->players as $p) {
                foreach ($p->battlefield as $card) {
                    $card->damage = 0;
                    $card->deathtouchDamage = false;
                    // Fin des effets « jusqu'à la fin du tour » (règle 514.2).
                    if ($card->powerBonus !== 0 || $card->toughnessBonus !== 0) {
                        $card->powerBonus = 0;
                        $card->toughnessBonus = 0;
                        $s->emit('CreatureBoosted', ['player' => $p->id, 'card' => $card->id, 'power' => $card->currentPower(), 'toughness' => $card->currentToughness()]);
                    }
                }
            }
        }

        // CR 103.8 : seul le premier joueur d'une partie à 2 saute sa pioche ; en multijoueur, tout le monde pioche dès le tour 1.
        // Bibliothèque vide : le joueur perd à la vérification des SBA qui précède la priorité (CR 704.5b).
        if ($s->phase === Phase::Draw) {
            $s->drawCard($s->activePlayer);
            // Triggers « au début de votre étape de pioche » (après la pioche de l'étape, CR 504.1 puis 504.2).
            $s->queuePlayerTriggers($s->activePlayer, TriggeredAbility::DRAW_STEP);
        }

        if (!$s->phase->hasPriority()) {
            // Untap / Cleanup : pas de ronde de priorité d'office (au cleanup, voir GameEngine : règle 514.3a).
            $s->stepStarted = true;

            return;
        }

        $first = $s->player($s->activePlayer)->lost ? $s->nextAlive($s->activePlayer) : $s->activePlayer;
        $s->priority = new PriorityState($first);
        $s->stepStarted = true;
    }

    /** Règle 500.4 : le mana non dépensé disparaît à la fin de chaque étape (mana restreint compris, compté dans `lost`). */
    public function emptyPools(GameState $s): void
    {
        foreach ($s->players as $p) {
            if (!$p->pool->isEmpty()) {
                $s->emit('ManaPoolEmptied', ['player' => $p->id, 'lost' => $p->pool->all(), 'pool' => []]);
                $p->pool->clear();
            }
        }
    }

    public function endStep(GameState $s): void
    {
        $this->emptyPools($s);

        $next = $s->phase->next();
        while ($next !== null && $this->combat->skips($next, $s)) {
            $next = $next->next();
        }
        if ($next !== null) {
            $s->phase = $next;
            $s->stepStarted = false;

            return;
        }

        // Nouveau tour : joueur suivant (dans le sens du tour) ; il dégage ses permanents à l'étape Untap.
        $s->turn++;
        if ($s->turn > $s->maxTurns) {
            $s->over = true;
            $s->winner = null;
            $s->emit('GameOver', ['winner' => null, 'reason' => 'turn_limit']);

            return;
        }
        $s->activePlayer = $s->nextAlive($s->activePlayer);
        $s->landsPlayed = 0;
        $s->phase = Phase::Untap;
        $s->stepStarted = false;
    }
}
