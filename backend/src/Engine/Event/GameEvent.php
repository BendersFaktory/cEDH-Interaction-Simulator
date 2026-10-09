<?php

declare(strict_types=1);

namespace App\Engine\Event;

/**
 * Un fait immuable qui s'est produit dans la partie.
 * Les payloads portent des valeurs ABSOLUES (ex: life résultant) pour que le reducer front reste trivial.
 * Catalogue complet : docs/EVENTS.md
 */
final class GameEvent
{
    public function __construct(
        public readonly int $seq,
        public readonly string $type,
        public readonly array $payload = [],
        public readonly bool $verbose = false, // bruit (PriorityGranted/Passed) masqué par défaut dans l'UI
    ) {
    }

    public function toArray(): array
    {
        return ['seq' => $this->seq, 'type' => $this->type, 'payload' => $this->payload, 'verbose' => $this->verbose];
    }
}
