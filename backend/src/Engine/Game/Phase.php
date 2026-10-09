<?php

declare(strict_types=1);

namespace App\Engine\Game;

/**
 * Version minimale : pioche -> principale -> fin.
 * TODO: Untap, Upkeep, Beginning of combat, Declare attackers/blockers, Damage, Second main.
 */
enum Phase: string
{
    case Draw = 'draw';
    case Main = 'main';
    case End = 'end';

    public function next(): ?self
    {
        return match ($this) {
            self::Draw => self::Main,
            self::Main => self::End,
            self::End => null,
        };
    }
}
