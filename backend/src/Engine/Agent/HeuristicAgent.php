<?php

declare(strict_types=1);

namespace App\Engine\Agent;

use App\Engine\Ability\AbilityManager;
use App\Engine\Ability\ActivatedAbility;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\ArrangeTop;
use App\Engine\Action\BottomCard;
use App\Engine\Action\CastSpell;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\Discard;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Action\KeepHand;
use App\Engine\Action\KeepLegend;
use App\Engine\Action\ScryArrange;
use App\Engine\Action\Mulligan;
use App\Engine\Action\PayCost;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PlayLand;
use App\Engine\Action\PregameChoice;
use App\Engine\Action\PutBack;
use App\Engine\Action\PutTrigger;
use App\Engine\Action\ReplaceChoice;
use App\Engine\Action\ResolutionChoice;
use App\Engine\Card\ReplacementEffect;
use App\Engine\Game\CardInstance;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PlayerState;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;

/**
 * IA de départ, volontairement simple :
 *  1. contre le sort adverse au dessus de la pile s'il le peut ;
 *  2. joue un terrain ;
 *  3. à son tour, en phase principale, pile vide : lance un sort au hasard (jamais sur soi-même) ;
 *  4. sinon passe.
 * Triggers : posés dans l'ordre de la file ; une cible de dégâts est l'adversaire vivant le plus faible.
 * Garder de quoi contrer : avec un contresort payable en main, ne lance (sort ou capacité à coût de mana) que ce qui laisse
 * assez de sources non engagées pour le payer (voir `keepCounterMana`).
 * Défausse (main > 7), par ordre : terrain en surplus, carte injouable (couleur introuvable ou coût hors de portée), carte la
 * plus chère ; on garde en dernier les contresorts puis les terrains dont on a besoin (voir `discard`).
 * Mulligan (tous profils) : garde une main de 2 à 5 terrains, sinon prend un mulligan tant qu'il en a pris moins de 2,
 * sinon garde ; les cartes à mettre au fond sont choisies comme une défausse (voir `discard`).
 * Combat : attaque avec toutes ses créatures sur l'adversaire qu'elle peut tuer, sinon le plus menaçant (sauf le commandant s'il peut mourir, et une
 * garde si un adversaire peut me tuer) ; bloque quand le bloqueur survit ou tue l'attaquant, bloque à deux quand la paire
 * tue l'attaquant sans mourir toute entière, et chump-bloque seulement si l'attaque est létale.
 * Sélection de cartes : Brainstorm remet les cartes qu'il défausserait (terrains en surplus d'abord, voir `discard`) ; Ponder
 * garde en ordre ce qui est utile (terrain s'il en manque, sort à portée de mana) et mélange si rien ne l'est. Rhystic Study :
 * paie {1} sauf si ça l'empêche de payer son contresort gardé (voir `reservedCounter`).
 * Profils (`AgentRegistry`) : `ai:heuristic` (défaut ci-dessus), `ai:aggro` (ne garde ni contresort ni bloqueur, attaque
 * le plus faible), `ai:control` (contre plus large, garde un bloqueur dès la moitié de ses PV menacée). Le profil est figé à
 * la construction : l'agent reste sans état.
 */
final class HeuristicAgent implements PlayerAgent
{
    /** PV au-dessus desquels l'IA paie une shockland plutôt que de l'avoir engagée. */
    private const SHOCK_LIFE_FLOOR = 10;
    /** Ad Nauseam : l'IA révèle la carte suivante tant que ses PV dépassent ce seuil (non calibré, étape 16). */
    private const AD_NAUSEAM_LIFE_FLOOR = 15;
    /** Necropotence : l'IA paie 1 PV par carte tant que ses PV dépassent ce seuil et qu'elle a moins de cartes en attente que ce nombre. */
    private const NECRO_LIFE_FLOOR = 20;
    private const NECRO_MAX_PENDING = 3;

    /**
     * @param int  $counterThreshold menace minimale d'un sort adverse pour le contrer (voir `threat`)
     * @param int  $hugeThreat       valeur d'un de mes sorts pour le lancer malgré la réserve de contresort
     * @param bool $keepCounterMana  garder de quoi payer son contresort (voir `keepCounterMana`)
     * @param int  $guardFactor      une créature reste en garde si la force d'un adversaire × ce facteur atteint mes PV (0 : jamais)
     * @param bool $targetByThreat   cible d'attaque : le plus menaçant (sinon le plus faible en PV), après la cible létale
     */
    public function __construct(
        private readonly int $counterThreshold = 3,
        private readonly int $hugeThreat = 6,
        private readonly bool $keepCounterMana = true,
        private readonly int $guardFactor = 1,
        private readonly bool $targetByThreat = true,
    ) {
    }

    /** Agressif : tout pour l'attaque, aucune réserve de contresort ni garde, sur l'adversaire le plus faible. */
    public static function aggro(): self
    {
        return new self(keepCounterMana: false, guardFactor: 0, targetByThreat: false);
    }

    /** Contrôle : contre dès une menace de 2, ne lâche sa réserve que pour un sort énorme, garde un bloqueur plus tôt. */
    public static function control(): self
    {
        return new self(counterThreshold: 2, hugeThreat: 8, guardFactor: 2);
    }

