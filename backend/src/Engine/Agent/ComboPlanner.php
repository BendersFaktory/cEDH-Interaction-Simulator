<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\CastSpell;
use App\Engine\Action\ResolutionChoice;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\PlayerState;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;

/**
 * IA de combo (étape 16), sans état comme `HeuristicAgent` qui l'interroge en premier : chaque décision se relit sur l'état.
 *
 * 1. **Breach** : Underworld Breach + Lion's Eye Diamond + Brain Freeze. Lancer LED (gratuit, +1 storm), lancer Brain Freeze sur
 *    soi (ou sur l'adversaire à la plus grosse bibliothèque si Thassa's Oracle n'est plus accessible), casser LED pour {U}{U}{U}
 *    quand rien n'est payable, relancer LED par escape ; quand la bibliothèque est assez petite, lancer Thassa's Oracle (main ou
 *    escape). Les trois cartes exilées par escape épargnent les pièces (LED, Brain Freeze, Oracle) : terrains d'abord.
 * 2. **Oracle** : Thassa's Oracle + Demonic Consultation / Tainted Pact. Lancer Oracle seulement si l'on peut payer aussi
 *    l'enabler ; avec son trigger sur la pile, lancer l'enabler (Consultation : un nom absent ; Tainted Pact : continuer
 *    jusqu'à vider la bibliothèque). Si Oracle est contrée, la bibliothèque reste intacte.
 * 3. **Ad Nauseam** : lancé dans sa phase principale, pile vide, avec au moins `AD_NAUSEAM_MIN_LIFE` PV ; continue tant que la
 *    pire carte possible (la plus grosse valeur de mana restant dans la bibliothèque, sans regarder l'ordre) ne le tue pas, et
 *    s'arrête dès qu'Oracle et un enabler sont en main.
 *
 * Seuils non calibrés : `bin/audit-combo.php` mesure le résultat.
 */
final class ComboPlanner
{
    /** PV minimum pour lancer Ad Nauseam (non calibré). */
    public const AD_NAUSEAM_MIN_LIFE = 20;
    /** Ad Nauseam : PV gardés au-dessus de la pire révélation possible (1 = ne jamais tomber à 0). */
    public const AD_NAUSEAM_MARGIN = 1;
    /** Breach : cartes (main + cimetière, hors pièces) qu'il faut avoir pour lancer Underworld Breach dans l'intention de combo. */
    public const BREACH_FUEL = 5;

    /** Prochaine action d'un combo, ou null (l'heuristique ordinaire décide). */
    public static function next(GameState $s, int $playerId, array $legal): ?Action
    {
        $p = $s->player($playerId);
        if (!self::hasPieces($p)) {
            return null; // cas courant (decks de démo) : rien à calculer
        }

        // Oracle + enabler d'abord quand tout est payable : il ne défausse pas la main comme LED.
        return self::oracle($s, $playerId, $legal) ?? self::breach($s, $playerId, $legal) ?? self::adNauseam($s, $playerId, $legal);
    }

    /**
     * Décision au milieu d'une résolution propre au combo : Tainted Pact continue tant que mon trigger d'Oracle attend sur la
     * pile ; Ad Nauseam (voir la classe). Null : l'heuristique ordinaire décide.
     *
     * @param list<ResolutionChoice> $choices
     */
    public static function resolution(GameState $s, int $playerId, array $choices): ?ResolutionChoice
    {
        $by = static fn (string $choice): ?ResolutionChoice => array_values(array_filter($choices, static fn (ResolutionChoice $c) => $c->choice === $choice))[0] ?? null;
        $kind = $s->selection['kind'] ?? '';
        if ($kind === 'tainted_pact' && self::oracleTriggerWaiting($s, $playerId)) {
            return $by('continue');
        }
        if ($kind === 'ad_nauseam') {
            $p = $s->player($playerId);
            $worst = 0;
            foreach ($p->library as $card) {
                $worst = max($worst, $card->manaCost()->manaValue());
            }
            $assembled = self::find($p->hand, self::isOracle(...)) !== null && self::find($p->hand, self::isEnabler(...)) !== null;

            return (!$assembled && $p->life - $worst >= self::AD_NAUSEAM_MARGIN ? $by('continue') : null) ?? $by('stop');
        }

        return null;
    }

    /**
     * Sorts que seul le planificateur lance (l'heuristique ordinaire ne les lance jamais, ils se gâcheraient hors combo) : Ad
     * Nauseam, Thassa's Oracle, Underworld Breach, Brain Freeze. Lion's Eye Diamond reste lancé par l'heuristique : en jeu, il
     * attend le combo.
     */
    public static function reserved(?CardInstance $card): bool
    {
        return $card !== null && ($card->hasEffect('ad_nauseam') || self::isOracle($card) || self::isBrainFreeze($card)
            || in_array(CardInstance::STATIC_ESCAPE, $card->statics, true));
    }

    /** Pièce d'un combo, gardée en dernier à la défausse (`HeuristicAgent::discard`) : les sorts réservés, LED et les enablers. */
    public static function keeps(CardInstance $card): bool
    {
        return self::reserved($card) || self::isLed($card) || self::isEnabler($card);
    }

