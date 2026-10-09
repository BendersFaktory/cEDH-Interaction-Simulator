<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ancre : snapshot complet du GameState, point de départ d'un rejeu déterministe (StateReconstructor).
 * Une ancre par tour (SparseCheckpoints), une à chaque décision humaine (avec `decision`) et une au point
 * de bifurcation d'un fork ; les checkpoints intermédiaires (PriorityGranted checkpoint=true) sont reconstruits.
 */
#[ORM\Entity]
#[ORM\Table(name: 'checkpoint')]
#[ORM\UniqueConstraint(name: 'uniq_sim_checkpoint', columns: ['simulation_id', 'event_seq'])]
class Checkpoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Simulation::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Simulation $simulation,
        #[ORM\Column]
        private int $eventSeq,
        #[ORM\Column(type: 'json_text')]
        private array $state,
        /** actionId à injecter pour reprendre depuis cette ancre (pause humaine), null sinon. */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $decision = null,
    ) {
    }

    public function getEventSeq(): int
    {
        return $this->eventSeq;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }

    public function getState(): array
    {
        return $this->state;
    }
}
