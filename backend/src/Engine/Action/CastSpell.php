<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Met un sort sur la pile : remet la liste des passes à zéro et rend la priorité au lanceur. */
final class CastSpell implements Action
{
    /** @param list<string> $targets ex: ['player:1'] ou ['stack:s3'] */
    public function __construct(
        private readonly int $player,
        public readonly string $cardId,
        public readonly array $targets = [],
        public readonly int $x = 0, // valeur choisie pour {X} (0 si le sort n'en a pas)
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('cast:%d:%s:%s', $this->player, $this->cardId, implode(',', $this->targets)) . ($this->x > 0 ? ':x=' . $this->x : '');
    }

    public function describe(GameState $state): string
    {
        $player = $state->player($this->player);
        $card = $player->findCastable($this->cardId);
        $label = 'Lancer ' . ($card?->name ?? $this->cardId);
        if ($card?->commander) {
            $label .= sprintf(' (commandant, coût %s)', $player->castCost($card));
        }
        if ($this->x > 0) {
            $label .= sprintf(' (X=%d)', $this->x);
        }
        if ($this->targets !== []) {
            $label .= ' → ' . implode(', ', array_map(self::targetLabel(...), $this->targets));
        }

        return $label;
    }

    private static function targetLabel(string $t): string
    {
        return str_starts_with($t, 'player:') ? 'joueur ' . substr($t, 7) : 'sort ' . substr($t, 6);
    }

    public function toArray(): array
    {
        return ['type' => 'cast', 'player' => $this->player, 'cardId' => $this->cardId, 'targets' => $this->targets, 'x' => $this->x];
    }
}
