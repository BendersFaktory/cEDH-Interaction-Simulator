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
    #[ORM\Column(type: 'json')]
    private array $state = [];

    /** DecisionRequest::toArray() quand status = waiting. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pending = null;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