    public function decide(GameState $state, int $playerId, array $legal): Action
    {
        if ($state->exilePlays !== [] || $state->player($playerId)->graveyard !== []) {
            // Cartes jouables hors de la main et de la zone de commandement (exil : Ragavan, Opposition Agent ; cimetière :
            // Flashback) : les heuristiques ne regardent que la main et la zone de commandement, elles décident sans elles ;
            // si elles passeraient dans sa phase principale pile vide, l'IA joue la première de ces cartes (une permission de
            // Ragavan tombe à la fin du tour).
            $p = $state->player($playerId);
            $elsewhere = static fn (Action $a) => ($a instanceof CastSpell && $p->findCastable($a->cardId) === null)
                || ($a instanceof PlayLand && $p->findInHand($a->cardId) === null);
            // Jamais lancées par l'IA, d'où qu'elles viennent (Escape d'Underworld Breach compris) : voir `neverCasts()`.
            $legal = array_values(array_filter($legal, fn (Action $a) => !($a instanceof CastSpell && $elsewhere($a) && $this->neverCasts(self::cardOf($state, $a)))));
            $outside = array_values(array_filter($legal, $elsewhere));
            if ($outside !== []) {
                $choice = $this->decide($state, $playerId, array_values(array_filter($legal, static fn (Action $a) => !$elsewhere($a))));
                $ownMain = $state->activePlayer === $playerId && $state->phase->isMain() && $state->stack === [];

                return $choice instanceof PassPriority && $ownMain ? $outside[0] : $choice;
            }
        }
        if ($state->selection !== null) {
            return $this->selectionDecision($state, $playerId, $legal);
        }
        if ($state->scryPending) {
            return $this->scryDecision($state, $playerId, $legal);
        }
        if ($state->paymentPending) {
            return $this->paymentDecision($state, $playerId, $legal);
        }
        if ($state->mulligan !== null) {
            return $this->mulliganDecision($state, $legal, $playerId);
        }
        $discards = array_values(array_filter($legal, static fn (Action $a) => $a instanceof Discard));
        if ($discards !== []) {
            return $this->discard($state, $discards, $playerId);
        }
        $triggers = array_values(array_filter($legal, static fn (Action $a) => $a instanceof PutTrigger));
        if ($triggers !== []) {
            return $this->putTrigger($state, $triggers, $playerId);
        }
        $legends = array_values(array_filter($legal, static fn (Action $a) => $a instanceof KeepLegend));
        if ($legends !== []) {
            return $this->keepLegend($state, $legends, $playerId);
        }
        if ($state->combat->declaring === 'attackers') {
            return $this->declareAttackers($state, $legal, $playerId);
        }
        if ($state->combat->declaring === 'blockers') {
            return $this->declareBlockers($state, $legal, $playerId);
        }

        $top = $state->topOfStack();

        if ($top !== null && $top->controller !== $playerId && ($threat = $this->threat($state, $playerId, $top)) >= $this->counterThreshold) {
            foreach ($legal as $action) {
                if ($action instanceof CastSpell && $this->isCounter($state, $action) && !$this->isPitch($action)
                    && $action->targets === ['stack:' . $top->id] && $this->counterWorks($state, $playerId, $action, $top)) {
                    return $action;
                }
            }
            // Faute de contresort payable : Force of Will / Force of Negation en exilant une carte, contre une menace énorme seulement.
            if ($threat >= $this->hugeThreat && ($pitch = $this->cheapestPitch($state, $playerId, $legal, $top)) !== null) {
                return $pitch;
            }
        }

        foreach ($legal as $action) {
            if ($action instanceof PlayLand) {
                return $this->landVariant($state, $playerId, $action, $legal);
            }
        }

        // Fetchland : dès sa propre phase principale, pile vide, pour avoir le terrain cherché à temps (jamais « sans rien trouver »).
        if ($top === null && $state->activePlayer === $playerId && $state->phase->isMain()) {
            $fetch = $this->fetchAction($state, $playerId, $legal);
            if ($fetch !== null) {
                return $fetch;
            }
        }

        // Wild Mongrel : en combat (étape des bloqueurs), défausser un terrain pour +1/+1 si la créature attaque ou bloque.
        if ($state->phase === Phase::DeclareBlockers && $state->combat->declaring === null) {
            foreach ($legal as $action) {
                if ($action instanceof ActivateAbility && $this->ability($state, $action)?->effect === 'pump'
                    && (isset($state->combat->attackers[$action->cardId]) || $state->combat->isBlocking($action->cardId))
                    && $state->player($playerId)->findInHand($action->costChoices[0] ?? '')?->isLand()) {
                    return $action;
                }
            }
        }

        // Capacité de dégâts (Prodigal Pinger, Goblin Bombardment) : sur l'adversaire vivant le plus faible (égalité : plus
        // petit id). Bombardment ne sacrifie que des jetons. Les capacités de mana ne sont jamais choisies : l'auto-paiement
        // s'en charge.
        $ping = null;
        foreach ($legal as $action) {
            if ($action instanceof ActivateAbility && !$action->mana && $this->ability($state, $action)?->effect === 'damage'
                && ($action->costChoices === [] || $this->battlefieldCard($state, $playerId, $action->costChoices[0])?->token)
                && ($target = $this->playerTarget($action)) !== null && $target !== $playerId) {
                $life = $state->player($target)->life;
                if ($ping === null || $life < $ping[1] || ($life === $ping[1] && $target < $ping[2])) {
                    $ping = [$action, $life, $target];
                }
            }
        }
        if ($ping !== null) {
            return $ping[0];
        }

        if ($top === null && $state->activePlayer === $playerId && $state->phase->isMain()) {
            $casts = [];
            foreach ($legal as $action) {
                if ($action instanceof CastSpell
                    && !$this->isCounter($state, $action)
                    && !$this->aiSkips($state, $action)
                    && !$this->isPitch($action)
                    && !str_starts_with((string) $action->alt, CastSpell::ALT_RETURN) // Gush : jamais en renvoyant ses Îles en phase principale
                    && !in_array('player:' . $playerId, $action->targets, true)) {
                    $casts[] = $action;
                }
            }
            foreach ($casts as $c) {
                if ($state->player($playerId)->findCastable($c->cardId)?->name === 'Sol Ring') {
                    return $c;
                }
            }
            $casts = $this->bestExileTargets($state, $playerId, $casts);
            $casts = $this->bestTutorTargets($state, $playerId, $casts);
            $casts = array_values(array_filter($casts, fn (CastSpell $c) => $this->worthCasting($state, $playerId, $c)));
            $casts = $this->keepCounterMana($state, $playerId, $casts);
            if ($casts !== []) {
                $casts = $this->leastWasteful($state, $playerId, $casts);
                $pick = $this->withBestX($state, $casts[$state->rng->int(0, count($casts) - 1)], $casts);

                return $this->manaSparingCounter($state, $playerId, $pick, $legal) ?? $pick;
            }

            // Plus rien à lancer, en phase principale 2 : Necropotence (1 PV par carte, voir `NECRO_LIFE_FLOOR`), jetons (Kher Keep),
            // puis pioche (Tome ; Mind Stone seulement main vide).
            if ($state->phase === Phase::Main2 && $state->player($playerId)->life > self::NECRO_LIFE_FLOOR
                && count(array_filter($state->delayedTriggers, static fn ($t) => $t->controller === $playerId && $t->ability()->effect === 'necro_return')) < self::NECRO_MAX_PENDING) {
                foreach ($legal as $action) {
                    if ($action instanceof ActivateAbility && $this->ability($state, $action)?->effect === 'necro') {
                        return $action;
                    }
                }
            }
            if ($state->phase === Phase::Main2) {
                foreach (['token', 'draw'] as $effect) {
                    foreach ($legal as $action) {
                        $ability = $action instanceof ActivateAbility ? $this->ability($state, $action) : null;
                        if ($ability?->effect === $effect && (!$ability->sacrifice || $state->player($playerId)->hand === [])
                            && $this->abilityKeepsCounterMana($state, $playerId, $action, $ability)) {
                            return $action;
                        }
                    }
                }
            }
        }

        return AutoPassAgent::pass($legal, $playerId);
    }

    /**
     * Shockland : payer les PV (variante `payLife: true`) tant qu'il en reste plus de `SHOCK_LIFE_FLOOR`, sinon l'avoir engagée.
     * Terrain ordinaire : l'action telle quelle.
     *
     * @param list<Action> $legal
     */
    private function landVariant(GameState $state, int $playerId, PlayLand $first, array $legal): PlayLand
    {
        if ($first->payLife === null) {
            return $first;
        }
        $pay = $state->player($playerId)->life > self::SHOCK_LIFE_FLOOR;
        foreach ($legal as $action) {
            if ($action instanceof PlayLand && $action->cardId === $first->cardId && $action->payLife === $pay) {
                return $action;
            }
        }

        return $first;
    }

    /**
     * Fetchland à activer : le terrain cherché qui apporte le plus de couleurs que ses terrains ne produisent pas encore, sinon
     * le premier ; une shockland cherchée n'est payée qu'au-dessus de `SHOCK_LIFE_FLOOR`. Rien si la recherche ne trouverait
     * rien, ou si les PV sont trop bas pour payer le coût sans risque.
     *
     * @param list<Action> $legal
     */
    private function fetchAction(GameState $state, int $playerId, array $legal): ?ActivateAbility
    {
        $p = $state->player($playerId);
        $produced = [];
        foreach ($p->battlefield as $card) {
            if ($card->isLand()) {
                foreach (self::colorsProduced($card) as $symbol) {
                    $produced[$symbol] = true;
                }
            }
        }
        $best = null;
        foreach ($legal as $action) {
            $ability = $action instanceof ActivateAbility ? $this->ability($state, $action) : null;
            if ($ability?->effect !== 'fetch' || $action->targets === ['library:none'] || $p->life <= $ability->lifeCost + self::SHOCK_LIFE_FLOOR / 2) {
                continue;
            }
            $land = null;
            foreach ($p->library as $c) {
                if ('library:' . $c->id === $action->targets[0]) {
                    $land = $c;
                }
            }
            if ($land === null) {
                continue;
            }
            if ($land->untappedLifeCost > 0 && in_array('life', $action->targets, true) !== ($p->life - $ability->lifeCost > self::SHOCK_LIFE_FLOOR)) {
                continue;
            }
            $new = count(array_diff(self::colorsProduced($land), array_keys($produced)));
            if ($best === null || $new > $best[1]) {
                $best = [$action, $new];
            }
        }

        return $best[0] ?? null;
    }

