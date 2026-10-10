<?php

declare(strict_types=1);

namespace App\Service;

use App\Doctrine\Type\JsonTextType;
use App\Engine\Event\GameEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;

/**
 * Écriture en masse des événements et des checkpoints d'une partie, hors UnitOfWork.
 *
 * Une partie 4 IA, c'est ~5 000 événements et ~900 checkpoints : un INSERT par ligne coûte un aller-retour
 * réseau chacun avec MySQL. On les regroupe en INSERT multi-lignes. À appeler dans la transaction de l'appelant.
 */
class BulkInserter
{
    private const EVENTS_PER_QUERY = 500;
    /** Un checkpoint pèse ~25 Ko : 50 lignes ≈ 1,3 Mo par requête, très loin de max_allowed_packet. */
    private const CHECKPOINTS_PER_QUERY = 50;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param list<GameEvent> $events */
    public function insertEvents(int $simulationId, array $events): void
    {
        $rows = [];
        foreach ($events as $e) {
            $rows[] = [$simulationId, $e->seq, $e->type, $this->json($e->payload), $e->verbose ? 1 : 0];
        }
        $this->insert('game_event', ['simulation_id', 'seq', 'type', 'payload', 'verbose'], $rows, self::EVENTS_PER_QUERY);
    }

    /**
     * @param array<int, array>       $states    eventSeq => état sérialisé
     * @param array<int, string|null> $decisions eventSeq => actionId à rejouer depuis cette ancre
     */
    public function insertCheckpoints(int $simulationId, array $states, array $decisions = []): void
    {
        $rows = [];
        foreach ($states as $seq => $state) {
            $rows[] = [$simulationId, $seq, $this->json($state), $decisions[$seq] ?? null];
        }
        $this->insert('checkpoint', ['simulation_id', 'event_seq', 'state', 'decision'], $rows, self::CHECKPOINTS_PER_QUERY);
    }

    /**
     * Ancre posée à une pause humaine : complète l'ancre existante à ce seq (une ancre de tour peut coïncider
     * avec la pause, contrainte unique), sinon en insère une.
     */
    public function anchorDecision(int $simulationId, int $seq, array $state, string $actionId): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE checkpoint SET decision = ? WHERE simulation_id = ? AND event_seq = ?',
            [$actionId, $simulationId, $seq],
        );
        if ($updated === 0) {
            $this->insertCheckpoints($simulationId, [$seq => $state], [$seq => $actionId]);
        }
    }

    /**
     * @param list<string>     $columns
     * @param list<list<mixed>> $rows
     */
    private function insert(string $table, array $columns, array $rows, int $perQuery): void
    {
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';

        foreach (array_chunk($rows, $perQuery) as $chunk) {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $table,
                implode(', ', $columns),
                implode(',', array_fill(0, count($chunk), $placeholders)),
            );
            $this->connection->executeStatement($sql, array_merge(...$chunk));
        }
    }

    /** Même encodeur que les colonnes `json_text` de Doctrine : un seul format dans la base. */
    private function json(array $value): string
    {
        return Type::getType(JsonTextType::NAME)->convertToDatabaseValue($value, $this->connection->getDatabasePlatform());
    }
}
