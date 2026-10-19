<?php

declare(strict_types=1);

namespace App\Service\Deck;

use App\Engine\Card\CardDefinition;

/** Deck Commander chargé : définitions + URL d'images (pour l'affichage), instanciable pour n'importe quel siège. */
final class LoadedDeck
{
    /**
     * @param list<CardDefinition> $library 99 cartes, une entrée par exemplaire
     * @param array<string, array{small: ?string, normal: ?string}> $images nom de carte => URL d'images
     */
    public function __construct(
        public readonly CardDefinition $commander,
        public readonly array $library,
        public readonly array $images = [],
        public readonly bool $degraded = false,
    ) {
    }

    /** Même forme et mêmes identifiants (`p<siège>_c<n>`, commandant d'abord) que `Decks::commanderDemo()`. */
    public function instantiate(int $playerId): array
    {
        $n = 0;
        $commander = $this->commander->instantiate(sprintf('p%d_c%d', $playerId, $n++), true);
        $library = [];
        foreach ($this->library as $definition) {
            $library[] = $definition->instantiate(sprintf('p%d_c%d', $playerId, $n++));
        }

        return ['commander' => $commander, 'library' => $library];
    }

    /** Forme stockée en base : bibliothèque compactée en `[définition, quantité]` (les basiques se répètent). */
    public function toArray(): array
    {
        $groups = [];
        foreach ($this->library as $definition) {
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last][0] === $definition) {
                $groups[$last][1]++;
            } else {
                $groups[] = [$definition, 1];
            }
        }

        return [
            'commander' => $this->commander->toArray(),
            'library' => array_map(static fn (array $g): array => [$g[0]->toArray(), $g[1]], $groups),
            'images' => $this->images,
            'degraded' => $this->degraded,
        ];
    }

    public static function fromArray(array $d): self
    {
        $library = [];
        foreach ($d['library'] as [$definition, $quantity]) {
            $card = CardDefinition::fromArray($definition);
            for ($i = 0; $i < $quantity; $i++) {
                $library[] = $card;
            }
        }

        return new self(CardDefinition::fromArray($d['commander']), $library, $d['images'] ?? [], (bool) ($d['degraded'] ?? false));
    }
}
