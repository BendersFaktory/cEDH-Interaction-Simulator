<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Mana\Color;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPlan;
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
     * A tenté de piocher dans une bibliothèque vide depuis la dernière vérification des SBA : il perd à la prochaine
     * (CR 704.5b). Sérialisé sous `drewFromEmpty`, omis à faux.
     */
    public bool $drewFromEmpty = false;
    /** Nombre de tours de ce joueur commencés (Starting Town : « votre premier, deuxième ou troisième tour »). Sérialisé sous `turnsTaken`, omis à 0. */
    public int $turnsTaken = 0;
    /**
     * Zone d'exil du joueur (cartes dont il est le propriétaire), dans l'ordre d'arrivée. Seul chemin d'entrée :
     * `GameState::exile()`. Sérialisée sous `exile`, omise quand vide (empreintes inchangées tant que rien n'exile).
     *
     * @var list<CardInstance>
     */
    public array $exile = [];

    /**
     * Couleurs (lettres WUBRG) que peut produire un terrain contrôlé par un adversaire, en `[lettre => true]` : branché par
     * `GameState` (jamais sérialisé, l'état se lit toujours depuis les adversaires). Sans lui (joueur isolé), aucune.
     * Sert aux capacités `ActivatedAbility::DYNAMIC_OPPONENT_LAND` (Fellwar Stone, Exotic Orchard).
     *
     * @var (\Closure(): array<string, true>)|null
     */
    public ?\Closure $opponentColors = null;

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

    /**
     * Copie indépendante (par la forme sérialisée) qui garde le branchement sur les adversaires : pour les simulations
     * locales de l'IA, où une capacité de mana dynamique doit rester évaluable.
     */
    public function duplicate(): self
    {
        $copy = self::fromArray($this->toArray());
        $copy->opponentColors = $this->opponentColors;

        return $copy;
    }

    /**
     * Couleurs (lettres) qu'une capacité de mana dynamique de `$card` autorise maintenant ; null si la capacité n'est pas dynamique
     * (toutes ses alternatives sont permises).
     *
     * @return array<string, true>|null
     */
    public function allowedColors(CardInstance $card, ActivatedAbility $ability): ?array
    {
        if (!$ability->isDynamicMana()) {
            return null;
        }
        if ($ability->spec === ActivatedAbility::DYNAMIC_LUCK) {
            return ($card->counters[CardInstance::LUCK] ?? 0) > 0 ? array_fill_keys(['W', 'U', 'B', 'R', 'G'], true) : ['C' => true]; // Gemstone Caverns
        }
        if ($ability->spec === ActivatedAbility::DYNAMIC_IMPRINT) {
            return array_fill_keys($card->imprint, true);
        }
        if ($ability->spec === ActivatedAbility::DYNAMIC_LEGENDARY) {
            // Mox Amber : couleurs des créatures (et planeswalkers) légendaires que l'on contrôle.
            $colors = [];
            foreach ($this->battlefield as $c) {
                if ($c->isCreature() && $c->isLegendary()) {
                    foreach ($c->colors() as $symbol) {
                        $colors[$symbol] = true;
                    }
                }
            }

            return $colors;
        }

        return $this->opponentColors === null ? [] : ($this->opponentColors)();
    }

    /** L'alternative `$option` de la capacité de mana est-elle utilisable (couleur autorisée si la capacité est dynamique) ? */
    public function optionAllowed(CardInstance $card, ActivatedAbility $ability, int $option): bool
    {
        $allowed = $this->allowedColors($card, $ability);

        return $allowed === null || isset($allowed[$ability->options[$option][0]->symbol()]);
    }

    /** Contrôle-t-il un commandant sur le champ de bataille (Fierce Guardianship) ? */
    public function controlsCommander(): bool
    {
        foreach ($this->battlefield as $card) {
            if ($card->commander) {
                return true;
            }
        }

        return false;
    }

    /** Carte de la main d'id `$cardId`, sans la retirer. */
    public function findHand(string $cardId): ?CardInstance
    {
        foreach ($this->hand as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
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
     * Active une capacité de mana d'un permanent : l'engage (si `{T}`) et ajoute le mana produit à la réserve.
     * Pas de pile, pas d'événement (l'appelant journalise). Seul endroit qui crée du mana. Le mana d'une option restreinte
     * (Eldrazi Temple) va dans la partie restreinte de la réserve.
     *
     * @return array<string, int> mana produit, par symbole
     */
    public function activateManaSource(CardInstance $card, ActivatedAbility $ability, int $option): array
    {
        if ($ability->tap) {
            $card->tapped = true;
        }
        $produced = $ability->produced($option);
        $restriction = $ability->restriction($option);
        foreach ($produced as $symbol => $n) {
            $this->pool->add(Color::fromSymbol($symbol), $n, $restriction);
        }

        return $produced;
    }

    /**
     * Exécute un plan de ManaPayment : active les capacités de mana prévues, puis chaque convertisseur (son coût pris dans la
     * réserve, puis sa production ajoutée), enfin dépense `spend` depuis la réserve : d'abord la partie restreinte prévue
     * (`spendRestricted`), le reste en mana libre.
     * Le surplus produit (Sol Ring pour un `{1}`) reste dans la réserve jusqu'à la fin de l'étape.
     * Les sources dont le coût inclut un sacrifice (Treasure) restent engagées sur le champ de bataille : l'appelant les
     * sacrifie aussitôt avec `GameState::sacrifice($joueur, $result['sacrifice'])`.
     *
     * Les PV payés et les dégâts que s'infligent les sources (`lifeCost`, `selfDamage`) ne sont PAS appliqués ici : l'appelant
     * les applique avec `GameState::hurtByMana($joueur, $result['pain'])` (événements à valeurs absolues).
     *
     * @return array{tapped: list<string>, paid: array<string, int>, fromPool: array<string, int>, sacrifice: list<string>, pain: list<array{source: string, lifeCost: int, selfDamage: int}>}
     *         ids engagés, mana produit par les activations, mana pris dans la réserve préexistante, ids à sacrifier, blessures
     */
    public function payWithPlan(ManaPlan $plan): array
    {
        $tapped = [];
        $paid = [];
        $sacrifice = [];
        $pain = [];
        foreach ($plan->taps as ['card' => $cardId, 'ability' => $abilityIndex, 'option' => $option]) {
            $card = $this->findOnBattlefield($cardId);
            $ability = $card?->abilities()[$abilityIndex] ?? null;
            if ($card === null || $ability === null || !$ability->isManaAbility || ($ability->tap && $card->tapped)) {
                throw new \DomainException("Source de mana introuvable ou déjà engagée : $cardId");
            }
            if ($ability->tap) {
                $tapped[] = $cardId;
            }
            if ($ability->sacrifice) {
                $sacrifice[] = $cardId;
            }
            if ($ability->lifeCost > 0 || $ability->selfDamage > 0) {
                $pain[] = ['source' => $card->name, 'lifeCost' => $ability->lifeCost, 'selfDamage' => $ability->selfDamage];
            }
            foreach ($this->activateManaSource($card, $ability, $option) as $symbol => $n) {
                $paid[$symbol] = ($paid[$symbol] ?? 0) + $n;
            }
        }
        $spent = [];
        foreach ($plan->converters as ['card' => $cardId, 'ability' => $abilityIndex, 'option' => $option, 'spend' => $cost]) {
            $card = $this->findOnBattlefield($cardId);
            $ability = $card?->abilities()[$abilityIndex] ?? null;
            if ($card === null || $ability === null || !$ability->isManaAbility || ($ability->tap && $card->tapped)) {
                throw new \DomainException("Convertisseur introuvable ou déjà engagé : $cardId");
            }
            foreach ($cost as $symbol => $n) {
                $this->pool->spend(Color::fromSymbol($symbol), $n);
                $spent[$symbol] = ($spent[$symbol] ?? 0) + $n;
            }
            if ($ability->tap) {
                $tapped[] = $cardId;
            }
            foreach ($this->activateManaSource($card, $ability, $option) as $symbol => $n) {
                $paid[$symbol] = ($paid[$symbol] ?? 0) + $n;
            }
        }
        $free = $plan->spend;
        foreach ($plan->spendRestricted as $kind => $amounts) {
            foreach ($amounts as $symbol => $n) {
                $this->pool->spendRestricted($kind, Color::fromSymbol($symbol), $n);
                $free[$symbol] -= $n;
            }
        }
        foreach ($free as $symbol => $n) {
            if ($n > 0) {
                $this->pool->spend(Color::fromSymbol($symbol), $n);
            }
        }
        foreach ($plan->spend as $symbol => $n) {
            $spent[$symbol] = ($spent[$symbol] ?? 0) + $n;
        }
        // Pris dans la réserve préexistante = dépensé au-delà de ce que les activations ont produit.
        $fromPool = [];
        foreach (Color::cases() as $color) {
            $symbol = $color->symbol();
            if (($spent[$symbol] ?? 0) > ($paid[$symbol] ?? 0)) {
                $fromPool[$symbol] = $spent[$symbol] - ($paid[$symbol] ?? 0);
            }
        }

        return ['tapped' => $tapped, 'paid' => $paid, 'fromPool' => $fromPool, 'sacrifice' => $sacrifice, 'pain' => $pain];
    }

    public function findOnBattlefield(string $cardId): ?CardInstance
    {
        foreach ($this->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    /** @return list<string> ids des permanents qui étaient engagés */
    public function untapAll(): array
    {
        $ids = [];
        foreach ($this->battlefield as $card) {
            if ($card->tapped && !$card->noUntap) {
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
        ] + ($this->pool->restrictedToArray() === [] ? [] : ['poolRestricted' => $this->pool->restrictedToArray()])
            + ($this->drewFromEmpty ? ['drewFromEmpty' => true] : [])
            + ($this->turnsTaken > 0 ? ['turnsTaken' => $this->turnsTaken] : [])
            + ($this->exile === [] ? [] : ['exile' => $map($this->exile)]);
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
            ManaPool::fromArray($d['pool'] ?? [], $d['poolRestricted'] ?? []),
        );
        $p->lost = (bool) $d['lost'];
        $p->drewFromEmpty = (bool) ($d['drewFromEmpty'] ?? false);
        $p->turnsTaken = (int) ($d['turnsTaken'] ?? 0);
        $p->exile = $map($d['exile'] ?? []);

        return $p;
    }
}
