<?php

declare(strict_types=1);

namespace App\Engine\Game;

final class PlayerState
{
    /** Taxe de commandant : +2 par lancement précédent depuis la zone de commandement. */
    public const COMMANDER_TAX = 2;
    /** Un joueur ayant subi 21 dégâts d'un même commandant est éliminé. */
    public const COMMANDER_DAMAGE_LIMIT = 21;
    /** Taille de main maximale à la fin du tour (défausse au cleanup). */
    public const MAX_HAND_SIZE = 7;

    public bool $lost = false;

    /**
     * @param list<CardInstance> $library    index 0 = dessus de la bibliothèque
     * @param list<CardInstance> $hand
     * @param list<CardInstance> $battlefield
     * @param list<CardInstance> $graveyard
     * @param list<CardInstance> $command    zone de commandement
     * @param array<string, int> $commanderCasts  id du commandant => nombre de lancements depuis la zone de commandement
     * @param array<string, int> $commanderDamage "propriétaireId:idCommandant" => dégâts de commandant subis
     */
    public function __construct(
        public readonly int $id,
        public int $life,
        public array $library = [],
        public array $hand = [],
        public array $battlefield = [],
        public array $graveyard = [],
        public array $command = [],
        public array $commanderCasts = [],
        public array $commanderDamage = [],
    ) {
    }

    public function findInHand(string $cardId): ?CardInstance
    {
        foreach ($this->hand as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    public function findInCommand(string $cardId): ?CardInstance
    {
        foreach ($this->command as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    /** Une carte lançable : en main ou dans la zone de commandement. */
    public function findCastable(string $cardId): ?CardInstance
    {
        return $this->findInHand($cardId) ?? $this->findInCommand($cardId);
    }

    public function takeFromHand(string $cardId): ?CardInstance
    {
        foreach ($this->hand as $i => $card) {
            if ($card->id === $cardId) {
                array_splice($this->hand, $i, 1);

                return $card;
            }
        }

        return null;
    }

    public function takeFromBattlefield(string $cardId): ?CardInstance
    {
        foreach ($this->battlefield as $i => $card) {
            if ($card->id === $cardId) {
                array_splice($this->battlefield, $i, 1);

                return $card;
            }
        }

        return null;
    }

    public function takeFromCommand(string $cardId): ?CardInstance
    {
        foreach ($this->command as $i => $card) {
            if ($card->id === $cardId) {
                array_splice($this->command, $i, 1);

                return $card;
            }
        }

        return null;
    }

    /** Coût réel d'un lancement, taxe de commandant comprise (squelette : mana générique uniquement). */
    public function castCost(CardInstance $card): int
    {
        $tax = $card->commander ? self::COMMANDER_TAX * ($this->commanderCasts[$card->id] ?? 0) : 0;

        return $card->cost + $tax;
    }

    /** @return list<CardInstance> */
    public function untappedLands(): array
    {
        return array_values(array_filter(
            $this->battlefield,
            static fn (CardInstance $c) => $c->isLand() && !$c->tapped,
        ));
    }

    /** Mana disponible : 1 par terrain non engagé (squelette : mana générique uniquement). */
    public function availableMana(): int
    {
        return count($this->untappedLands());
    }

    /** @return list<string> ids des terrains engagés */
    public function tapLands(int $n): array
    {
        $ids = [];
        foreach ($this->battlefield as $card) {
            if (count($ids) >= $n) {
                break;
            }
            if ($card->isLand() && !$card->tapped) {
                $card->tapped = true;
                $ids[] = $card->id;
            }
        }

        return $ids;
    }

    /** @return list<string> ids des permanents qui étaient engagés */
    public function untapAll(): array
    {
        $ids = [];
        foreach ($this->battlefield as $card) {
            if ($card->tapped) {
                $card->tapped = false;
                $ids[] = $card->id;
            }
        }

        return $ids;
    }

    public function toArray(): array
    {
        $map = static fn (array $cards) => array_map(static fn (CardInstance $c) => $c->toArray(), $cards);

        return [
            'id' => $this->id,
            'life' => $this->life,
            'lost' => $this->lost,
            'library' => $map($this->library),
            'hand' => $map($this->hand),
            'battlefield' => $map($this->battlefield),
            'graveyard' => $map($this->graveyard),
            'command' => $map($this->command),
            'commanderCasts' => $this->commanderCasts,
            'commanderDamage' => $this->commanderDamage,
        ];
    }

    public static function fromArray(array $d): self
    {
        $map = static fn (array $cards) => array_map([CardInstance::class, 'fromArray'], $cards);
        $p = new self(
            (int) $d['id'],
            (int) $d['life'],
            $map($d['library']),
            $map($d['hand']),
            $map($d['battlefield']),
            $map($d['graveyard']),
            $map($d['command']),
            array_map('intval', $d['commanderCasts']),
            array_map('intval', $d['commanderDamage']),
        );
        $p->lost = (bool) $d['lost'];

        return $p;
    }
}
