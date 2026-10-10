<?php

declare(strict_types=1);

namespace App\Engine\Game;

final class StackItem
{
    /**
     * @param list<string> $targets ex: ['player:1'] ou ['stack:s3']
     */
    public function __construct(
        public readonly string $id,
        public readonly CardInstance $card,
        public readonly int $controller,
        public readonly array $targets = [],
        public readonly int $x = 0, // valeur choisie pour {X}
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'card' => $this->card->toArray(),
            'controller' => $this->controller,
            'targets' => $this->targets,
            'x' => $this->x,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self($d['id'], CardInstance::fromArray($d['card']), (int) $d['controller'], $d['targets'], (int) ($d['x'] ?? 0));
    }
}
