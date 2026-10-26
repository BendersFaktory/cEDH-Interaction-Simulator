<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\GameState;

/** Met un sort sur la pile : remet la liste des passes à zéro et rend la priorité au lanceur. */
final class CastSpell implements Action
{
    /**
     * `alt` : coût alternatif choisi. Gratuit (commandant, Fierce Guardianship) ; `pitch:<id>` : exil de la carte bleue `<id>`
     * (+ 1 PV pour Force of Will, sans PV pour Force of Negation ; `pitch:<id>,<id>` : deux cartes bleues, Commandeer) ;
     * `return:<id>[,<id>]` : renvoi en main des Îles `<id>` (Daze, Gush). Gratuit aussi pour Mindbreak Trap (trois sorts d'un adversaire).
     */
    public const ALT_FREE = 'free';
    public const ALT_PITCH = 'pitch:';
    public const ALT_RETURN = 'return:';

    /** @param list<string> $targets ex: ['player:1'] ou ['stack:s3'] */
    public function __construct(
        private readonly int $player,
        public readonly string $cardId,
        public readonly array $targets = [],
        public readonly int $x = 0, // valeur choisie pour {X} (0 si le sort n'en a pas)
        public readonly ?string $alt = null, // coût alternatif (ALT_FREE | ALT_PITCH . id | ALT_RETURN . ids) ; null = coût de mana
        public readonly int $mode = 0, // mode choisi d'un sort modal (Pyroblast) ; 0 = sort non modal
        public readonly ?string $sacrifice = null, // créature sacrifiée en coût additionnel (Culling the Weak, Diabolic Intent)
        public readonly bool $kicked = false, // kicker payé en plus du coût (Orim's Chant)
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('cast:%d:%s:%s', $this->player, $this->cardId, implode(',', $this->targets)) . ($this->x > 0 ? ':x=' . $this->x : '')
            . ($this->alt === null ? '' : ':alt=' . $this->alt) . ($this->mode > 0 ? ':mode=' . $this->mode : '')
            . ($this->sacrifice === null ? '' : ':sac=' . $this->sacrifice) . ($this->kicked ? ':kicked' : '');
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
        } elseif ($this->alt !== null && str_starts_with($this->alt, self::ALT_RETURN)) {
            $names = array_map(static function (string $id) use ($player): string {
                $island = $player->findOnBattlefield($id);

                return ($island?->name ?? $id) . ($island?->tapped ? ' engagée' : '');
            }, explode(',', substr($this->alt, strlen(self::ALT_RETURN))));
            $label .= ' (coût alternatif : renvoyer en main ' . implode(', ', $names) . ')';
        } elseif ($this->alt !== null) {
            $pitched = array_map(static fn (string $id) => $player->findHand($id)?->name ?? 'une carte bleue', explode(',', substr($this->alt, strlen(self::ALT_PITCH))));
            $life = $card?->altCost === \App\Engine\Game\CardInstance::ALT_PITCH_BLUE ? '1 PV et ' : '';
            $label .= ' (coût alternatif : ' . $life . 'exil de ' . implode(' et ', $pitched) . ')';
        }
        if ($this->kicked) {
            $label .= ' (avec kicker ' . $card?->kicker . ')';
        }
        if ($this->mode > 0) {
            $label .= ' (mode ' . $this->mode . ' : ' . implode(', ', array_map(static fn (\App\Engine\Card\SpellEffect $e) => $e->effect, $card?->effectsForMode($this->mode) ?? [])) . ')';
        }
        if ($this->sacrifice !== null) {
            $label .= ' (sacrifier ' . ($player->findOnBattlefield($this->sacrifice)?->name ?? $this->sacrifice) . ')';
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

        if (str_contains($t, '>')) {
            [$victim, $new] = explode('>', $t, 2); // Deflecting Swat, Misdirection : objet de pile visé > nouvelle cible
            return self::targetLabel($state, $victim) . ' (nouvelle cible : ' . self::targetLabel($state, $new) . ')';
        }
        if (str_starts_with($t, 'stack:') && str_contains($t, '+')) {
            return 'sorts ' . str_replace('+', ', ', substr($t, 6)); // Mindbreak Trap
        }
        if (str_starts_with($t, 'library:')) {
            $id = substr($t, 8);
            foreach ($state->players as $p) {
                foreach ($p->library as $card) {
                    if ($card->id === $id) {
                        return $card->name . ' (bibliothèque)';
                    }
                }
            }

            return 'aucune carte';
        }

        return str_starts_with($t, 'player:') ? 'joueur ' . substr($t, 7) : 'sort ' . substr($t, 6);
    }

    public function toArray(): array
    {
        return ['type' => 'cast', 'player' => $this->player, 'cardId' => $this->cardId, 'targets' => $this->targets, 'x' => $this->x]
            + ($this->alt === null ? [] : ['alt' => $this->alt]) + ($this->mode > 0 ? ['mode' => $this->mode] : [])
            + ($this->sacrifice === null ? [] : ['sacrifice' => $this->sacrifice]) + ($this->kicked ? ['kicked' => true] : []);
    }
}
