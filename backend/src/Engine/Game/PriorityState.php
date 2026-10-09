<?php

declare(strict_types=1);

namespace App\Engine\Game;

/**
 * L'état EXACT d'une ronde de priorité. C'est ce qui permet de forker
 * au milieu d'une ronde sans que l'IA reprenne dans un contexte faux.
 */
final class PriorityState
{
    /**
     * @param list<int> $passed joueurs ayant passé consécutivement depuis la dernière action
     * @param bool $announced   true si l'événement PriorityGranted a déjà été émis pour $holder
     *                          (évite de le ré-émettre à la reprise d'une simulation suspendue)
     */
    public function __construct(
        public int $holder,
        public array $passed = [],
        public bool $announced = false,
    ) {
    }

    public function toArray(): array
    {
        return ['holder' => $this->holder, 'passed' => $this->passed, 'announced' => $this->announced];
    }

    public static function fromArray(array $d): self
    {
        return new self((int) $d['holder'], array_map('intval', $d['passed']), (bool) $d['announced']);
    }
}
