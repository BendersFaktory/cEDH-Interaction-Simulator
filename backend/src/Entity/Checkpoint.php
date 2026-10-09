<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot complet du GameState juste après un PriorityGranted : les seuls points où l'on peut forker.
 * Stratégie actuelle : un checkpoint par octroi de priorité (simple, mais volumineux).
 * TODO: ne garder qu'un checkpoint par tour et re-simuler (le moteur est déterministe) jusqu'au point voulu.
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
    ) {
    }

    public function getEventSeq(): int
    {
        return $this->eventSeq;
    }

    public function getState(): array
    {
        return $this->state;
    }
}