    /** @return list<string> couleurs (lettres) que produisent les capacités de mana d'une carte */
    private static function colorsProduced(CardInstance $card): array
    {
        $out = [];
        foreach ($card->abilities() as $a) {
            foreach ($a->options as $option) {
                foreach ($option as $color) {
                    $out[$color->symbol()] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * Sort d'exil (Swords to Plowshares) : une seule action par carte, sur le permanent adverse le plus menaçant (valeur de
     * `cardValue` + force ; égalité : premier dans l'ordre des cibles) ; jamais sur un des miens. Les autres sorts sont
     * rendus tels quels, dans le même ordre.
     *
     * @param list<CastSpell> $casts
     * @return list<CastSpell>
     */
    private function bestExileTargets(GameState $state, int $playerId, array $casts): array
    {
        $isExile = static fn (CastSpell $c) => $state->player($playerId)->findCastable($c->cardId)?->hasEffect('exile') === true;
        $best = [];
        foreach ($casts as $c) {
            $target = $isExile($c) ? $this->targetPermanent($state, $c->targets[0] ?? '') : null;
            if ($target === null || $target[0] === $playerId) {
                continue;
            }
            $score = $this->cardValue($target[1], 0) + $target[1]->currentPower();
            if (!isset($best[$c->cardId]) || $score > $best[$c->cardId][1]) {
                $best[$c->cardId] = [$c, $score];
            }
        }

        return array_values(array_filter($casts, static fn (CastSpell $c) => !$isExile($c) || ($best[$c->cardId][0] ?? null) === $c));
    }

    /** Cumulative upkeep (Mystic Remora) : coût total au-delà duquel l'IA sacrifie la source. */
    private const CUMULATIVE_UPKEEP_MAX = 3;

    /**
     * Paiement à la résolution. Daze, Mana Leak, Pact of Negation : payer dès que possible (première action proposée), sinon
     * refuser. Rhystic Study : payer seulement si le contresort gardé (`reservedCounter`) reste payable après.
     *
     * @param list<Action> $legal
     */
    private function paymentDecision(GameState $state, int $playerId, array $legal): Action
    {
        $pay = null;
        $refuse = null;
        foreach ($legal as $a) {
            if ($a instanceof PayCost && $a->pay) {
                $pay ??= $a;
            } elseif ($a instanceof PayCost) {
                $refuse ??= $a;
            }
        }
        if ($refuse !== null && $state->topOfStack()?->trigger?->effect === 'pay_untap') {
            return $refuse; // Mana Vault : payer {4} pour la dégager rapporte {C}{C}{C}, une perte nette d'un mana
        }
        if ($state->topOfStack()?->trigger?->effect === 'cumulative_upkeep' && $refuse !== null) {
            // Mystic Remora : garder tant que le total reste ≤ CUMULATIVE_UPKEEP_MAX (seuil non calibré).
            return $pay !== null && ManaCost::parse($pay->cost)->manaValue() <= self::CUMULATIVE_UPKEEP_MAX ? $pay : $refuse;
        }
        if ($pay === null || $refuse === null || $state->topOfStack()?->trigger?->effect !== 'draw_unless') {
            return $legal[0] ?? AutoPassAgent::pass($legal, $playerId);
        }
        $reserve = $this->reservedCounter($state, $playerId);
        if ($reserve === null) {
            return $pay;
        }
        $after = $state->player($playerId)->duplicate();
        $plan = ManaPayment::plan($after, ManaCost::parse($pay->cost), 0, $state->turn);
        if ($plan === null) {
            return $refuse;
        }
        $after->payWithPlan($plan);
        $counter = $after->findCastable($reserve->id);

        return $counter !== null && $this->canPayCounter($state, $after, $counter) ? $pay : $refuse;
    }

    /**
     * Sélection de cartes. Brainstorm : remettre la carte que l'on défausserait (`discard` : terrain en surplus, carte
     * injouable, la plus chère ; contresorts gardés). Ponder : les cartes utiles d'abord (terrain s'il en manque, sort dont le
     * coût est à portée), dans l'ordre de la bibliothèque ; si aucune ne l'est, mélanger.
     *
     * @param list<Action> $legal
     */
    private function selectionDecision(GameState $state, int $playerId, array $legal): Action
    {
        $choices = array_values(array_filter($legal, static fn (Action $a) => $a instanceof ResolutionChoice));
        if ($choices !== []) {
            return $this->resolutionDecision($state, $playerId, $choices);
        }
        // Mox Diamond : défausser le terrain le plus en double dans la main (le premier à égalité) plutôt que perdre le Mox.
        // Mockingbird : la créature copiable la plus forte (valeur de mana + force), sinon telle quelle.
        $copies = array_values(array_filter($legal, static fn (Action $a) => $a instanceof ReplaceChoice && $a->copyId !== null));
        if ($copies !== []) {
            $score = static fn (ReplaceChoice $r) => ($c = $state->findPermanent((string) $r->copyId)) === null ? -1 : $c->manaCost()->manaValue() + $c->currentPower();
            usort($copies, static fn (ReplaceChoice $a, ReplaceChoice $b) => $score($b) <=> $score($a));

            return $copies[0];
        }
        $replaces = array_values(array_filter($legal, static fn (Action $a) => $a instanceof ReplaceChoice && $a->discardId !== null));
        if ($replaces !== []) {
            $hand = $state->player($playerId)->hand;
            $copies = array_count_values(array_map(static fn (CardInstance $c) => $c->name, array_filter($hand, static fn (CardInstance $c) => $c->isLand())));
            $best = $replaces[0];
            foreach ($replaces as $r) {
                $name = $state->player($playerId)->findInHand((string) $r->discardId)?->name;
                $bestName = $state->player($playerId)->findInHand((string) $best->discardId)?->name;
                if (($copies[$name] ?? 0) > ($copies[$bestName] ?? 0)) {
                    $best = $r;
                }
            }

            return $best;
        }
        $putBacks = array_values(array_filter($legal, static fn (Action $a) => $a instanceof PutBack));
        if ($putBacks !== []) {
            return $this->discard($state, $putBacks, $playerId);
        }
        $arranges = array_values(array_filter($legal, static fn (Action $a) => $a instanceof ArrangeTop));
        if ($arranges === []) {
            return AutoPassAgent::pass($legal, $playerId);
        }
        $p = $state->player($playerId);
        $lands = count(array_filter([...$p->battlefield, ...$p->hand], static fn (CardInstance $c) => $c->isLand()));
        $potential = $this->manaPotential($p, $p->battlefield) + count(array_filter($p->hand, static fn (CardInstance $c) => $c->isLand()));
        $useful = [];
        $rest = [];
        foreach (array_slice($p->library, 0, $state->selection['count'] ?? 0) as $card) {
            $good = $card->isLand() ? $lands < 5 : $card->cost <= $potential + 1;
            if ($good) {
                $useful[] = $card->id;
            } else {
                $rest[] = $card->id;
            }
        }
        $order = [...$useful, ...$rest];
        foreach ($arranges as $a) {
            if ($useful === [] ? $a->shuffle : (!$a->shuffle && $a->order === $order)) {
                return $a;
            }
        }

        return $arranges[0];
    }

    /**
     * Décisions de l'étape 14 (heuristiques simples, non calibrées ; les combos sont l'affaire de l'étape 16) : Ad Nauseam continue
     * tant que ses PV dépassent `AD_NAUSEAM_LIFE_FLOOR` ; Tainted Pact prend la première carte non-terrain ; Thassa's Oracle et
     * Lim-Dûl's Vault gardent dessus la première carte non-terrain regardée (le Vault ne paie jamais de PV) ; Scheming Symmetry :
     * la carte la plus forte (`cardValue`) ; Beseech the Mirror : la lancer.
     *
     * @param list<ResolutionChoice> $choices
     */
    private function resolutionDecision(GameState $state, int $playerId, array $choices): ResolutionChoice
    {
        $p = $state->player($playerId);
        $by = static fn (string $choice): ?ResolutionChoice => array_values(array_filter($choices, static fn (ResolutionChoice $c) => $c->choice === $choice))[0] ?? null;
        switch ($state->selection['kind'] ?? '') {
            case 'ad_nauseam':
                return ($p->life > self::AD_NAUSEAM_LIFE_FLOOR ? $by('continue') : null) ?? $by('stop') ?? $choices[0];
            case 'tainted_pact':
                $card = ResolutionChoice::find($p->exile, (string) ($state->selection['cardId'] ?? ''));

                return ($card !== null && $card->isLand() ? $by('continue') : null) ?? $choices[0];
            case 'oracle':
            case 'vault':
                foreach ($choices as $c) {
                    $card = ResolutionChoice::find($p->library, $c->cardId());
                    if ($card !== null && !$card->isLand()) {
                        return $c;
                    }
                }

                return $choices[0];
            case 'symmetry':
                $best = $choices[0];
                $score = -1;
                foreach ($choices as $c) {
                    $card = ResolutionChoice::find($p->library, $c->cardId());
                    if ($card !== null && $this->cardValue($card, 0) > $score) {
                        [$best, $score] = [$c, $this->cardValue($card, 0)];
                    }
                }

                return $best;
            default:
                return $choices[0];
        }
    }

    /** Scry : garder sur le dessus les cartes qui ne sont pas des terrains (dans l'ordre), mettre les terrains dessous. */
    private function scryDecision(GameState $state, int $playerId, array $legal): Action
    {
        $scries = array_values(array_filter($legal, static fn (Action $a) => $a instanceof ScryArrange));
        $count = $scries[0]->count;
        $keep = [];
        foreach (array_slice($state->player($playerId)->library, 0, $count) as $card) {
            if (!$card->isLand()) {
                $keep[] = $card->id;
            }
        }
        foreach ($scries as $scry) {
            if ($scry->kept === $keep) {
                return $scry;
            }
        }

        return $scries[0];
    }

    /** Tuteur : la carte de la bibliothèque la plus forte (`cardValue`) ; une seule cible par sort (la première à égalité). */
    private function bestTutorTargets(GameState $state, int $playerId, array $casts): array
    {
        $isTutor = static fn (CastSpell $c) => $state->player($playerId)->findCastable($c->cardId)?->hasEffect('tutor') === true;
        $best = [];
        foreach ($casts as $c) {
            if (!$isTutor($c)) {
                continue;
            }
            $found = null;
            foreach ($state->player($playerId)->library as $card) {
                if ('library:' . $card->id === ($c->targets[0] ?? '')) {
                    $found = $card;
                }
            }
            $score = $found === null ? -1 : $this->cardValue($found, 0);
            if (!isset($best[$c->cardId]) || $score > $best[$c->cardId][1]) {
                $best[$c->cardId] = [$c, $score];
            }
        }

        return array_values(array_filter($casts, static fn (CastSpell $c) => !$isTutor($c) || ($best[$c->cardId][0] ?? null) === $c));
    }

    /** @return array{0: int, 1: CardInstance}|null contrôleur et permanent visés par `permanent:<id>` */
    private function targetPermanent(GameState $state, string $target): ?array
    {
        if (!str_starts_with($target, 'permanent:')) {
            return null;
        }
        foreach ($state->players as $p) {
            if (($card = $p->findOnBattlefield(substr($target, 10))) !== null) {
                return [$p->id, $card];
            }
        }

        return null;
    }

    private function ability(GameState $state, ActivateAbility $a): ?ActivatedAbility
    {
        return $this->battlefieldCard($state, $a->player(), $a->cardId)?->abilities()[$a->abilityIndex] ?? null;
    }

    private function playerTarget(ActivateAbility $a): ?int
    {
        $t = $a->targets[0] ?? null;

        return $t !== null && str_starts_with($t, 'player:') ? (int) substr($t, 7) : null;
    }

    /**
     * Évaluateur de menace d'un élément de pile adverse : ce qui me vise (ou protège mon sort d'un contresort adverse)
     * pèse lourd ; un rituel, un jeton de mana ou un sort bon marché (valeur de mana + dégâts < 3) qui ne me vise pas ne vaut pas
     * un contresort. Sinon la valeur de mana (+ les dégâts), +1 pour une créature, +1 pour une créature de force >= 3.
     */
    private function threat(GameState $state, int $playerId, \App\Engine\Game\StackItem $item): int
    {
        if ($item->isAbility()) {
            return 0;
        }
        if (in_array('player:' . $playerId, $item->targets, true)) {
            return 10;
        }
        // Un sort qui exile un de mes permanents : le contrer le sauve.
        if (($this->targetPermanent($state, $item->targets[0] ?? '')[0] ?? null) === $playerId) {
            return 8;
        }
        $card = $item->card;
        if ($card->hasEffect('counter')) {
            // Un contresort adverse qui vise un de mes sorts : le contrer protège mon sort.
            foreach ($item->targets as $t) {
                if (str_starts_with($t, 'stack:')) {
                    foreach ($state->stack as $other) {
                        if ('stack:' . $other->id === $t && $other->controller === $playerId) {
                            return 8;
                        }
                    }
                }
            }

            return 0;
        }
        if ($card->hasEffect('mana') || $card->hasEffect('treasure')) {
            return 0;
        }

        return $this->cardValue($card, $item->x);
    }

    /**
     * Garde les sorts qui laissent le moins de mana inutilisé dans la réserve (Sol Ring payant un `{1}` laisse 1 mana qui
     * sera perdu à la fin de l'étape). Pour chaque carte, on simule le paiement puis, en regardant un sort de plus, le
     * mana qu'il resterait au mieux une fois la réserve réutilisée par un second sort. Les sorts à X n'entrent pas dans
     * le calcul (le X maximal absorbe le surplus). Sans simulation possible, on garde tout.
     *
     * @param list<CastSpell> $casts
     * @return list<CastSpell>
     */
    private function leastWasteful(GameState $state, int $playerId, array $casts): array
    {
        $p = $state->player($playerId);
        $cards = [];
        foreach ($casts as $c) {
            $cards[$c->cardId] = $p->findCastable($c->cardId);
        }
        if (count($cards) < 2) {
            return $casts;
        }
        $score = [];
        foreach ($cards as $id => $card) {
            if ($card === null || $card->hasEffect('mana')) {
                $score[$id] = 0;
                continue;
            }
            $copy = $this->afterCast($state, $p, $card);
            if ($copy === null) {
                $score[$id] = 0;
                continue;
            }
            $best = $copy->pool->total();
            foreach ($cards as $id2 => $card2) {
                if ($id2 === $id || $card2 === null || $card2->hasEffect('mana')) {
                    continue;
                }
                $second = $this->afterCast($state, $copy, $card2);
                if ($second !== null) {
                    $best = min($best, $second->pool->total());
                }
            }
            $score[$id] = $best;
        }
        $min = min($score);

        return array_values(array_filter($casts, static fn (CastSpell $c) => $score[$c->cardId] === $min));
    }

    /**
     * Copie de l'état du joueur après avoir payé `$card` (sans effet de bord) ; null si inpayable ou si le coût a un {X} sans `$x`.
     *
     * @return PlayerState|null
     */
    private function afterCast(GameState $state, PlayerState $p, CardInstance $card, ?int $x = null): ?PlayerState
    {
        $copy = $p->duplicate();
        $copyCard = $copy->findCastable($card->id);
        if ($copyCard === null) {
            return null;
        }
        $cost = $copy->castCost($copyCard);
        if ($cost->hasX() && $x === null) {
            return null;
        }
        $plan = ManaPayment::plan($copy, $cost, $x ?? 0, $state->turn, null, $copyCard);
        if ($plan === null) {
            return null;
        }
        $copy->payWithPlan($plan);

        return $copy;
    }

    /**
     * Garder de quoi contrer : si la main contient un contresort payable maintenant, on n'en lance pas d'autre sort que ceux
     * qui laissent assez de mana (réserve et sources non engagées) pour payer le moins cher des contresorts. Exceptions : un
     * rituel (il ajoute du mana), et une rafale de dégâts létale pour un joueur (ne rien faire serait pire). Si le contresort
     * n'est pas payable de toute façon (couleur absente, sources déjà engagées), la réserve est sans objet et rien n'est filtré.
     *
     * @param list<CastSpell> $casts
     * @return list<CastSpell>
     */
    private function keepCounterMana(GameState $state, int $playerId, array $casts): array
    {
        $reserve = $this->reservedCounter($state, $playerId);
        if ($reserve === null) {
            return $casts;
        }
        $p = $state->player($playerId);
        $kept = [];
        $waste = [];
        foreach ($casts as $c) {
            $card = $p->findCastable($c->cardId);
            if ($card === null || $card->hasEffect('mana') || $this->isLethalBurn($state, $card, $c) || $this->isGameChanging($card, $c)) {
                $kept[] = $c;
                $waste[] = 0;
                continue;
            }
            $after = $this->afterCast($state, $p, $card, $c->x);
            $lost = $after === null ? null : $this->counterWaste($state, $after, $reserve);
            if ($lost !== null) {
                $kept[] = $c;
                $waste[] = $lost;
            }
        }
        if ($kept === []) {
            return [];
        }
        // Parmi les sorts qui gardent le contresort, ceux qui laissent de quoi le payer sans perte (une Île plutôt
        // qu'un Signet, dont le mana en trop serait perdu).
        $min = min($waste);

        return array_values(array_filter($kept, static fn (CastSpell $c, int $i) => $waste[$i] === $min, ARRAY_FILTER_USE_BOTH));
    }

    /** Sort qui change la partie : sa valeur (comme `threat()`) atteint `hugeThreat` ; on le lance même sans garder le contresort. */
    private function isGameChanging(CardInstance $card, CastSpell $cast): bool
    {
        return ($card->effects === [] || $card->hasEffect('damage')) && $this->cardValue($card, $cast->x) >= $this->hugeThreat;
    }

    /** Valeur d'un sort : valeur de mana (+ X, + dégâts), +1 pour une créature, +1 pour une créature de force >= 3. */
    private function cardValue(CardInstance $card, int $x): int
    {
        return $card->cost + $x + ($card->effect('damage')?->amount ?? 0) + ($card->isCreature() ? 1 : 0) + ($card->isCreature() && $card->power >= 3 ? 1 : 0);
    }

    /** Mana qui serait perdu en payant `$counter` avec l'état `$after` (production d'un Signet en trop) ; null si impayable. */
    private function counterWaste(GameState $state, PlayerState $after, CardInstance $counter): ?int
    {
        $copy = $after->duplicate();
        $copyCounter = $copy->findCastable($counter->id);
        $plan = $copyCounter === null ? null : ManaPayment::plan($copy, $copy->castCost($copyCounter), 0, $state->turn, null, $copyCounter);
        if ($plan === null) {
            return null;
        }
        $before = $copy->pool->total();
        $copy->payWithPlan($plan);

        return max(0, $copy->pool->total() - $before);
    }

    /**
     * Paiement qui épargne le contresort : si le paiement automatique de `$cast` ne laisse de quoi payer le contresort réservé
     * qu'en perdant du mana (Signet à la place d'une Île), alors qu'un paiement sur les autres sources existe, renvoie la
     * prochaine capacité de mana de ce paiement à activer à la main (le sort sera lancé ensuite, payé par la réserve).
     * Null : lancer directement. La réserve courante est ignorée pour le contresort (elle se vide à la fin de l'étape).
     *
     * @param list<Action> $legal
     */
    private function manaSparingCounter(GameState $state, int $playerId, CastSpell $cast, array $legal): ?ActivateAbility
    {
        $p = $state->player($playerId);
        $card = $p->findCastable($cast->cardId);
        $counter = $this->reservedCounter($state, $playerId);
        if ($card === null || $counter === null || $card->hasEffect('mana')) {
            return null;
        }
        $after = $this->afterCast($state, $p, $card, $cast->x);
        if ($after === null) {
            return null;
        }
        $after->pool->clear();
        if ($this->counterWaste($state, $after, $counter) === 0) {
            return null;
        }
        // Sources du contresort mises de côté (engagées sur une copie), puis paiement du sort sur le reste.
        $spared = $p->duplicate();
        $spared->pool->clear();
        $counterPlan = ManaPayment::plan($spared, $spared->castCost($counter), 0, $state->turn, null, $counter);
        if ($counterPlan === null) {
            return null;
        }
        $spared = $p->duplicate();
        foreach ([...$counterPlan->taps, ...$counterPlan->converters] as $use) {
            $spared->findOnBattlefield($use['card'])->tapped = true;
        }
        $spellCard = $spared->findCastable($card->id);
        $plan = ManaPayment::plan($spared, $spared->castCost($spellCard), $cast->x, $state->turn, null, $spellCard);
        $next = $plan->taps[0] ?? $plan?->converters[0] ?? null;
        if ($next === null) {
            return null;
        }
        foreach ($legal as $a) {
            if ($a instanceof ActivateAbility && $a->mana && $a->cardId === $next['card']
                && $a->abilityIndex === $next['ability'] && $a->option === $next['option']) {
                return $a;
            }
        }

        return null;
    }

    /** Idem pour une capacité à coût de mana (jetons, pioche) : jamais si elle prive l'IA de son contresort. */
    private function abilityKeepsCounterMana(GameState $state, int $playerId, ActivateAbility $action, ActivatedAbility $ability): bool
    {
        $reserve = $this->reservedCounter($state, $playerId);
        if ($reserve === null) {
            return true;
        }
        $copy = $state->player($playerId)->duplicate();
        if ($ability->manaCost !== null && !$ability->manaCost->hasX()) {
            $plan = ManaPayment::plan($copy, $ability->manaCost, 0, $state->turn, $action->cardId);
            if ($plan === null) {
                return true;
            }
            $copy->payWithPlan($plan);
        }
        if ($ability->tap && ($source = $copy->findOnBattlefield($action->cardId)) !== null) {
            $source->tapped = true;
        }

        return $this->canPayCounter($state, $copy, $reserve);
    }

    /** Le moins cher des contresorts de la main, s'il est payable avec l'état actuel ; sinon null. */
    private function reservedCounter(GameState $state, int $playerId): ?CardInstance
    {
        if (!$this->keepCounterMana) {
            return null;
        }
        $p = $state->player($playerId);
        $best = null;
        foreach ($p->hand as $card) {
            if ($card->hasEffect('counter') && !$card->hasEffect('pact') && ($best === null || $card->cost < $best->cost)) {
                $best = $card;
            }
        }
        if ($best === null || ManaPayment::plan($p, $p->castCost($best), 0, $state->turn, null, $best) === null) {
            return null;
        }

        return $best;
    }

    private function canPayCounter(GameState $state, PlayerState $after, CardInstance $counter): bool
    {
        return ManaPayment::plan($after, $after->castCost($counter), 0, $state->turn, null, $counter) !== null;
    }

    /** Dégâts qui tuent un joueur vivant (X compris) : on les lance même en vidant le mana du contresort. */
    private function isLethalBurn(GameState $state, CardInstance $card, CastSpell $cast): bool
    {
        if (!$card->hasEffect('damage')) {
            return false;
        }
        foreach ($cast->targets as $t) {
            if (str_starts_with($t, 'player:') && $state->player((int) substr($t, 7))->life <= ($card->effect('damage')?->amount ?? 0) + $cast->x) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un rituel (effet `mana`) n'est lancé que s'il rend payable un sort non-rituel de la main (ou du commandement)
     * qui ne l'était pas. Simulation sur une copie de l'état du joueur : le rituel est payé, puis son mana ajouté.
     */
    private function worthCasting(GameState $state, int $playerId, CastSpell $cast): bool
    {
        $p = $state->player($playerId);
        $ritual = $p->findCastable($cast->cardId);
        if ($ritual === null || !$ritual->hasEffect('mana')) {
            return true;
        }
        $copy = $p->duplicate();
        $copyRitual = $copy->findCastable($cast->cardId);
        $plan = ManaPayment::plan($copy, $copy->castCost($copyRitual), 0, $state->turn, null, $copyRitual);
        if ($plan === null) {
            return false;
        }
        $copy->payWithPlan($plan);
        foreach (ManaCost::parse($ritual->effect('mana')?->adds ?? '')->colored as $symbol => $n) {
            $copy->pool->add(\App\Engine\Mana\Color::fromSymbol($symbol), $n);
        }
        // Avant : sans le rituel (état d'origine) ; après : réserve augmentée.
        $before = $p->duplicate();
        foreach ([...$p->hand, ...$p->command] as $card) {
            if ($card->isLand() || $card->hasEffect('mana') || $card->id === $ritual->id) {
                continue;
            }
            $cost = $p->castCost($card);
            if (ManaPayment::plan($before, $cost, 0, $state->turn, null, $card) === null && ManaPayment::plan($copy, $cost, 0, $state->turn, null, $card) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Règle des légendes : garde le permanent dégagé (il peut encore servir ce tour), à défaut le plus ancien sur le champ
     * de bataille (premier proposé à égalité).
     *
     * @param non-empty-list<KeepLegend> $legends
     */
    private function keepLegend(GameState $state, array $legends, int $playerId): KeepLegend
    {
        $best = $legends[0];
        $bestKey = null;
        foreach ($legends as $action) {
            $card = $state->player($playerId)->findOnBattlefield($action->cardId);
            $key = [$card?->tapped ? 1 : 0, $card?->enteredTurn ?? PHP_INT_MAX];
            if ($bestKey === null || $key < $bestKey) {
                [$best, $bestKey] = [$action, $key];
            }
        }

        return $best;
    }

    /**
     * Premier trigger de la file ; s'il cible, l'adversaire vivant le plus faible (égalité : plus petit id), jamais soi-même
     * s'il y a un autre choix.
     *
     * @param list<PutTrigger> $triggers
     */
    private function putTrigger(GameState $state, array $triggers, int $playerId): PutTrigger
    {
        $first = $triggers[0]->pendingId;
        foreach ($state->pendingTriggers as $pending) {
            if ($pending->id === $first && $pending->ability()->choosesHandCard()) {
                return $this->imprintChoice($state, $triggers, $playerId);
            }
        }
        $best = null;
        foreach ($triggers as $t) {
            if ($t->pendingId !== $first) {
                continue;
            }
            if ($t->targets === []) {
                return $t;
            }
            if (!str_starts_with($t->targets[0], 'player:')) {
                // Tuteur à l'entrée (Ranger-Captain of Eos) : la carte de la bibliothèque la plus forte ; rien à chercher : tel quel.
                $found = null;
                foreach ($state->player($playerId)->library as $c) {
                    if ('library:' . $c->id === $t->targets[0]) {
                        $found = $c;
                    }
                }
                $score = $found === null ? -1 : $this->cardValue($found, 0);
                if ($best === null || $score > $best[1]) {
                    $best = [$t, $score];
                }
                continue;
            }
            $target = (int) substr($t->targets[0], 7);
            $score = [$target === $playerId ? 1 : 0, $state->player($target)->life, $target];
            if ($best === null || $score < $best[1]) {
                $best = [$t, $score];
            }
        }

        return $best[0];
    }

    /**
     * Imprint (Chrome Mox) : exiler la carte de la main la moins chère dont une couleur sert à lancer une AUTRE carte non-terrain
     * de la main (le Mox paiera alors cette couleur), jamais un contresort ni une carte incolore, et seulement si la main garde
     * au moins deux autres cartes non-terrain. Sinon ne rien exiler (l'action sans cible). Égalité : première de la main.
     *
     * @param list<PutTrigger> $triggers
     */
    private function imprintChoice(GameState $state, array $triggers, int $playerId): PutTrigger
    {
        $hand = $state->player($playerId)->hand;
        $spells = array_values(array_filter($hand, static fn (CardInstance $c) => !$c->isLand()));
        $decline = null;
        $best = null;
        foreach ($triggers as $t) {
            $id = str_starts_with($t->targets[0] ?? '', 'card:') ? substr($t->targets[0], 5) : null;
            if ($id === null) {
                $decline = $t;
                continue;
            }
            $card = null;
            foreach ($hand as $c) {
                if ($c->id === $id) {
                    $card = $c;
                }
            }
            if ($card === null || $card->hasEffect('counter') || $card->colors() === [] || count($spells) < 3) {
                continue;
            }
            $serves = false;
            foreach ($spells as $other) {
                if ($other->id !== $card->id && array_intersect($card->colors(), $other->colors()) !== []) {
                    $serves = true;
                }
            }
            if ($serves && ($best === null || $this->cardValue($card, 0) < $best[1])) {
                $best = [$t, $this->cardValue($card, 0)];
            }
        }

        return $best[0] ?? $decline ?? $triggers[0];
    }

    /**
     * Avant-partie : garder 2 à 5 terrains ; sinon mulligan tant que moins de 2 pris ; mise au fond = classement de la défausse.
     *
     * @param list<Action> $legal
     */
    private function mulliganDecision(GameState $state, array $legal, int $playerId): Action
    {
        $bottoms = array_values(array_filter($legal, static fn (Action $a) => $a instanceof BottomCard));
        if ($bottoms !== []) {
            return $this->discard($state, $bottoms, $playerId);
        }
        // Gemstone Caverns : toujours en jeu (un mana de plus dès le premier tour), en exilant la carte qu'elle défausserait.
        $pregame = array_values(array_filter($legal, static fn (Action $a) => $a instanceof PregameChoice && $a->cardId !== null));
        if ($pregame !== []) {
            return $this->discard($state, $pregame, $playerId);
        }
        $lands = count(array_filter($state->player($playerId)->hand, static fn (CardInstance $c) => $c->isLand()));
        $taken = $state->mulligan[$playerId]['taken'] ?? 0;
        $wanted = ($lands < 2 || $lands > 5) && $taken < 2 ? Mulligan::class : KeepHand::class;
        foreach ($legal as $action) {
            if ($action instanceof $wanted) {
                return $action;
            }
        }

        return $legal[0];
    }

    /**
     * Défausse d'une carte (la décision est reprise carte par carte). Sert aussi à choisir les cartes à mettre au fond
     * après un mulligan (aucun terrain en jeu : un terrain n'y est en surplus qu'à partir de 5 en main). Score le plus haut défaussé en premier :
     *  1. terrain en surplus (>= 5 en jeu, ou >= 3 en jeu et >= 3 en main) : 1000, +5 s'il n'apporte aucune couleur nouvelle ;
     *  2. carte injouable : couleur que ni le jeu ni les terrains en main ne produisent (avec au moins 3 terrains en jeu, avant
     *     cela les couleurs ne sont pas fixées), ou valeur de mana à plus de 3 au dessus du mana potentiel (sources en jeu +
     *     terrains en main, au moins 4) : 800 ;
     *  3. sinon la carte la plus chère (sa valeur de mana) ;
     *  4. on garde en dernier : un terrain utile (-1, ou -1000 s'il y en a moins de 4 en jeu) et un contresort (-500).
     * Égalité : plus petit id.
     *
     * @param list<Discard|BottomCard|PutBack|PregameChoice> $discards
     */
    private function discard(GameState $state, array $discards, int $playerId): Action
    {
        $p = $state->player($playerId);
        $handLandCards = array_values(array_filter($p->hand, static fn (CardInstance $c) => $c->isLand()));
        $lands = count(array_filter($p->battlefield, static fn (CardInstance $c) => $c->isLand()));
        $handLands = count($handLandCards);
        $produced = $this->producedSymbols($p, $p->battlefield);
        $reachable = $this->producedSymbols($p, [...$p->battlefield, ...$handLandCards]);
        $potential = $this->manaPotential($p, $p->battlefield) + $handLands;
        $best = null;
        foreach ($discards as $d) {
            $card = $p->findInHand($d->cardId);
            if ($card->isLand()) {
                if ($lands >= 5 || ($lands >= 3 && $handLands >= 3) || ($state->mulligan !== null && $handLands >= 5)) {
                    $score = 1000 + (array_diff_key($this->producedSymbols($p, [$card]), $produced) === [] ? 5 : 0);
                } else {
                    $score = $lands < 4 ? -1000 : -1;
                }
            } else {
                $cost = $card->manaCost();
                $impossible = $lands >= 3 && array_diff_key($cost->colored, $reachable) !== [];
                if ($impossible || $cost->generic + array_sum($cost->colored) + count($cost->hybrid) > max($potential, 4) + 3) {
                    $score = 800;
                } else {
                    $score = $card->hasEffect('counter') ? -500 : $card->cost;
                }
            }
            if ($best === null || $score > $best[1] || ($score === $best[1] && $card->id < $best[0]->cardId)) {
                $best = [$d, $score];
            }
        }

        return $best[0];
    }

    /**
     * Symboles (WUBRGC) que ces permanents savent produire (options permises de leurs capacités de mana : une capacité
     * dynamique, Chrome Mox ou Fellwar Stone, ne compte que ses couleurs du moment ; un terrain en main compte via `abilities()`).
     *
     * @param iterable<CardInstance> $cards
     * @return array<string, true>
     */
    private function producedSymbols(PlayerState $p, iterable $cards): array
    {
        $set = [];
        foreach ($cards as $card) {
            foreach ($card->abilities() as $ability) {
                if ($ability->isManaAbility) {
                    foreach (AbilityManager::allowedOptions($p, $card, $ability) as $i) {
                        foreach ($ability->options[$i] as $color) {
                            $set[$color->symbol()] = true;
                        }
                    }
                }
            }
        }

        return $set;
    }

    /**
     * Mana qu'ils peuvent produire en un tour : somme, par permanent, de sa meilleure option (Sol Ring : 2).
     *
     * @param iterable<CardInstance> $cards
     */
    private function manaPotential(PlayerState $p, iterable $cards): int
    {
        $total = 0;
        foreach ($cards as $card) {
            $best = 0;
            foreach ($card->abilities() as $ability) {
                if ($ability->isManaAbility) {
                    foreach (AbilityManager::allowedOptions($p, $card, $ability) as $i) {
                        $best = max($best, count($ability->options[$i]));
                    }
                }
            }
            $total += $best;
        }

        return $total;
    }

    /** @param list<Action> $legal */
    private function declareAttackers(GameState $state, array $legal, int $playerId): Action
    {
        // Les créatures à mana (Llanowar Elves) restent à la maison : leur mana vaut plus que leur attaque. Une créature
        // sans force (jeton Kobold 0/1) aussi.
        // Le commandant n'attaque pas s'il peut mourir au blocage (même en échange : le relancer coûte la taxe). En
        // danger de mort au prochain tour, une créature reste en garde (voir `guard`).
        $guard = $this->guard($state, $playerId);
        $attacks = array_values(array_filter($legal, fn (Action $a) => $a instanceof DeclareAttacker
            && $a->cardId !== $guard
            && !$this->hasManaAbility($card = $this->battlefieldCard($state, $playerId, $a->cardId))
            && ($card?->currentPower() ?? 0) > 0
            && !$this->isSuicideAttack($state, $card, $a->defender)
            && !($card->commander && $this->canDieBlocked($state, $card, $a->defender))));
        if ($attacks === []) {
            return $this->finish($legal, $playerId);
        }
        $target = $this->attackTarget($state, $attacks);
        foreach ($attacks as $a) {
            if ($a->defender === $target) {
                return $a;
            }
        }

        return $this->finish($legal, $playerId);
    }

    /**
     * Adversaire visé : d'abord celui que l'attaque peut tuer (force des attaquants possibles contre lui + celle des
     * créatures qui l'attaquent déjà >= ses PV), puis le plus menaçant (`boardThreat`), puis le plus faible en PV, puis le
     * plus petit id. Le choix reste stable d'une déclaration à l'autre : la cible létale le reste, et la menace ne dépend
     * pas des attaquants.
     *
     * @param list<DeclareAttacker> $attacks
     */
    private function attackTarget(GameState $state, array $attacks): int
    {
        $power = [];
        foreach ($attacks as $a) {
            $power[$a->defender][$a->cardId] = max(0, $this->battlefieldCard($state, $a->player(), $a->cardId)?->currentPower() ?? 0);
        }
        $best = null;
        foreach ($power as $defender => $byCard) {
            $total = array_sum($byCard);
            foreach ($state->combat->attackers as $attackerId => $attacked) {
                if ($attacked === $defender) {
                    $total += max(0, $this->battlefieldCard($state, $state->activePlayer, (string) $attackerId)?->currentPower() ?? 0);
                }
            }
            $life = $state->player($defender)->life;
            $score = [$total >= $life ? 0 : 1, -array_sum($byCard), $this->targetByThreat ? -$this->boardThreat($state, $defender) : 0, $life, $defender];
            if ($best === null || $score < $best[1]) {
                $best = [$defender, $score];
            }
        }

        return $best[0];
    }

    /** Menace d'un joueur sur la table : somme des valeurs (`cardValue`) de ses permanents non-terrains. */
    private function boardThreat(GameState $state, int $playerId): int
    {
        $threat = 0;
        foreach ($state->player($playerId)->battlefield as $card) {
            if (!$card->isLand()) {
                $threat += $this->cardValue($card, 0);
            }
        }

        return $threat;
    }

    /**
     * Créature gardée à la maison pour bloquer : seulement si la force cumulée d'un adversaire (créatures sans défenseur)
     * atteint mes PV et qu'aucune autre créature capable de bloquer ne reste déjà (créature à mana, sans force, ou avec
     * vigilance). On garde la plus grande endurance (égalité : la première du champ de bataille). Null sinon.
     */
    private function guard(GameState $state, int $playerId): ?string
    {
        if ($this->guardFactor === 0) {
            return null;
        }
        $me = $state->player($playerId);
        $danger = false;
        foreach ($state->players as $opponent) {
            if ($opponent->id === $playerId || $opponent->lost) {
                continue;
            }
            $power = 0;
            foreach ($opponent->battlefield as $c) {
                if ($c->isCreature() && !$c->hasKeyword(CardInstance::DEFENDER)) {
                    $power += max(0, $c->currentPower());
                }
            }
            $danger = $danger || $power * $this->guardFactor >= $me->life;
        }
        if (!$danger) {
            return null;
        }
        $best = null;
        foreach ($me->battlefield as $c) {
            if (!$c->isCreature() || isset($state->combat->attackers[$c->id])) {
                continue;
            }
            if ($c->tapped) {
                continue;
            }
            if ($this->hasManaAbility($c) || $c->currentPower() <= 0 || $c->hasKeyword(CardInstance::VIGILANCE)) {
                return null;
            }
            if ($best === null || $c->currentToughness() > $best->currentToughness()) {
                $best = $c;
            }
        }

        return $best?->id;
    }

    /** Vrai si un bloqueur possible du défenseur tuerait cet attaquant (échange compris ; vol et portée pris en compte). */
    private function canDieBlocked(GameState $state, CardInstance $attacker, int $defender): bool
    {
        foreach ($state->player($defender)->battlefield as $blocker) {
            if ($blocker->isCreature() && !$blocker->tapped
                && (!$attacker->hasKeyword(CardInstance::FLYING) || $blocker->hasKeyword(CardInstance::FLYING) || $blocker->hasKeyword(CardInstance::REACH))
                && $this->duel($attacker, $blocker)[1]) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Action> $legal */
    private function declareBlockers(GameState $state, array $legal, int $playerId): Action
    {
        $incoming = 0;
        foreach ($state->combat->attackers as $attackerId => $defender) {
            if ($defender === $playerId && ($attacker = $this->battlefieldCard($state, $state->activePlayer, $attackerId)) !== null) {
                $incoming += $attacker->currentPower();
            }
        }
        $lethal = $incoming >= $state->player($playerId)->life;

        $chump = null;
        foreach ($legal as $a) {
            if (!$a instanceof DeclareBlocker || isset($state->combat->blockers[$a->attackerId])) {
                continue;
            }
            $attacker = $this->battlefieldCard($state, $state->activePlayer, $a->attackerId);
            $blocker = $this->battlefieldCard($state, $playerId, $a->cardId);
            if ($attacker === null || $blocker === null) {
                continue;
            }
            [$survives, $kills] = $this->duel($attacker, $blocker);
            if ($survives || $kills) {
                return $a;
            }
            // Contre le piétinement, le chump block ne retient que l'endurance du bloqueur.
            $saved = $attacker->hasKeyword(CardInstance::TRAMPLE)
                ? min($attacker->currentPower(), $blocker->currentToughness() - $blocker->damage)
                : $attacker->currentPower();
            if ($lethal && !$blocker->commander && ($chump === null || $saved > $chump[1])) {
                $chump = [$a, $saved];
            }
        }
        $double = $this->doubleBlock($state, $legal, $playerId);
        if ($double !== null) {
            return $double;
        }
        if ($chump !== null) {
            return $chump[0];
        }

        return $this->finish($legal, $playerId);
    }

    /**
     * Blocage à deux : un attaquant qu'aucun bloqueur seul ne tue est bloqué par deux créatures qui le tuent ensemble, si
     * l'attaquant ne peut pas tuer les deux et vaut au moins le moins cher des bloqueurs (on accepte d'en perdre un).
     * Jamais avec le commandant, ni contre l'initiative, la double initiative ou le contact mortel (le duel à deux n'est
     * pas simulé). La plus grande endurance est déclarée d'abord (l'attaquant répartit ses dégâts dans l'ordre). Second
     * appel : l'attaquant déjà bloqué par une de mes créatures reçoit le second bloqueur.
     *
     * @param list<Action> $legal
     */
    private function doubleBlock(GameState $state, array $legal, int $playerId): ?DeclareBlocker
    {
        $options = [];
        foreach ($legal as $a) {
            if ($a instanceof DeclareBlocker && ($b = $this->battlefieldCard($state, $playerId, $a->cardId)) !== null && !$b->commander) {
                $options[$a->attackerId][] = [$a, $b];
            }
        }
        foreach ($options as $attackerId => $candidates) {
            $attacker = $this->battlefieldCard($state, $state->activePlayer, (string) $attackerId);
            if ($attacker === null || $attacker->hasKeyword(CardInstance::FIRST_STRIKE) || $attacker->hasKeyword(CardInstance::DOUBLE_STRIKE)
                || $attacker->hasKeyword(CardInstance::DEATHTOUCH)) {
                continue;
            }
            $current = [];
            foreach ($state->combat->blockers[$attackerId] ?? [] as $id) {
                if (($c = $this->battlefieldCard($state, $playerId, $id)) !== null) {
                    $current[] = $c;
                }
            }
            if (count($current) >= 2 || (count($current) === 1 && $this->gangKills($attacker, $current))) {
                continue;
            }
            if (count($current) === 1) {
                foreach ($candidates as [$a, $b]) {
                    if ($this->goodPair($attacker, $current[0], $b)) {
                        return $a;
                    }
                }
                continue;
            }
            foreach ($candidates as $i => [$a1, $b1]) {
                foreach ($candidates as $j => [$a2, $b2]) {
                    if ($i !== $j && $this->goodPair($attacker, $b1, $b2)
                        && $b1->currentToughness() - $b1->damage >= $b2->currentToughness() - $b2->damage) {
                        return $a1;
                    }
                }
            }
        }

        return null;
    }

    private function goodPair(CardInstance $attacker, CardInstance $b1, CardInstance $b2): bool
    {
        $left = static fn (CardInstance $c): int => $c->currentToughness() - $c->damage;

        return $this->gangKills($attacker, [$b1, $b2])
            && $attacker->currentPower() < $left($b1) + $left($b2)
            && $attacker->cost >= min($b1->cost, $b2->cost);
    }

    /** @param list<CardInstance> $blockers Vrai si les bloqueurs tuent ensemble l'attaquant (force cumulée, ou contact mortel). */
    private function gangKills(CardInstance $attacker, array $blockers): bool
    {
        $power = 0;
        foreach ($blockers as $b) {
            if ($b->currentPower() > 0 && $b->hasKeyword(CardInstance::DEATHTOUCH)) {
                return true;
            }
            $power += max(0, $b->currentPower());
        }

        return $power > 0 && $power >= $attacker->currentToughness() - $attacker->damage;
    }

    /**
     * Issue d'un duel attaquant / bloqueur (contact mortel et initiative compris).
     *
     * @return array{bool, bool} [le bloqueur survit, l'attaquant meurt]
     */
    private function duel(CardInstance $attacker, CardInstance $blocker): array
    {
        $kills = static fn (CardInstance $by, CardInstance $on): bool => $by->currentPower() > 0
            && ($by->hasKeyword(CardInstance::DEATHTOUCH) || $by->currentPower() >= $on->currentToughness() - $on->damage);
        $first = static fn (CardInstance $c): bool => $c->hasKeyword(CardInstance::FIRST_STRIKE) || $c->hasKeyword(CardInstance::DOUBLE_STRIKE);
        $blockerDies = $kills($attacker, $blocker);
        $attackerDies = $kills($blocker, $attacker);
        if ($first($attacker) && !$first($blocker) && $blockerDies) {
            $attackerDies = false;
        } elseif ($first($blocker) && !$first($attacker) && $attackerDies) {
            $blockerDies = false;
        }

        return [!$blockerDies, $attackerDies];
    }

    /** Vrai si un bloqueur possible du défenseur tuerait cet attaquant sans mourir (hors vol, piétinement, menace). */
    private function isSuicideAttack(GameState $state, CardInstance $attacker, int $defender): bool
    {
        if ($attacker->hasKeyword(CardInstance::TRAMPLE) || $attacker->hasKeyword(CardInstance::FLYING) || $attacker->hasKeyword(CardInstance::MENACE)) {
            return false;
        }
        foreach ($state->player($defender)->battlefield as $blocker) {
            if ($blocker->isCreature() && !$blocker->tapped) {
                [$survives, $kills] = $this->duel($attacker, $blocker);
                if ($survives && $kills) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sort à X (une CastSpell par valeur de X) : retient la variante au X le plus grand, jamais X=0 si possible.
     *
     * @param list<CastSpell> $casts
     */
    private function withBestX(GameState $state, CastSpell $pick, array $casts): CastSpell
    {
        if (!property_exists($pick, 'x')) {
            return $pick;
        }
        $best = $pick;
        foreach ($casts as $c) {
            if ($c->cardId === $pick->cardId && $c->targets === $pick->targets && $c->x > $best->x) {
                $best = $c;
            }
        }
        if ($best->x === 0) {
            // Seule la variante X=0 existe pour cette carte : on l'évite si une autre action de lancer est possible.
            foreach ($casts as $c) {
                if ($c->x > 0) {
                    return $c;
                }
            }
        }

        return $best;
    }

    /** @param list<Action> $legal */
    private function finish(array $legal, int $playerId): Action
    {
        foreach ($legal as $a) {
            if ($a instanceof FinishDeclaration) {
                return $a;
            }
        }

        return new FinishDeclaration($playerId);
    }

    private function battlefieldCard(GameState $state, int $owner, string $cardId): ?CardInstance
    {
        foreach ($state->player($owner)->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    private function hasManaAbility(?CardInstance $card): bool
    {
        foreach ($card?->abilities() ?? [] as $ability) {
            if ($ability->isManaAbility) {
                return true;
            }
        }

        return false;
    }

    /** Coût alternatif par exil d'une carte de la main (Force of Will) : l'IA ne le paie jamais (deux cartes pour une). */
    /**
     * Contresort à coût alternatif par exil sur `$top` qui coûte le moins : la carte exilée de plus faible valeur de mana, un
     * contresort gardé en dernier recours (égalité : premier proposé). Null s'il n'y en a pas.
     *
     * @param list<Action> $legal
     */
    private function cheapestPitch(GameState $state, int $playerId, array $legal, \App\Engine\Game\StackItem $top): ?CastSpell
    {
        $p = $state->player($playerId);
        $best = null;
        foreach ($legal as $action) {
            if (!$action instanceof CastSpell || !$this->isPitch($action) || !$this->isCounter($state, $action)
                || $action->targets !== ['stack:' . $top->id] || !$this->counterWorks($state, $playerId, $action, $top)) {
                continue;
            }
            $cost = 0;
            foreach (explode(',', substr((string) $action->alt, strlen(CastSpell::ALT_PITCH))) as $id) {
                $card = $p->findInHand($id);
                $cost += ($card?->cost ?? 0) + ($card?->hasEffect('counter') ? 100 : 0);
            }
            if ($best === null || $cost < $best[1]) {
                $best = [$action, $cost];
            }
        }

        return $best[0] ?? null;
    }

    private function isPitch(CastSpell $action): bool
    {
        return str_starts_with((string) $action->alt, CastSpell::ALT_PITCH);
    }

    /**
     * Ce contresort vaut-il d'être lancé sur `$top` ? Non pour un « sauf si son contrôleur paie » (Daze, Mana Leak) que la
     * victime peut payer, ni pour Pact of Negation si l'on ne pourra pas payer {3}{U}{U} au prochain entretien (estimé : au
     * moins autant de mana produisible par ses permanents que le coût, et du bleu). Toujours oui pour un contresort ordinaire.
     */
    private function counterWorks(GameState $state, int $playerId, CastSpell $action, \App\Engine\Game\StackItem $top): bool
    {
        $card = $state->player($playerId)->findCastable($action->cardId);
        $counter = $card?->effect('counter');
        if ($card === null || $counter === null) {
            return true;
        }
        if ($counter->payCost !== null
            && ManaPayment::plan($state->player($top->controller), \App\Engine\Mana\ManaCost::parse($counter->payCost), 0, $state->turn) !== null) {
            return false;
        }
        $pact = $card->effect('pact');
        if ($pact !== null) {
            $p = $state->player($playerId);
            $battlefield = $p->battlefield;
            $cost = \App\Engine\Mana\ManaCost::parse((string) $pact->payCost);

            return $this->manaPotential($p, $battlefield) >= $cost->manaValue() && isset($this->producedSymbols($p, $battlefield)['U']);
        }

        return true;
    }

    private function isCounter(GameState $state, CastSpell $action): bool
    {
        $card = $state->player($action->player())->findCastable($action->cardId);

        return $card?->hasEffect('counter') === true && !$card->isModal(); // Pyroblast : jamais utilisé comme contresort par l'IA
    }

    /**
     * Sorts que l'IA ne lance pas faute de jugement fin (lot H2) : modaux (Pyroblast, Into the Flood Maw), à coût additionnel de
     * sacrifice (Culling the Weak, Diabolic Intent) et de renvoi en main (Snapback, Chain of Vapor), qui pourraient viser ses
     * propres permanents ; lot H3 : changer une cible (Deflecting Swat, Misdirection), prendre un sort (Commandeer), exiler des
     * sorts (Mindbreak Trap).
     */
    private function aiSkips(GameState $state, CastSpell $action): bool
    {
        $card = $state->player($action->player())->findCastable($action->cardId);

        if ($card !== null && $state->replacementFor($card, ReplacementEffect::ENTERS)?->instead === ReplacementEffect::DISCARD_LAND) {
            // Mox Diamond : seulement avec un terrain à perdre (deux en main, ou un avec déjà trois terrains en jeu).
            $p = $state->player($action->player());
            $inHand = count(array_filter($p->hand, static fn (CardInstance $c) => $c->isLand()));
            $inPlay = count(array_filter($p->battlefield, static fn (CardInstance $c) => $c->isLand()));

            return !($inHand >= 2 || ($inHand >= 1 && $inPlay >= 3));
        }

        $bargain = $card?->extraCost === CardInstance::EXTRA_BARGAIN; // Beseech the Mirror : lancé, mais sans rien sacrifier

        return $card !== null && ($card->isModal() || ($card->extraCost !== null && !$bargain) || ($bargain && $action->sacrifice !== null)
            || $card->hasEffect('bounce') || $card->hasEffect('destroy') || $card->hasEffect('grant_sac_mana')
            || $card->hasEffect('redirect') || $card->hasEffect('steal') || $card->hasEffect('exile_spells') || $this->neverCasts($card));
    }

    /**
     * Lot H5b, laissé à l'IA de combo (étape 16) : Final Fortune (perdre la partie), Demonic Consultation et Tainted Pact (exiler sa
     * bibliothèque), Scheming Symmetry (faire chercher un adversaire).
     */
    private function neverCasts(?CardInstance $card): bool
    {
        return $card !== null && ($card->hasEffect('extra_turn') || $card->hasEffect('consult') || $card->hasEffect('tainted_pact') || $card->hasEffect('symmetry'));
    }

    /** La carte d'un lancement, d'où qu'elle vienne : main, zone de commandement, exil jouable ou cimetière (Flashback, Escape). */
    private static function cardOf(GameState $state, CastSpell $cast): ?CardInstance
    {
        return $state->castable($cast->player(), $cast->cardId) ?? ResolutionChoice::find($state->player($cast->player())->graveyard, $cast->cardId);
    }
}
