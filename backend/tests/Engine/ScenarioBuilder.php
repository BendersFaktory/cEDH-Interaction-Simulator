<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PlayerState;
use App\Engine\Game\PriorityState;
use App\Engine\Game\Rng;

/** Construit un état de jeu précis : tour du joueur 0, phase principale, pile vide, priorité au joueur 0. */
final class ScenarioBuilder
{
    /** @var array<int, list<CardInstance>> */
    private array $hands = [];
    /** @var array<int, int> */
    private array $lands = [];

    public function hand(int $player, CardInstance ...$cards): self
    {
        $this->hands[$player] = array_values($cards);

        return $this;
    }

    public function untappedLands(int $player, int $n): self
    {
        $this->lands[$player] = $n;

        return $this;
    }

    private int $life = 20;

    /** PV de départ de tous les joueurs (20 par défaut pour les anciens scénarios, 40 pour Commander). */
    public function life(int $life): self
    {
        $this->life = $life;

        return $this;
    }

    /** @var array<int, CardInstance> */
    private array $commanders = [];
    /** @var array<int, array<string, int>> */
    private array $commanderCasts = [];

    /** Place un commandant en zone de commandement (déjà lancé $casts fois : taxe +2 par lancement). */
    public function commander(int $player, CardInstance $commander, int $casts = 0): self
    {
        $this->commanders[$player] = $commander;
        $this->commanderCasts[$player] = [$commander->id => $casts];

        return $this;
    }

    /** @param array<int, string> $controllers */
    public function build(array $controllers): GameState
    {
        $players = [];
        foreach (array_keys($controllers) as $id) {
            $p = new PlayerState($id, $this->life);
            $p->hand = $this->hands[$id] ?? [];
            if (isset($this->commanders[$id])) {
                $p->command[] = $this->commanders[$id];
                $p->commanderCasts = $this->commanderCasts[$id];
            }
            for ($i = 0; $i < ($this->lands[$id] ?? 0); $i++) {
                $p->battlefield[] = new CardInstance("p{$id}_land$i", 'Land', CardInstance::LAND);
            }
            // Bibliothèque factice pour éviter toute élimination par pioche pendant le test.
            for ($i = 0; $i < 10; $i++) {
                $p->library[] = new CardInstance("p{$id}_lib$i", 'Land', CardInstance::LAND);
            }
            $players[$id] = $p;
        }

        $s = new GameState($players, new Rng(1), $controllers);
        $s->phase = Phase::Main1;
        $s->stepStarted = true;
        $s->priority = new PriorityState(0);

        return $s;
    }

    public static function bolt(string $id): CardInstance
    {
        return new CardInstance($id, 'Lightning Bolt', CardInstance::INSTANT, 1, 'damage', 3);
    }

    public static function commanderCard(string $id, int $cost = 3): CardInstance
    {
        return new CardInstance($id, 'Test Commander', CardInstance::CREATURE, $cost, null, 0, false, true);
    }

    public static function counterspell(string $id): CardInstance
    {
        return new CardInstance($id, 'Counterspell', CardInstance::INSTANT, 2, 'counter');
    }
}
