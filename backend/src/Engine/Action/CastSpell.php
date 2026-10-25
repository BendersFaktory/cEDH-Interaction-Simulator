<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Met un sort sur la pile : remet la liste des passes à zéro et rend la priorité au lanceur. */
final class CastSpell implements Action
{
    /** `alt` : coût alternatif choisi. Gratuit (commandant, Fierce Guardianship) ; `pitch:<id>` : 1 PV et exil de la carte bleue `<id>` (Force of Will). */
    public const ALT_FREE = 'free';
    public const ALT_PITCH = 'pitch:';

    /** @param list<string> $targets ex: ['player:1'] ou ['stack:s3'] */
    public function __construct(
        private readonly int $player,
        public readonly string $cardId,
        public readonly array $targets = [],
        public readonly int $x = 0, // valeur choisie pour {X} (0 si le sort n'en a pas)
        public readonly ?string $alt = null, // coût alternatif (ALT_FREE | ALT_PITCH . id) ; null = coût de mana
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('cast:%d:%s:%s', $this->player, $this->cardId, implode(',', $this->targets)) . ($this->x > 0 ? ':x=' . $this->x : '')
            . ($this->alt === null ? '' : ':alt=' . $this->alt);
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
        if ($this->alt === self::ALT_FREE) {
            $label .= ' (coût alternatif : gratuit)';
        } elseif ($this->alt !== null) {
            $pitched = $player->findHand(substr($this->alt, strlen(self::ALT_PITCH)));
            $label .= ' (coût alternatif : 1 PV et exil de ' . ($pitched?->name ?? 'une carte bleue') . ')';
        }
        if ($this->targets !== []) {
            $label .= ' → ' . implode(', ', array_map(static fn (string $t) => self::targetLabel($state, $t), $this->targets));
        }

        return $label;
    }

    private static function targetLabel(GameState $state, string $t): string
    {
        if (str_starts_with($t, 'permanent:')) {
            $id = substr($t, 10);
            foreach ($state->players as $p) {
                if (($card = $p->findOnBattlefield($id)) !== null) {
                    return sprintf('%s (joueur %d)', $card->name, $p->id);
                }
            }

            return 'permanent ' . $id;
        }

        return str_starts_with($t, 'player:') ? 'joueur ' . substr($t, 7) : 'sort ' . substr($t, 6);
    }

    public function toArray(): array
    {
        return ['type' => 'cast', 'player' => $this->player, 'cardId' => $this->cardId, 'targets' => $this->targets, 'x' => $this->x]
            + ($this->alt === null ? [] : ['alt' => $this->alt]);
    }
}
