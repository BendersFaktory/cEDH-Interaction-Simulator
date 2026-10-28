<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Card\Decks;

final class GameFactory
{
    /**
     * Crée une partie Commander (40 PV, commandant(s) en zone de commandement, main de 7).
     * L'avant-partie (mulligan de Londres) commence ici : `mulligan` est initialisé, MulliganManager la mène au premier advance().
     * TODO: pioche de départ configurable, ordre des sièges aléatoire.
     *
     * @param array<int, string> $controllers id joueur => clé d'agent.
     *        Exemple « tu joues J0 contre 3 IA » : [0 => 'human:auto', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic']
     * @param (callable(int): array{commander: CardInstance, partner?: ?CardInstance, library: list<CardInstance>})|null $deckBuilder
     */
    public static function create(int $seed, array $controllers, ?callable $deckBuilder = null, int $startingLife = 40): GameState
    {
        if (count($controllers) < 2) {
            throw new \InvalidArgumentException('Il faut au moins 2 joueurs.');
        }

        $deckBuilder ??= [Decks::class, 'commanderDemo'];
        $rng = new Rng($seed);

        $players = [];
        foreach (array_keys($controllers) as $id) {
            $deck = $deckBuilder($id);
            Decks::validate($deck, $id);
            $player = new PlayerState($id, $startingLife, $rng->shuffle($deck['library']));
            $player->command = Decks::commanders($deck); // un partenaire (Partner) rejoint aussi la zone de commandement
            $players[$id] = $player;
        }

        $s = new GameState($players, $rng, $controllers);
        // CR 103.1 : le premier joueur est tiré au sort (les sièges et l'ordre du tour restent fixes).
        $ids = array_keys($s->players);
        $s->activePlayer = $ids[$rng->int(0, count($ids) - 1)];
        $s->priority = new PriorityState($s->activePlayer);
        $s->emit('GameStarted', [
            'seed' => $seed,
            'format' => 'commander',
            'firstPlayer' => $s->activePlayer,
            'players' => array_map(static fn (PlayerState $p) => [
                'id' => $p->id,
                'life' => $p->life,
                'library' => count($p->library),
                'controller' => $controllers[$p->id],
                'command' => array_map(static fn (CardInstance $c) => $c->toPublic(), $p->command),
            ], array_values($s->players)),
        ]);

        foreach (array_keys($s->players) as $id) {
            for ($i = 0; $i < 7; $i++) {
                $s->drawCard($id, setup: true);
            }
        }
        $s->mulligan = array_map(static fn () => ['taken' => 0, 'kept' => false], $s->players);

        return $s;
    }
}
