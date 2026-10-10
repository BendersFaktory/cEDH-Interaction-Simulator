<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Mana\Color;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPool;

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
        public ManaPool $pool = new ManaPool(),
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

    /** Coût réel d'un lancement, taxe de commandant comprise (la taxe s'ajoute au mana générique). */
    public function castCost(CardInstance $card): ManaCost
    {
        $tax = $card->commander ? self::COMMANDER_TAX * ($this->commanderCasts[$card->id] ?? 0) : 0;

        return $card->manaCost()->withExtraGeneric($tax);
    }

    /** @return list<CardInstance> */
    public function untappedLands(): array
    {
        return array_values(array_filter(
            $this->battlefield,
            static fn (CardInstance $c) => $c->isLand() && !$c->tapped,
        ));
    }

    /**
     * Exécute un plan de ManaPayment : engage les terrains (capacité de mana, sans pile), le mana passe par la
     * réserve puis est dépensé pour le sort (la réserve est donc vide à la fin).
     *
     * @param list<array{land: string, color: Color}> $plan
     * @return array{tapped: list<string>, paid: array<string, int>} ids engagés et mana produit par couleur
     */
    public function payWithPlan(array $plan): array
    {
        $tapped = [];
        $paid = [];
        foreach ($plan as ['land' => $landId, 'color' => $color]) {
            foreach ($this->battlefield as $card) {
                if ($card->id === $landId && $card->isLand() && !$card->tapped) {
                    $card->tapped = true;
                    $this->pool->add($color);
                    $tapped[] = $landId;
                    $paid[$color->symbol()] = ($paid[$color->symbol()] ?? 0) + 1;
                    continue 2;
                }
            }
            throw new \DomainException("Terrain introuvable ou déjà engagé : $landId");
        }
        foreach ($plan as ['color' => $color]) {
            $this->pool->spend($color);
        }

        return ['tapped' => $tapped, 'paid' => $paid];
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
            'pool' => $this->pool->toArray(),
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
            ManaPool::fromArray($d['pool'] ?? []),
        );
        $p->lost = (bool) $d['lost'];

        return $p;
    }
}
