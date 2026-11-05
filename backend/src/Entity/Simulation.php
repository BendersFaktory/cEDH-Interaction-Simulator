<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Une branche de simulation. Une simulation racine n'a pas de parent ;
 * un fork référence sa parente et le numéro d'événement (checkpoint) où il bifurque.
 *
 * Les événements d'une branche = ceux de ses ancêtres jusqu'à forkEventSeq + les siens.
 */
#[ORM\Entity]
#[ORM\Table(name: 'simulation')]
class Simulation
{
    public const QUEUED = 'queued'; // créée, pas encore jouée par le worker
    public const FAILED = 'failed'; // le worker a échoué (voir error)
    public const RUNNING = 'running';
    public const WAITING = 'waiting'; // suspendue en attente d'une décision humaine
    public const OVER = 'over';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Simulation $parent = null;

    #[ORM\Column(nullable: true)]
    private ?int $forkEventSeq = null;

    #[ORM\Column(length: 16)]
    private string $status = self::RUNNING;

    /** GameState::toArray() courant (dernier état connu de la branche). */
    #[ORM\Column(type: 'json_text')]
    private array $state = [];

    /** DecisionRequest::toArray() quand status = waiting. */
    #[ORM\Column(type: 'json_text', nullable: true)]
    private ?array $pending = null;

    /** Agents par joueur (id => clé) : le worker reconstruit la partie avec GameFactory::create($seed, $controllers). */
    #[ORM\Column(type: 'json_text', nullable: true)]
    private ?array $controllers = null;

    /** Decks chargés par siège (id => `LoadedDeck::toArray()`) ; absent = decks de démo. Hérité par les forks (images). */
    #[ORM\Column(type: 'json_text', nullable: true)]
    private ?array $decks = null;

    /** Tour courant pendant la simulation asynchrone (écrit par UPDATE DBAL hors transaction). */
    #[ORM\Column(nullable: true)]
    private ?int $progress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(#[ORM\Column] private int $seed)
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function getParent(): ?Simulation
    {
        return $this->parent;
    }

    public function setParent(?Simulation $parent): void
    {
        $this->parent = $parent;
    }

    public function getForkEventSeq(): ?int
    {
        return $this->forkEventSeq;
    }

    public function setForkEventSeq(?int $seq): void
    {
        $this->forkEventSeq = $seq;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function setState(array $state): void
    {
        $this->state = $state;
    }

    public function getPending(): ?array
    {
        return $this->pending;
    }

    public function setPending(?array $pending): void
    {
        $this->pending = $pending;
    }

    /** @return array<int, string>|null */
    public function getControllers(): ?array
    {
        return $this->controllers;
    }

    /** @param array<int, string>|null $controllers */
    public function setControllers(?array $controllers): void
    {
        $this->controllers = $controllers;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function getDecks(): ?array
    {
        return $this->decks;
    }

    /** @param array<int, array<string, mixed>>|null $decks */
    public function setDecks(?array $decks): void
    {
        $this->decks = $decks;
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function setProgress(?int $progress): void
    {
        $this->progress = $progress;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
