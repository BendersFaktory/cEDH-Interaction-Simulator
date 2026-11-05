<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Simulation;
use App\Message\RunSimulation;
use App\Service\SimulationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunSimulationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SimulationService $simulations,
    ) {
    }

    public function __invoke(RunSimulation $message): void
    {
        $sim = $this->em->find(Simulation::class, $message->simulationId);
        if ($sim === null || $sim->getStatus() !== Simulation::QUEUED) {
            return; // supprimée ou déjà prise en charge
        }
        // queued → running, visible par GET pendant le calcul ; conditionnel, pour qu'un seul worker la prenne.
        // (L'entité chargée reste QUEUED en mémoire : start() s'en sert pour brancher la progression.)
        $taken = $this->em->getConnection()->executeStatement(
            'UPDATE simulation SET status = ? WHERE id = ? AND status = ?',
            [Simulation::RUNNING, $message->simulationId, Simulation::QUEUED],
        );
        if ($taken === 0) {
            return;
        }

        try {
            $this->simulations->start($sim);
        } catch (\Throwable $e) {
            // Pas de relance : on enregistre l'échec par UPDATE direct (l'EM peut être fermé par la transaction avortée).
            $this->em->getConnection()->executeStatement(
                'UPDATE simulation SET status = ?, error = ? WHERE id = ?',
                [Simulation::FAILED, mb_substr($e::class . ' : ' . $e->getMessage(), 0, 2000), $message->simulationId],
            );
        }
    }
}
