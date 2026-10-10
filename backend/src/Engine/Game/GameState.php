<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
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
    public int $nextTokenId = 1;
    public int $eventSeq = 0;
    public bool $over = false;
    public ?int $winner = null;
    public CombatState $combat;

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
        $this->combat = new CombatState();
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
     * (règle de remplacement de Commander) et un jeton qui cesse d'exister (règle 111.7).
     * Retourne la destination réelle : 'graveyard' | 'command' | 'none'.
     */
    public function sendToGraveyard(int $ownerId, CardInstance $card): string
    {
        if ($card->token) {
            return 'none';
        }
        if ($card->commander) {
            $this->player($ownerId)->command[] = $card;

            return 'command';
        }
        $this->player($ownerId)->graveyard[] = $card;

        return 'graveyard';
    }

    /**
     * Sacrifie des permanents du joueur (coût déjà payé : ex. Treasure engagé par `payWithPlan`) : champ de bataille ->
     * cimetière via `sendToGraveyard()`, un événement `PermanentSacrificed` par permanent. Seul chemin du sacrifice.
     *
     * @param list<string> $cardIds
     */
    public function sacrifice(int $playerId, array $cardIds): void
    {
        $p = $this->player($playerId);
        foreach ($cardIds as $id) {
            $card = $p->takeFromBattlefield($id) ?? throw new \DomainException("Permanent à sacrifier introuvable : $id");
            $card->tapped = false;
            $destination = $this->sendToGraveyard($playerId, $card);
            $this->emit('PermanentSacrificed', ['player' => $playerId, 'card' => $card->toPublic(), 'destination' => $destination]);
        }
    }

    /** Crée un jeton Treasure sur le champ de bataille du joueur (`TokenCreated`). */
    public function createTreasure(int $playerId): CardInstance
    {
        return $this->createToken($playerId, 'Treasure', CardInstance::ARTIFACT, abilities: [ActivatedAbility::treasure()]);
    }

    /**
     * Crée un jeton sur le champ de bataille du joueur (id `p<joueur>_t<n>`, déterministe) et journalise `TokenCreated`.
     *
     * @param list<ActivatedAbility> $abilities
     */
    public function createToken(int $playerId, string $name, string $kind, int $power = 0, int $toughness = 0, array $abilities = []): CardInstance
    {
        $token = new CardInstance(
            sprintf('p%d_t%d', $playerId, $this->nextTokenId++), $name, $kind,
            power: $power, toughness: $toughness, abilities: $abilities, token: true,
        );
        $token->enteredTurn = $this->turn;
        $this->player($playerId)->battlefield[] = $token;
        $this->emit('TokenCreated', ['player' => $playerId, 'card' => $token->toPublic()]);

        return $token;
    }

    /** Défausse une carte de la main (cimetière via `sendToGraveyard()`) et journalise `CardDiscarded`. Seul chemin de la défausse. */
    public function discard(int $playerId, string $cardId): void
    {
        $p = $this->player($playerId);
        $card = $p->takeFromHand($cardId) ?? throw new \DomainException("Carte à défausser absente de la main : $cardId");
        $destination = $this->sendToGraveyard($playerId, $card);
        $this->emit('CardDiscarded', ['player' => $playerId, 'card' => $card->toPublic(), 'destination' => $destination, 'hand' => count($p->hand)]);
    }

    public function toArray(): array
    {
        return [
            'turn' => $this->turn,
            'activePlayer' => $this->activePlayer,
            'phase' => $this->phase->value,
            'stepStarted' => $this->stepStarted,
            'priority' => $this->priority->toArray(),
            'combat' => $this->combat->toArray(),
            'stack' => array_map(static fn (StackItem $i) => $i->toArray(), $this->stack),
            'landsPlayed' => $this->landsPlayed,
            'nextStackId' => $this->nextStackId,
            'nextTokenId' => $this->nextTokenId,
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
        $s->combat = CombatState::fromArray($d['combat'] ?? []);
        $s->stack = array_map([StackItem::class, 'fromArray'], $d['stack']);
        $s->landsPlayed = (int) $d['landsPlayed'];
        $s->nextStackId = (int) $d['nextStackId'];
        $s->nextTokenId = (int) ($d['nextTokenId'] ?? 1);
        $s->eventSeq = (int) $d['eventSeq'];
        $s->over = (bool) $d['over'];
        $s->winner = $d['winner'] === null ? null : (int) $d['winner'];

        return $s;
    }
}
