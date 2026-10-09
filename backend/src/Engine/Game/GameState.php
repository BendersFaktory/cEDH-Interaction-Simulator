<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Event\GameEvent;

/**
 * Source de vérité unique d'une partie. 100 % sérialisable (toArray/fromArray),
 * aucun état caché ailleurs : c'est la condition pour que snapshot + fork fonctionnent.
 */
final class GameState
{
    public int $turn = 1;
    public int $activePlayer;
    public Phase $phase = Phase::Untap;
    public bool $stepStarted = false;
    public PriorityState $priority;
    /** @var list<StackItem> dernier élément = dessus de la pile */
    public array $stack = [];
    public int $landsPlayed = 0;
    public int $nextStackId = 1;
    public int $eventSeq = 0;
    public bool $over = false;
    public ?int $winner = null;

    /** @var list<GameEvent> tampon vidé par drainEvents(), jamais sérialisé */
    private array $pendingEvents = [];

    /**
     * @param array<int, PlayerState> $players     indexés par id, ordre croissant = ordre du tour
     * @param array<int, string>      $controllers  id joueur => clé d'agent ('ai:heuristic', 'human', ...)
     */
    public function __construct(
        public array $players,
        public Rng $rng,
        public array $controllers,
        public int $maxTurns = 400, // tours cumulés de tous les joueurs
    ) {
        ksort($this->players);
        $this->activePlayer = (int) array_key_first($this->players);
        $this->priority = new PriorityState($this->activePlayer);
    }

    public function player(int $id): PlayerState
    {
        return $this->players[$id];
    }

    /** @return list<int> ids des joueurs encore en jeu, dans l'ordre du tour */
    public function alivePlayerIds(): array
    {
        $ids = [];
        foreach ($this->players as $id => $p) {
            if (!$p->lost) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Prochain joueur en vie après $from, dans le sens du tour. */
    public function nextAlive(int $from): int
    {
        $ids = array_keys($this->players);
        $n = count($ids);
        $start = (int) array_search($from, $ids, true);
        for ($i = 1; $i <= $n; $i++) {
            $id = $ids[($start + $i) % $n];
            if (!$this->players[$id]->lost) {
                return $id;
            }
        }

        return $from;
    }

    public function topOfStack(): ?StackItem
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    public function findStackItem(string $id): ?StackItem
    {
        foreach ($this->stack as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public function emit(string $type, array $payload = [], bool $verbose = false): GameEvent
    {
        $event = new GameEvent(++$this->eventSeq, $type, $payload, $verbose);
        $this->pendingEvents[] = $event;

        return $event;
    }

    /** @return list<GameEvent> */
    public function drainEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    /** Pioche une carte et journalise. Retourne false si la bibliothèque est vide. */
    public function drawCard(int $playerId): bool
    {
        $p = $this->player($playerId);
        $card = array_shift($p->library);
        if ($card === null) {
            return false;
        }
        $p->hand[] = $card;
        $this->emit('CardDrawn', ['player' => $playerId, 'card' => $card->toPublic(), 'library' => count($p->library)]);

        return true;
    }

    /**
     * Envoie une carte au cimetière de son propriétaire, SAUF un commandant qui retourne en zone de commandement
     * (règle de remplacement de Commander). Retourne la destination réelle : 'graveyard' | 'command'.
     */
    public function sendToGraveyard(int $ownerId, CardInstance $card): string
    {
        if ($card->commander) {
            $this->player($ownerId)->command[] = $card;

            return 'command';
        }
        $this->player($ownerId)->graveyard[] = $card;

        return 'graveyard';
    }

    public function toArray(): array
    {
        return [
            'turn' => $this->turn,
            'activePlayer' => $this->activePlayer,
            'phase' => $this->phase->value,
            'stepStarted' => $this->stepStarted,
            'priority' => $this->priority->toArray(),
            'stack' => array_map(static fn (StackItem $i) => $i->toArray(), $this->stack),
            'landsPlayed' => $this->landsPlayed,
            'nextStackId' => $this->nextStackId,
            'eventSeq' => $this->eventSeq,
            'over' => $this->over,
            'winner' => $this->winner,
            'maxTurns' => $this->maxTurns,
            'rng' => $this->rng->state(),
            'controllers' => $this->controllers,
            'players' => array_map(static fn (PlayerState $p) => $p->toArray(), $this->players),
        ];
    }

    public static function fromArray(array $d): self
    {
        $players = [];
        foreach ($d['players'] as $id => $p) {
            $players[(int) $id] = PlayerState::fromArray($p);
        }
        $controllers = [];
        foreach ($d['controllers'] as $id => $key) {
            $controllers[(int) $id] = $key;
        }

        $s = new self($players, Rng::fromState((int) $d['rng']), $controllers, (int) $d['maxTurns']);
        $s->turn = (int) $d['turn'];
        $s->activePlayer = (int) $d['activePlayer'];
        $s->phase = Phase::from($d['phase']);
        $s->stepStarted = (bool) $d['stepStarted'];
        $s->priority = PriorityState::fromArray($d['priority']);
        $s->stack = array_map([StackItem::class, 'fromArray'], $d['stack']);
        $s->landsPlayed = (int) $d['landsPlayed'];
        $s->nextStackId = (int) $d['nextStackId'];
        $s->eventSeq = (int) $d['eventSeq'];
        $s->over = (bool) $d['over'];
        $s->winner = $d['winner'] === null ? null : (int) $d['winner'];

        return $s;
    }
}
