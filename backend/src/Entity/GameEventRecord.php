<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Un GameEvent persisté. Le numéro `seq` est unique le long d'une chaîne parent -> fork. */
#[ORM\Entity]
#[ORM\Table(name: 'game_event')]
#[ORM\UniqueConstraint(name: 'uniq_sim_seq', columns: ['simulation_id', 'seq'])]
class GameEventRecord
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
        private int $seq,
        #[ORM\Column(length: 48)]
        private string $type,
        #[ORM\Column(type: 'json')]
        private array $payload,
        #[ORM\Column]
        private bool $verbose,
    ) {
    }

    public function toArray(): array
    {
        return ['seq' => $this->seq, 'type' => $this->type, 'payload' => $this->payload, 'verbose' => $this->verbose];
    }
}
