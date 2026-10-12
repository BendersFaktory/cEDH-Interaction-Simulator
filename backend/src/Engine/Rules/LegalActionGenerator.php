<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\AbilityManager;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\Discard;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PlayLand;
use App\Engine\Action\PutTrigger;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\CardInstance;
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
        if ($s->triggerResumeHolder !== null) {
            return $this->triggerActions($s, $playerId);
        }

        if ($s->combat->declaring !== null) {
            return $this->declarationActions($s, $playerId);
        }

        if ($s->phase === Phase::Cleanup && $playerId === $s->activePlayer
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
                    $actions[] = new PlayLand($playerId, $card->id);
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
                $targetSets = $ability->effect === 'damage'
                    ? array_map(static fn (int $id) => ['player:' . $id], $s->alivePlayerIds())
                    : [[]];
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
     * Pendant une déclaration (attaquants ou bloqueurs) : un choix par action, puis FinishDeclaration.
     * Seul le déclarant courant a des choix ; la liste est recalculée après chaque déclaration.
     *
     * @return list<Action>
     */
    private function declarationActions(GameState $s, int $playerId): array
    {
        $choices = $s->combat->declaring === 'attackers' ? $this->attackActions($s, $playerId) : $this->blockActions($s, $playerId);

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
            if (!$card->isCreature() || $card->tapped || $card->enteredTurn >= $s->turn || isset($s->combat->attackers[$card->id])) {
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
        foreach ($s->player($playerId)->battlefield as $card) {
            if (!$card->isCreature() || $card->tapped || $s->combat->isBlocking($card->id)) {
                continue;
            }
            foreach ($attackers as $attackerId) {
                $actions[] = new DeclareBlocker($playerId, $card->id, $attackerId);
            }
        }

        return $actions;
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
        return match ($card->effect) {
            'damage' => array_map(static fn (int $id) => ['player:' . $id], $s->alivePlayerIds()),
            // Un contresort ne cible que des sorts, pas des capacités activées.
            'counter' => array_map(static fn ($item) => ['stack:' . $item->id], array_values(array_filter($s->stack, static fn ($item) => !$item->isAbility()))),
            default => [[]],
        };
    }
}
