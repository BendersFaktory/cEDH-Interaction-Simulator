<?php

declare(strict_types=1);

namespace App\Engine\Action;

use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;

/**
 * Décision au milieu d'une résolution (étape 14, lot H5b : `GameState::$selection`, voir `StackResolver::choose()`). `choice` :
 * - Tainted Pact (`tainted_pact`) : `take` (mettre la carte exilée en main) ou `continue` (exiler la suivante) ;
 * - Ad Nauseam (`ad_nauseam`) : `continue` (révéler la suivante) ou `stop` ;
 * - Lim-Dûl's Vault (`vault`) : `continue` (payer 1 PV, mettre les cartes regardées dessous, regarder les suivantes) ou
 *   `top:<id>` (garder les cartes regardées, celle-ci sur le dessus) ;
 * - Thassa's Oracle (`oracle`) : `top:<id>` (cette carte sur le dessus, les autres dessous) ou `none` (toutes dessous) ;
 * - Scheming Symmetry (`symmetry`) : `card:<id>` (la carte cherchée, mise sur le dessus) ou `none` (rien trouvé) ;
 * - Beseech the Mirror (`beseech`) : `cast` (la lancer sans payer son coût) ou `hand` (la mettre en main).
 */
final class ResolutionChoice implements Action
{
    public function __construct(
        private readonly int $player,
        public readonly string $choice,
    ) {
    }

    public function player(): int
    {
        return $this->player;
    }

    public function id(): string
    {
        return sprintf('choice:%d:%s', $this->player, $this->choice);
    }

    public function describe(GameState $state): string
    {
        $sel = $state->selection ?? [];
        $p = $state->player($this->player);
        $name = static function (string $id, array $cards): string {
            foreach ($cards as $card) {
                if ($card->id === $id) {
                    return $card->name;
                }
            }

            return $id;
        };
        $kind = $sel['kind'] ?? '';
        $exiled = $name((string) ($sel['cardId'] ?? ''), $p->exile);

        return match (true) {
            $kind === 'tainted_pact' && $this->choice === 'take' => "Mettre $exiled en main",
            $kind === 'tainted_pact' => "Ne pas prendre $exiled : exiler la carte suivante",
            $kind === 'ad_nauseam' && $this->choice === 'continue' => 'Révéler la carte suivante (perdre autant de PV que sa valeur de mana)',
            $kind === 'ad_nauseam' => 'S\'arrêter',
            $kind === 'vault' && $this->choice === 'continue' => 'Payer 1 PV : mettre ces cartes dessous et regarder les suivantes',
            $kind === 'vault' => 'Garder ces cartes, ' . $name(substr($this->choice, 4), $p->library) . ' sur le dessus',
            $kind === 'oracle' && $this->choice === 'none' => 'Tout mettre sous la bibliothèque',
            $kind === 'oracle' => 'Garder ' . $name(substr($this->choice, 4), $p->library) . ' sur le dessus, le reste dessous',
            $kind === 'symmetry' && $this->choice === 'none' => 'Ne rien trouver',
            $kind === 'symmetry' => 'Mettre ' . $name(substr($this->choice, 5), $p->library) . ' sur le dessus de sa bibliothèque',
            $kind === 'beseech' && $this->choice === 'cast' => "Lancer $exiled sans payer son coût de mana",
            $kind === 'beseech' => "Mettre $exiled en main",
            default => $this->choice,
        };
    }

    /** La carte de la bibliothèque désignée par `top:<id>` / `card:<id>`, ou null. */
    public function cardId(): ?string
    {
        foreach (['top:', 'card:'] as $prefix) {
            if (str_starts_with($this->choice, $prefix)) {
                return substr($this->choice, strlen($prefix));
            }
        }

        return null;
    }

    /** @param list<CardInstance> $cards */
    public static function find(array $cards, ?string $id): ?CardInstance
    {
        foreach ($cards as $card) {
            if ($card->id === $id) {
                return $card;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return ['type' => 'choice', 'player' => $this->player, 'choice' => $this->choice];
    }
}