    // --- Breach -----------------------------------------------------------------------------------------------------------

    private static function breach(GameState $s, int $playerId, array $legal): ?Action
    {
        if (!self::ownMainEmptyStack($s, $playerId)) {
            return null;
        }
        $p = $s->player($playerId);
        $zones = [...$p->hand, ...$p->battlefield, ...$p->graveyard];
        $led = self::find($zones, self::isLed(...));
        $freeze = self::find([...$p->hand, ...$p->graveyard], self::isBrainFreeze(...));
        if (!$s->controlsStatic($playerId, CardInstance::STATIC_ESCAPE)) {
            // Breach en main : la lancer seulement si les deux autres pièces sont là, avec de quoi nourrir les escapes.
            $fuel = count(array_filter([...$p->hand, ...$p->graveyard], static fn (CardInstance $c) => !self::isPiece($c)));
            if ($led === null || $freeze === null || $fuel < self::BREACH_FUEL) {
                return null;
            }

            return self::castOf($s, $legal, static fn (CardInstance $c) => in_array(CardInstance::STATIC_ESCAPE, $c->statics, true));
        }
        if (($win = self::oracleWin($s, $playerId, $legal)) !== null) {
            return $win;
        }
        if ($led === null || $freeze === null) {
            return null;
        }
        // Bibliothèque assez petite, Oracle en main ou au cimetière mais pas encore payable : du mana d'abord, plus de Brain Freeze.
        $needOracle = count($p->library) <= $s->devotion($playerId, 'U') + 2 && self::find([...$p->hand, ...$p->graveyard], self::isOracle(...)) !== null;
        // 1. LED en main : gratuit, +1 storm.
        if (in_array($led, $p->hand, true) && ($cast = self::castOf($s, $legal, self::isLed(...), fromHand: true)) !== null) {
            return $cast;
        }
        // 2. Brain Freeze payable : sur soi tant qu'Oracle reste accessible, sinon sur l'adversaire à la plus grosse bibliothèque.
        $target = self::freezeTarget($s, $playerId);
        if (!$needOracle && $target !== null && ($cast = self::castOf($s, $legal, self::isBrainFreeze(...), targets: ['player:' . $target])) !== null) {
            return $cast;
        }
        // 3. Casser LED pour {U}{U}{U} (la main part au cimetière) : pour Oracle, ou si Brain Freeze sera ensuite lançable.
        if (in_array($led, $p->battlefield, true) && ($needOracle || ($target !== null && self::fuelAfterCrack($p) >= 3))) {
            foreach ($legal as $a) {
                if ($a instanceof ActivateAbility && $a->cardId === $led->id && ($ability = $led->abilities()[$a->abilityIndex] ?? null) !== null
                    && $ability->discardHand && ($ability->produced($a->option)['U'] ?? 0) > 0) {
                    return $a;
                }
            }
        }
        // 4. LED au cimetière : la relancer par escape.
        if (in_array($led, $p->graveyard, true)) {
            return self::castOf($s, $legal, self::isLed(...));
        }

        return null;
    }

    /** Bibliothèque assez petite : lancer Thassa's Oracle (main, ou escape avec Breach). */
    private static function oracleWin(GameState $s, int $playerId, array $legal): ?Action
    {
        $p = $s->player($playerId);
        if (count($p->library) > $s->devotion($playerId, 'U') + 2) { // Oracle ajoute {U}{U} à la dévotion
            return null;
        }

        return self::castOf($s, $legal, self::isOracle(...));
    }

    private static function freezeTarget(GameState $s, int $playerId): ?int
    {
        $p = $s->player($playerId);
        $oracle = self::find([...$p->hand, ...$p->graveyard, ...$p->library], self::isOracle(...));
        if ($oracle !== null && $p->library !== []) {
            return $playerId;
        }
        $best = null;
        foreach ($s->players as $o) {
            if ($o->id !== $playerId && !$o->lost && $o->library !== [] && ($best === null || count($o->library) > count($s->player($best)->library))) {
                $best = $o->id;
            }
        }

        return $best;
    }

    /** Cartes du cimetière (hors pièces) qu'il y aura après avoir cassé LED : de quoi payer l'escape de Brain Freeze. */
    private static function fuelAfterCrack(PlayerState $p): int
    {
        return count(array_filter([...$p->hand, ...$p->graveyard], static fn (CardInstance $c) => !self::isPiece($c)));
    }

    // --- Oracle + Consultation / Tainted Pact ------------------------------------------------------------------------------

