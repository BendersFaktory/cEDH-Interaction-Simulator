<?php

declare(strict_types=1);

namespace App\Tests\Engine;

use App\Engine\Game\GameFactory;
use App\Engine\Game\GameState;
use PHPUnit\Framework\TestCase;

/** Version du format de `GameState::toArray()` (garde-fou des ancres de checkpoints). */
final class StateVersionTest extends TestCase
{
    private const FOUR_AI = [0 => 'ai:heuristic', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];

    public function testStateCarriesItsVersion(): void
    {
        $d = GameFactory::create(42, self::FOUR_AI)->toArray();
        $this->assertSame(GameState::FORMAT_VERSION, $d['version']);
    }

    public function testStateWithoutVersionIsReadAsLegacy(): void
    {
        $d = GameFactory::create(42, self::FOUR_AI)->toArray();
        unset($d['version']);
        $this->assertSame(GameState::FORMAT_VERSION, GameState::fromArray($d)->toArray()['version']);
    }

    public function testNewerFormatIsRefused(): void
    {
        $d = GameFactory::create(42, self::FOUR_AI)->toArray();
        $d['version'] = GameState::FORMAT_VERSION + 1;
        $this->expectException(\DomainException::class);
        GameState::fromArray($d);
    }
}