    private static function oracle(GameState $s, int $playerId, array $legal): ?Action
    {
        $p = $s->player($playerId);
        if (self::oracleTriggerWaiting($s, $playerId)) {
            if (count($p->library) <= $s->devotion($playerId, 'U')) {
                return null; // déjà gagné à la résolution
            }

            return self::castOf($s, $legal, static fn (CardInstance $c) => $c->hasEffect('consult'), targets: ['name:'])
                ?? self::castOf($s, $legal, static fn (CardInstance $c) => $c->hasEffect('tainted_pact'));
        }
        if (!self::ownMainEmptyStack($s, $playerId)) {
            return null;
        }
        if (($win = self::oracleWin($s, $playerId, $legal)) !== null) {
            return $win;
        }
        $oracle = self::find($p->hand, self::isOracle(...));
        if ($oracle === null) {
            return null;
        }
        foreach ($p->hand as $enabler) {
            if (self::isEnabler($enabler) && ManaPayment::plan($p, ManaCost::parse($p->castCost($oracle) . $p->castCost($enabler)), 0, $s->turn) !== null) {
                return self::castOf($s, $legal, self::isOracle(...), fromHand: true);
            }
        }

        return null;
    }

    /** Mon trigger de Thassa's Oracle attend sur le dessus de la pile. */
    private static function oracleTriggerWaiting(GameState $s, int $playerId): bool
    {
        $top = $s->topOfStack();

        return $top !== null && $top->controller === $playerId && $top->trigger?->effect === 'oracle';
    }

    // --- Ad Nauseam -------------------------------------------------------------------------------------------------------

    private static function adNauseam(GameState $s, int $playerId, array $legal): ?Action
    {
        if (!self::ownMainEmptyStack($s, $playerId) || $s->player($playerId)->life < self::AD_NAUSEAM_MIN_LIFE) {
            return null;
        }

        return self::castOf($s, $legal, static fn (CardInstance $c) => $c->hasEffect('ad_nauseam'), fromHand: true);
    }

    // --- Outils -----------------------------------------------------------------------------------------------------------

    private static function hasPieces(PlayerState $p): bool
    {
        foreach ([...$p->hand, ...$p->battlefield, ...$p->graveyard] as $card) {
            if (self::keeps($card)) {
                return true;
            }
        }

        return false;
    }

    private static function ownMainEmptyStack(GameState $s, int $playerId): bool
    {
        return $s->stack === [] && $s->activePlayer === $playerId && $s->phase->isMain();
    }

    /** Pièce à ne jamais exiler par escape : LED, Brain Freeze, Thassa's Oracle. */
    private static function isPiece(CardInstance $c): bool
    {
        return self::isLed($c) || self::isBrainFreeze($c) || self::isOracle($c);
    }

    private static function isOracle(CardInstance $c): bool
    {
        foreach ($c->triggers as $t) {
            if ($t->effect === 'oracle' && $t->event === TriggeredAbility::ETB) {
                return true;
            }
        }

        return false;
    }

    private static function isEnabler(CardInstance $c): bool
    {
        return $c->hasEffect('consult') || $c->hasEffect('tainted_pact');
    }

    private static function isLed(CardInstance $c): bool
    {
        return array_filter($c->abilities, static fn (ActivatedAbility $a) => $a->discardHand) !== [];
    }

    private static function isBrainFreeze(CardInstance $c): bool
    {
        return $c->hasKeyword(CardInstance::STORM) && $c->hasEffect('mill');
    }

    /** @param list<CardInstance> $cards */
    private static function find(array $cards, callable $match): ?CardInstance
    {
        foreach ($cards as $card) {
            if ($match($card)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Premier lancement légal d'une carte qui vérifie `$match` (cibles exactes si données ; depuis la main seulement si
     * `$fromHand`). Un lancement par escape reçoit les trois cartes à exiler qui épargnent les pièces ; null s'il n'y en a pas assez.
     */
    private static function castOf(GameState $s, array $legal, callable $match, ?array $targets = null, bool $fromHand = false): ?CastSpell
    {
        foreach ($legal as $a) {
            if (!$a instanceof CastSpell || ($targets !== null && $a->targets !== $targets)) {
                continue;
            }
            $p = $s->player($a->player());
            $card = $a->alt === CastSpell::ALT_ESCAPE ? ResolutionChoice::find($p->graveyard, $a->cardId) : $s->castable($a->player(), $a->cardId);
            if ($card === null || !$match($card) || ($fromHand && $p->findInHand($card->id) === null)) {
                continue;
            }
            if ($a->alt !== CastSpell::ALT_ESCAPE) {
                return $a;
            }
            $exiles = self::escapeExiles($p, $card);
            if ($exiles !== null) {
                return new CastSpell($a->player(), $a->cardId, $a->targets, $a->x, $a->alt, $a->mode, $a->sacrifice, $a->kicked, $exiles);
            }
        }

        return null;
    }

    /**
     * Trois cartes du cimetière à exiler pour l'escape de `$card` : jamais une pièce, les terrains d'abord, puis les plus
     * anciennes. Null s'il n'y en a pas trois.
     *
     * @return list<string>|null
     */
    private static function escapeExiles(PlayerState $p, CardInstance $card): ?array
    {
        $others = array_values(array_filter($p->graveyard, static fn (CardInstance $c) => $c->id !== $card->id && !self::isPiece($c)));
        usort($others, static fn (CardInstance $a, CardInstance $b) => (int) $b->isLand() <=> (int) $a->isLand()); // tri stable (PHP 8)

        return count($others) < 3 ? null : array_map(static fn (CardInstance $c) => $c->id, array_slice($others, 0, 3));
    }
}
