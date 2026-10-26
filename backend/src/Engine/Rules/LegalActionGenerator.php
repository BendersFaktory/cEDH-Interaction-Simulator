<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Ability\AbilityManager;
use App\Engine\Action\Action;
use App\Engine\Action\ActivateAbility;
use App\Engine\Action\ArrangeTop;
use App\Engine\Action\AssignCombatDamage;
use App\Engine\Action\BottomCard;
use App\Engine\Action\CastSpell;
use App\Engine\Action\ChainCopy;
use App\Engine\Action\Discard;
use App\Engine\Action\DeclareAttacker;
use App\Engine\Action\DeclareBlocker;
use App\Engine\Action\FinishDeclaration;
use App\Engine\Action\KeepHand;
use App\Engine\Action\KeepLegend;
use App\Engine\Action\ScryArrange;
use App\Engine\Action\Mulligan;
use App\Engine\Action\PassPriority;
use App\Engine\Action\PayCost;
use App\Engine\Action\PlayLand;
use App\Engine\Action\PutBack;
use App\Engine\Action\PutTrigger;
use App\Engine\Card\SpellEffect;
use App\Engine\Decision\IllegalActionException;
use App\Engine\Game\CardInstance;
use App\Engine\Game\CombatState;
use App\Engine\Game\GameState;
use App\Engine\Game\Phase;
use App\Engine\Game\PlayerState;
use App\Engine\Mana\ManaContext;
use App\Engine\Mana\ManaCost;
use App\Engine\Mana\ManaPayment;

/**
 * Calcule les actions légales d'un joueur À CET INSTANT (état du GameState fait foi).
 * Alimente à la fois les agents IA et l'ActionPanel du front.
 * TODO: coûts colorés, X, coûts alternatifs, capacités activées, vitesse « flash », ciblage riche.
 */
final class LegalActionGenerator
{
    /** Plus grand X proposé pour un sort à {X} (évite des centaines d'actions identiques). */
    private const MAX_X = 10;

    /**
     * Scry : une action par rangement possible des cartes du dessus (chacune gardée ou non, dans un ordre). La première est la
     * plus naturelle (tout garder, dans l'ordre), ce que l'agent passif choisit.
     *
     * @return list<ScryArrange>
     */
    private function scryActions(GameState $s, int $playerId): array
    {
        $top = $s->stack[array_key_last($s->stack)] ?? null;
        $scry = $top?->card->effect('scry');
        if ($top === null || $scry === null || $top->controller !== $playerId) {
            return [];
        }
        $count = min($scry->amount, count($s->player($playerId)->library));
        $ids = array_map(static fn (CardInstance $c) => $c->id, array_slice($s->player($playerId)->library, 0, $count));

        return array_map(static fn (array $seq) => new ScryArrange($playerId, array_map(static fn (int $i) => $ids[$i], $seq), $count), self::orderedSelections($count));
    }

    /**
     * Sélection de cartes en cours (`GameState::$selection`). Brainstorm (`put_back`) : une action par carte de la main,
     * dédoublonnée par nom (une décision par carte remise). Ponder (`arrange`) : un ordre par permutation des cartes regardées
     * (l'ordre actuel en premier, ce que l'agent passif choisit), puis « mélanger ».
     *
     * @return list<PutBack|ArrangeTop|ChainCopy>
     */
    private function selectionActions(GameState $s, int $playerId): array
    {
        $sel = $s->selection;
        if ($sel === null || $sel['player'] !== $playerId) {
            return [];
        }
        $p = $s->player($playerId);
        if ($sel['kind'] === 'chain') {
            // Chain of Vapor : décliner d'abord (ce que l'agent passif choisit), puis un terrain par nom × un permanent non-terrain.
            $actions = [new ChainCopy($playerId)];
            $lands = [];
            foreach ($p->battlefield as $card) {
                if ($card->isLand() && !isset($lands[$card->name])) {
                    $lands[$card->name] = $card->id;
                }
            }
            foreach ($lands as $landId) {
                foreach (self::bounceTargets($s) as [$target]) {
                    $actions[] = new ChainCopy($playerId, $landId, $target);
                }
            }

            return $actions;
        }
        if ($sel['kind'] === 'put_back') {
            $actions = [];
            $seen = [];
            foreach ($p->hand as $card) {
                if (!isset($seen[$card->name])) {
                    $seen[$card->name] = true;
                    $actions[] = new PutBack($playerId, $card->id);
                }
            }

            return $actions;
        }
        $ids = array_map(static fn (CardInstance $c) => $c->id, array_slice($p->library, 0, $sel['count']));
        $actions = [];
        foreach (self::orderedSelections(count($ids)) as $seq) {
            if (count($seq) === count($ids)) {
                $actions[] = new ArrangeTop($playerId, array_map(static fn (int $i) => $ids[$i], $seq));
            }
        }
        $actions[] = new ArrangeTop($playerId, [], true);

        return $actions;
    }

    /** Toutes les sélections ordonnées d'indices distincts de 0 à `$n - 1`, les plus grandes d'abord (ordre naturel en premier). @return list<list<int>> */
    private static function orderedSelections(int $n): array
    {
        $out = [];
        $walk = static function (array $prefix) use (&$walk, &$out, $n): void {
            $out[] = $prefix;
            for ($i = 0; $i < $n; $i++) {
                if (!in_array($i, $prefix, true)) {
                    $walk([...$prefix, $i]);
                }
            }
        };
        $walk([]);
        usort($out, static fn (array $a, array $b) => count($b) <=> count($a));

        return $out;
    }

    /** @return list<Action> */
    public function for(GameState $s, int $playerId): array
    {
        if ($s->mulligan !== null) {
            return $this->mulliganActions($s, $playerId);
        }
        if ($s->selection !== null) {
            return $this->selectionActions($s, $playerId);
        }
        if ($s->scryPending) {
            return $this->scryActions($s, $playerId);
        }
        if ($s->paymentPending) {
            return $this->paymentActions($s, $playerId);
        }
        if ($s->sbaResume !== null) {
            return $this->legendActions($s, $playerId);
        }
        if ($s->triggerResumeHolder !== null) {
            return $this->triggerActions($s, $playerId);
        }

        if ($s->combat->declaring === CombatState::DAMAGE) {
            return $this->assignmentActions($s, $playerId);
        }
        if ($s->combat->declaring !== null) {
            return $this->declarationActions($s, $playerId);
        }

        // Défausse du cleanup ; pendant une ronde de priorité du cleanup (règle 514.3a), priorité ordinaire.
        if ($s->phase === Phase::Cleanup && !$s->cleanupRound && $playerId === $s->activePlayer
            && count($s->player($playerId)->hand) > PlayerState::MAX_HAND_SIZE) {
            return $this->discardActions($s, $playerId);
        }

        $p = $s->player($playerId);
        $actions = [new PassPriority($playerId)];

        $sorcerySpeed = $playerId === $s->activePlayer && $s->phase->isMain() && $s->stack === [];

        // Terrain : action spéciale, une par tour, timing sorcery.
        if ($sorcerySpeed && $s->landsPlayed < 1) {
            $seen = [];
            foreach ($p->hand as $card) {
                if ($card->isLand() && !isset($seen[$card->name])) {
                    $seen[$card->name] = true;
                    if ($card->untappedLifeCost === 0) {
                        $actions[] = new PlayLand($playerId, $card->id);
                        continue;
                    }
                    if ($p->life >= $card->untappedLifeCost) {
                        $actions[] = new PlayLand($playerId, $card->id, true);
                    }
                    $actions[] = new PlayLand($playerId, $card->id, false);
                }
            }
        }

        // Sorts (main + zone de commandement), dédoublonnés par (nom, cibles) pour ne pas lister 4 Bolt identiques.
        // Le coût d'un commandant inclut la taxe : +2 par lancement précédent.
        $seen = [];
        $mana = ManaPayment::context($p, $s->turn); // champ de bataille scanné une seule fois pour tous les sorts et capacités
        // Interdictions (Silence, Lavinia, Voice of Victory…) : vérifiées seulement s'il y en a, le cas courant reste rapide.
        $restricted = $s->castLocks !== [] || $s->noncreatureLocks !== [] || self::opponentsHaveStatics($s, $playerId);
        foreach ([...$p->hand, ...$p->command] as $card) {
            if ($card->isLand() || (!$sorcerySpeed && !$s->castsAtInstantSpeed($playerId, $card)) || ($restricted && !$s->canCast($playerId, $card))) {
                continue;
            }
            // Modes de paiement : le coût de mana (si payable avec les terrains non engagés : couleurs, hybrides), puis les coûts alternatifs.
            $cost = $p->castCost($card);
            $modes = ManaPayment::plan($p, $cost, 0, $s->turn, null, $card, $mana) === null ? [] : [null];
            if (($card->altCost === CardInstance::ALT_COMMANDER && $p->controlsCommander())
                || ($card->altCost === CardInstance::ALT_OPPONENT_THREE_SPELLS && PriorityManager::opponentCastThree($s, $playerId))) {
                $modes[] = CastSpell::ALT_FREE; // Fierce Guardianship, Mindbreak Trap
            }
            if ($card->altCost === CardInstance::ALT_PITCH_TWO_BLUE) {
                // Commandeer : deux cartes bleues distinctes de la main, dédoublonnées par paire de noms.
                $blues = array_values(array_filter($p->hand, static fn (CardInstance $o) => $o->id !== $card->id && $o->isBlue()));
                $pairs = [];
                foreach ($blues as $i => $a) {
                    foreach (array_slice($blues, $i + 1) as $b) {
                        $names = [$a->name, $b->name];
                        sort($names);
                        $pairs[implode('|', $names)] ??= CastSpell::ALT_PITCH . $a->id . ',' . $b->id;
                    }
                }
                array_push($modes, ...array_values($pairs));
            }
            if (($card->altCost === CardInstance::ALT_PITCH_BLUE && $p->life >= 1) || $card->altCost === CardInstance::ALT_PITCH_BLUE_FREE
                || ($card->altCost === CardInstance::ALT_PITCH_BLUE_OFF_TURN && $s->activePlayer !== $playerId)) {
                foreach ($p->hand as $other) {
                    if ($other->id !== $card->id && $other->isBlue()) {
                        $modes[] = CastSpell::ALT_PITCH . $other->id;
                    }
                }
            }
            if ($card->islandsToReturn() > 0) {
                foreach (self::islandChoices($p, $card->islandsToReturn()) as $ids) {
                    $modes[] = CastSpell::ALT_RETURN . implode(',', $ids);
                }
            }
            if ($modes === []) {
                continue;
            }
            // Un sort à {X} offre une action par X payable, au coût de mana seulement.
            $maxX = $cost->hasX() && in_array(null, $modes, true) ? min(ManaPayment::maxX($p, $cost, $s->turn, $card, $mana), self::MAX_X) : 0;
            // Coût additionnel « sacrifier une créature » : une créature par nom (Culling the Weak, Diabolic Intent).
            $sacrifices = [null];
            if ($card->extraCost === CardInstance::EXTRA_SACRIFICE_CREATURE) {
                $sacrifices = [];
                foreach ($p->battlefield as $creature) {
                    if ($creature->isCreature() && !isset($sacrifices[$creature->name])) {
                        $sacrifices[$creature->name] = $creature->id;
                    }
                }
                $sacrifices = array_values($sacrifices);
            }
            // Kicker (Orim's Chant) : une variante payée en plus, au coût de mana seulement.
            $kicks = $card->kicker !== null && in_array(null, $modes, true)
                && ManaPayment::plan($p, ManaCost::parse((string) $cost . $card->kicker), 0, $s->turn, null, $card, $mana) !== null ? [false, true] : [false];
            foreach ($card->isModal() ? $card->modes() : [0] as $mode) {
                foreach ($modes as $alt) {
                    foreach ($this->targetSets($s, $card, $playerId, $mode) as $targets) {
                        foreach ($sacrifices as $sacrifice) {
                            foreach ($alt === null ? $kicks : [false] as $kicked) {
                                for ($x = 0; $x <= ($alt === null ? $maxX : 0); $x++) {
                                    $key = $card->name . '|' . implode(',', $targets) . '|' . $x . '|' . $alt . '|' . $mode . '|' . $sacrifice . '|' . ($kicked ? 'k' : '');
                                    if (isset($seen[$key]) || ($restricted && $x > 0 && !$s->canCast($playerId, $card, $x))) {
                                        continue;
                                    }
                                    $seen[$key] = true;
                                    $actions[] = new CastSpell($playerId, $card->id, $targets, $x, $alt, $mode, $sacrifice, $kicked);
                                }
                            }
                        }
                    }
                }
            }
        }

        return [...$actions, ...$this->abilityActions($s, $p, $mana)];
    }

    /** Un adversaire de `$playerId` contrôle-t-il un permanent à capacité statique (Lavinia, Voice of Victory) ? */
    private static function opponentsHaveStatics(GameState $s, int $playerId): bool
    {
        foreach ($s->players as $o) {
            if ($o->id !== $playerId && !$o->lost) {
                foreach ($o->battlefield as $card) {
                    if ($card->statics !== []) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Îles à renvoyer pour Daze / Gush : chaque combinaison de `$n` Îles du joueur, dédoublonnée par (nom, engagée) — renvoyer
     * une Île engagée plutôt qu'une dégagée est un vrai choix.
     *
     * @return list<list<string>>
     */
    private static function islandChoices(PlayerState $p, int $n): array
    {
        $islands = array_values(array_filter($p->battlefield, static fn (CardInstance $c) => $c->isIsland()));
        $out = [];
        $seen = [];
        $walk = static function (int $from, array $picked) use (&$walk, &$out, &$seen, $islands, $n): void {
            if (count($picked) === $n) {
                $key = array_map(static fn (CardInstance $c) => $c->name . ($c->tapped ? '#t' : ''), $picked);
                sort($key);
                $key = implode('|', $key);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = array_map(static fn (CardInstance $c) => $c->id, $picked);
                }

                return;
            }
            for ($i = $from; $i < count($islands); $i++) {
                $walk($i + 1, [...$picked, $islands[$i]]);
            }
        };
        $walk(0, []);

        return $out;
    }

    /**
     * Paiement demandé à la résolution (`StackResolver::paymentDue()`) : payer (si l'auto-paiement trouve un plan), puis refuser.
     *
     * @return list<PayCost>
     */
    private function paymentActions(GameState $s, int $playerId): array
    {
        $due = StackResolver::paymentDue($s);
        if ($due === null || $due['player'] !== $playerId) {
            return [];
        }
        $payable = ManaPayment::plan($s->player($playerId), \App\Engine\Mana\ManaCost::parse($due['cost']), 0, $s->turn) !== null;

        return [...($payable ? [new PayCost($playerId, true, $due['cost'])] : []), new PayCost($playerId, false, $due['cost'])];
    }

    /**
     * Règle des légendes (voir StateBasedActions) : une action par permanent du premier groupe de légendaires homonymes.
     *
     * @return list<KeepLegend>
     */
    private function legendActions(GameState $s, int $playerId): array
    {
        return array_map(
            static fn (CardInstance $c) => new KeepLegend($playerId, $c->id),
            StateBasedActions::legendGroup($s->player($playerId)) ?? [],
        );
    }

    /**
     * Capacités activées des permanents du joueur, à tout moment où il a la priorité (ou seulement en rituel si
     * `sorcerySpeed`, voir AbilityManager::canActivate). Une action par (alternative, cibles, choix de coût), dédoublonnée
     * par noms : quatre Montagnes non engagées = une seule action ; sacrifier l'un ou l'autre de deux jetons Kobold aussi.
     *
     * @return list<ActivateAbility>
     */
    private function abilityActions(GameState $s, PlayerState $p, ManaContext $mana): array
    {
        $actions = [];
        $seen = [];
        // Champ de bataille, puis capacités activables depuis la main (Simian Spirit Guide, Channel d'Otawara).
        $sources = [];
        foreach ($p->battlefield as $card) {
            $sources[] = [$card, false];
        }
        foreach ($p->hand as $card) {
            foreach ($card->abilities as $declared) {
                if ($declared->fromHand !== '') {
                    $sources[] = [$card, true];
                    break;
                }
            }
        }
        foreach ($sources as [$card, $inHand]) {
            foreach ($card->abilities() as $index => $ability) {
                if (($ability->fromHand !== '') !== $inHand || !AbilityManager::canActivate($s, $p, $card, $ability, $mana)) {
                    continue;
                }
                $options = $ability->isManaAbility ? AbilityManager::allowedOptions($p, $card, $ability) : [0];
                $targetSets = match ($ability->effect) {
                    'damage' => array_map(static fn (int $id) => ['player:' . $id], $s->alivePlayerIds()),
                    'fetch' => self::fetchTargets($p, $ability),
                    // Channel : un artefact, une créature ou un enchantement (pas un terrain), de n'importe quel joueur en vie.
                    'bounce' => self::bounceTargets($s),
                    default => [[]],
                };
                foreach (AbilityManager::costChoices($p, $card, $ability) as $choices) {
                    $names = implode(',', array_map(static fn (CardInstance $c) => $c->name, $choices));
                    $ids = array_map(static fn (CardInstance $c) => $c->id, $choices);
                    foreach ($options as $option) {
                        foreach ($targetSets as $targets) {
                            $key = $card->name . '|' . $index . '|' . $option . '|' . implode(',', $targets) . '|' . $names;
                            if (!isset($seen[$key])) {
                                $seen[$key] = true;
                                $actions[] = new ActivateAbility($p->id, $card->id, $index, $option, $targets, $ability->isManaAbility, $ids);
                            }
                        }
                    }
                }
            }
        }

        return $actions;
    }

    /**
     * Défausse obligatoire (main > 7 au cleanup) : une action par nom de carte, pas de « passer ».
     *
     * @return list<Action>
     */
    /**
     * Avant-partie (voir MulliganManager) : garder ou prendre un mulligan (tant que moins de 7 pris), puis, main gardée,
     * une action par carte de la main à mettre au fond (pas de dédoublonnage par nom : le front propose chaque carte).
     *
     * @return list<Action>
     */
    private function mulliganActions(GameState $s, int $playerId): array
    {
        $m = $s->mulligan[$playerId] ?? null;
        if ($m === null) {
            return [];
        }
        if (!$m['kept']) {
            return $m['taken'] < MulliganManager::HAND_SIZE
                ? [new KeepHand($playerId), new Mulligan($playerId)]
                : [new KeepHand($playerId)];
        }
        if (MulliganManager::toBottom($s, $playerId) === 0) {
            return [];
        }

        return array_map(static fn (CardInstance $c) => new BottomCard($playerId, $c->id), $s->player($playerId)->hand);
    }

    private function discardActions(GameState $s, int $playerId): array
    {
        $actions = [];
        $seen = [];
        foreach ($s->player($playerId)->hand as $card) {
            if (!isset($seen[$card->name])) {
                $seen[$card->name] = true;
                $actions[] = new Discard($playerId, $card->id);
            }
        }

        return $actions;
    }

    /**
     * Triggers en attente du joueur : un choix par trigger et par cible, pas de « passer ». Deux triggers
     * identiques (même source par le nom, même capacité) ne donnent qu'un seul choix : leur ordre relatif est indifférent.
     *
     * @return list<PutTrigger>
     */
    public function triggerActions(GameState $s, int $playerId): array
    {
        $actions = [];
        $seen = [];
        foreach ($s->pendingTriggers as $pending) {
            if ($pending->controller !== $playerId) {
                continue;
            }
            $ability = $pending->ability();
            $targetSets = $ability->targetsPlayer()
                ? array_map(static fn (int $id) => ['player:' . $id], $s->alivePlayerIds())
                : ($pending->opponent !== null && $ability->effect === 'draw_unless' ? [['player:' . $pending->opponent]] : [[]]); // Rhystic Study : le lanceur paiera ; Kraum ne cible pas
            if ($ability->effect === 'counter_spell' && $pending->stackId !== null) {
                $targetSets = [['stack:' . $pending->stackId]]; // Lavinia : le sort lancé sans mana
            }
            if ($ability->choosesLibraryCard()) {
                // Tuteur à l'entrée (Ranger-Captain of Eos) : une carte qui convient par nom, ou rien à chercher.
                $targetSets = [];
                $names = [];
                foreach ($s->player($playerId)->library as $card) {
                    if ($ability->tutorMatches($card) && !isset($names[$card->name])) {
                        $names[$card->name] = true;
                        $targetSets[] = ['library:' . $card->id];
                    }
                }
                $targetSets = $targetSets === [] ? [['library:none']] : $targetSets;
            }
            if ($ability->choosesHandCard()) {
                // Imprint (Chrome Mox) : ne rien exiler, ou l'une des cartes éligibles de la main (non-artefact, non-terrain).
                foreach ($s->player($playerId)->hand as $card) {
                    if (!$card->isLand() && !$card->hasType(CardInstance::ARTIFACT)) {
                        $targetSets[] = ['card:' . $card->id];
                    }
                }
            }
            foreach ($targetSets as $targets) {
                $key = $pending->source->name . '|' . $pending->index . '|' . implode(',', $targets);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $actions[] = new PutTrigger($playerId, $pending->id, $targets);
                }
            }
        }

        return $actions;
    }

    /**
     * Répartition des dégâts de combat (contrôleur humain de l'attaquant) : une action par répartition légale du
     * prochain attaquant concerné (DamageAssignment::options, la répartition par défaut en tête), pas de « passer ».
     * Vide pour un siège IA (répartition par défaut, sans décision) ou quand il n'y a qu'une répartition possible.
     *
     * @return list<AssignCombatDamage>
     */
    public function assignmentActions(GameState $s, int $playerId): array
    {
        if ($playerId !== $s->activePlayer || !DamageAssignment::chooses($s)) {
            return [];
        }
        $firstStrike = $s->phase === Phase::FirstStrikeDamage;
        foreach (array_keys($s->combat->attackers) as $attackerId) {
            $attackerId = (string) $attackerId;
            if (isset($s->combat->assignments[$attackerId])) {
                continue;
            }
            $options = DamageAssignment::options($s, $attackerId, $firstStrike);
            if (count($options) > 1) {
                return array_map(
                    static fn (array $vector, int $i) => new AssignCombatDamage($playerId, $attackerId, $vector, $i === 0),
                    $options,
                    array_keys($options),
                );
            }
        }

        return [];
    }

    /**
     * Pendant une déclaration (attaquants ou bloqueurs) : un choix par action, puis FinishDeclaration.
     * Seul le déclarant courant a des choix ; la liste est recalculée après chaque déclaration.
     *
     * @return list<Action>
     */
    private function declarationActions(GameState $s, int $playerId): array
    {
        $choices = $s->combat->declaring === 'attackers' ? $this->attackActions($s, $playerId) : $this->blockActions($s, $playerId);
        if ($s->combat->declaring !== 'attackers') {
            $half = [];
            foreach ($s->combat->blockers as $attackerId => $blockers) {
                if (count($blockers) === 1 && $this->isMenaceAttacker($s, $attackerId)) {
                    $half[$attackerId] = true;
                }
            }
            if ($half !== []) {
                $completing = array_values(array_filter($choices, static fn ($c) => isset($half[$c->attackerId])));
                if ($completing !== []) {
                    return $completing;
                }
            }
        }

        return [...$choices, new FinishDeclaration($playerId)];
    }

    /**
     * Attaquants possibles du joueur actif : créature non engagée, arrivée avant ce tour (mal d'invocation),
     * pas déjà déclarée, vers chaque adversaire en vie.
     *
     * @return list<DeclareAttacker>
     */
    public function attackActions(GameState $s, int $playerId): array
    {
        if ($playerId !== $s->activePlayer || $s->attackLocked) {
            return [];
        }
        $actions = [];
        foreach ($s->player($playerId)->battlefield as $card) {
            if (!$card->isCreature() || $card->tapped || $card->hasKeyword(CardInstance::DEFENDER)
                || ($card->enteredTurn >= $s->turn && !$card->hasKeyword(CardInstance::HASTE)) || isset($s->combat->attackers[$card->id])) {
                continue;
            }
            foreach ($s->alivePlayerIds() as $defender) {
                if ($defender !== $playerId) {
                    $actions[] = new DeclareAttacker($playerId, $card->id, $defender);
                }
            }
        }

        return $actions;
    }

    /**
     * Bloqueurs possibles : créature non engagée du défenseur, qui ne bloque pas déjà, sur un attaquant qui le vise.
     *
     * @return list<DeclareBlocker>
     */
    public function blockActions(GameState $s, int $playerId): array
    {
        $attackers = [];
        foreach ($s->combat->attackers as $attackerId => $defender) {
            if ($defender === $playerId && $this->onBattlefield($s, $s->activePlayer, $attackerId)) {
                $attackers[] = $attackerId;
            }
        }
        $actions = [];
        $free = [];
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->isCreature() && !$card->tapped && !$s->combat->isBlocking($card->id)) {
                $free[] = $card;
            }
        }
        $allowed = [];
        foreach ($attackers as $attackerId) {
            $attacker = $this->findCard($s, $s->activePlayer, $attackerId);
            $able = array_filter($free, static fn (CardInstance $c) => !$attacker->hasKeyword(CardInstance::FLYING)
                || $c->hasKeyword(CardInstance::FLYING) || $c->hasKeyword(CardInstance::REACH));
            if ($attacker->hasKeyword(CardInstance::MENACE) && count($s->combat->blockers[$attackerId] ?? []) === 0 && count($able) < 2) {
                continue;
            }
            foreach ($able as $card) {
                $allowed[$card->id][$attackerId] = true;
            }
        }
        foreach ($free as $card) {
            foreach ($attackers as $attackerId) {
                if (isset($allowed[$card->id][$attackerId])) {
                    $actions[] = new DeclareBlocker($playerId, $card->id, $attackerId);
                }
            }
        }

        return $actions;
    }

    private function isMenaceAttacker(GameState $s, string $attackerId): bool
    {
        return $this->findCard($s, $s->activePlayer, $attackerId)?->hasKeyword(CardInstance::MENACE) ?? false;
    }

    private function findCard(GameState $s, int $playerId, string $cardId): ?CardInstance
    {
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->id === $cardId) {
                return $card;
            }
        }

        return null;
    }

    private function onBattlefield(GameState $s, int $playerId, string $cardId): bool
    {
        foreach ($s->player($playerId)->battlefield as $card) {
            if ($card->id === $cardId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrouve, parmi les actions légales, celle que $injected désigne (jamais de confiance au client).
     *
     * @param list<Action> $legal
     */
    public function match(Action $injected, int $holder, array $legal): Action
    {
        if ($injected->player() === $holder) {
            foreach ($legal as $candidate) {
                if ($candidate->id() === $injected->id()) {
                    return $candidate;
                }
            }
        }

        throw new IllegalActionException(sprintf('Action illégale pour le joueur %d : %s', $holder, $injected->id()));
    }

    /**
     * Cibles d'un sort : une liste par combinaison de cibles, une cible par effet ciblé dans l'ordre du texte (CR 115.1).
     * Chaque effet ciblé choisit sa cible indépendamment des autres (deux « cible joueur » peuvent viser le même joueur,
     * comme deux mots « cible » distincts, CR 115.3). Sans effet ciblé : une seule liste vide. Un effet sans cible légale
     * annule toutes les combinaisons.
     *
     * @return list<list<string>>
     */
    private function targetSets(GameState $s, CardInstance $card, int $playerId, int $mode = 0): array
    {
        $sets = [[]];
        foreach ($card->targetedEffects($mode) as $effect) {
            $next = [];
            foreach ($sets as $set) {
                foreach ($this->targetCandidates($s, $effect, $playerId) as $target) {
                    $next[] = [...$set, $target];
                }
            }
            $sets = $next;
        }

        return $sets;
    }

    /** @return list<string> les cibles légales d'un effet ciblé */
    private function targetCandidates(GameState $s, SpellEffect $effect, int $playerId): array
    {
        return match ($effect->effect) {
            // Tuteur : une carte de sa bibliothèque par nom (`library:<id>`), comme une fetchland.
            'tutor' => self::libraryTargets($s->player($playerId), $effect->tutorFilter),
            // Exil : tout permanent (ou toute créature) d'un joueur en vie, le sien compris (`permanent:<id>`) ; `opponent_…` : d'un adversaire.
            'exile', 'bounce', 'destroy' => self::permanentTargets($s, $effect, $playerId),
            'mill', 'silence_target' => array_map(static fn (int $id) => 'player:' . $id, $s->alivePlayerIds()),
            // Gift : un adversaire en vie reçoit le jeton.
            'gift' => array_map(static fn (int $id) => 'player:' . $id, array_values(array_filter($s->alivePlayerIds(), static fn (int $id) => $id !== $playerId))),
            'steal' => array_map(static fn ($item) => 'stack:' . $item->id, array_values(array_filter($s->stack, static fn ($item) => !$item->isAbility() && $effect->canCounter($item->card, $item->x)))),
            'redirect' => $this->redirectCandidates($s, $effect),
            'exile_spells' => self::exileSpellsCandidates($s, $playerId),
            // Copie : une créature que le lanceur contrôle (`permanent:<id>`).
            'copy' => array_map(static fn (CardInstance $c) => 'permanent:' . $c->id, array_values(array_filter($s->player($playerId)->battlefield, static fn (CardInstance $c) => $c->isCreature()))),
            'damage' => array_map(static fn (int $id) => 'player:' . $id, $s->alivePlayerIds()),
            // Un contresort ne cible que des sorts, pas des capacités activées ; Dispel seulement les instants (`SpellEffect::canCounter()`).
            'counter' => array_map(static fn ($item) => 'stack:' . $item->id, array_values(array_filter($s->stack, static fn ($item) => !$item->isAbility() && $effect->canCounter($item->card, $item->x)))),
            default => [],
        };
    }

    /**
     * @return list<string> une cible `library:<id>` par nom de carte de la bibliothèque (`library:none` si aucune ne convient)
     * @param ?string $filter types acceptés, alternatives séparées par `|` (null = toute carte)
     */
    private static function libraryTargets(PlayerState $p, ?string $filter = null): array
    {
        $types = $filter === null ? null : explode('|', $filter);
        $out = [];
        $seen = [];
        foreach ($p->library as $card) {
            if ($types !== null && !array_filter($types, static fn (string $t) => $card->hasType($t))) {
                continue;
            }
            if (!isset($seen[$card->name])) {
                $seen[$card->name] = true;
                $out[] = 'library:' . $card->id;
            }
        }

        return $out === [] ? ['library:none'] : $out;
    }

    /** @return list<list<string>> une cible par permanent non-terrain (capacité Channel), joueurs dans l'ordre du tour */
    private static function bounceTargets(GameState $s): array
    {
        $out = [];
        foreach ($s->alivePlayerIds() as $id) {
            foreach ($s->player($id)->battlefield as $permanent) {
                if (!$permanent->isLand()) {
                    $out[] = ['permanent:' . $permanent->id];
                }
            }
        }

        return $out;
    }

    /**
     * Deflecting Swat (`any`), Misdirection (`spell`) : une cible `stack:<id>><nouvelle cible>` par objet de pile à une seule
     * cible et par nouvelle cible légale pour lui (voir `retargetCandidates()`).
     *
     * @return list<string>
     */
    private function redirectCandidates(GameState $s, SpellEffect $effect): array
    {
        $out = [];
        foreach ($s->stack as $item) {
            if ($effect->target === 'spell' && $item->isAbility()) {
                continue;
            }
            foreach ($this->retargetCandidates($s, $item) as $new) {
                $out[] = 'stack:' . $item->id . '>' . $new;
            }
        }

        return $out;
    }

    /**
     * Nouvelles cibles légales d'un objet de pile à une seule cible (CR 115.7), autres que l'actuelle et que lui-même, du point
     * de vue de son contrôleur : sort à un effet ciblé (contresort, dégâts, renvoi…), ou capacité de dégâts (un joueur) ou de
     * renvoi (Channel d'Otawara). Vide sinon (cible d'une recherche de bibliothèque, d'un Gift, d'un Mindbreak Trap…).
     *
     * @return list<string>
     */
    public function retargetCandidates(GameState $s, \App\Engine\Game\StackItem $item): array
    {
        if (count($item->targets) !== 1) {
            return [];
        }
        if ($item->isAbility()) {
            $effect = ($item->ability ?? $item->trigger)->effect;
            $candidates = match ($effect) {
                'damage' => array_map(static fn (int $id) => 'player:' . $id, $s->alivePlayerIds()),
                'bounce' => array_merge(...array_map(static fn (array $t) => $t, self::bounceTargets($s))),
                default => [],
            };
        } else {
            $targeted = $item->card->targetedEffects($item->mode);
            if (count($targeted) !== 1 || in_array($targeted[0]->effect, ['tutor', 'gift', 'redirect', 'exile_spells'], true)) {
                return [];
            }
            $candidates = $this->targetCandidates($s, $targeted[0], $item->controller);
        }

        return array_values(array_filter($candidates, static fn (string $t) => $t !== $item->targets[0] && $t !== 'stack:' . $item->id));
    }

    /**
     * Mindbreak Trap (« any number of target spells ») : chaque sort de la pile seul, puis tous les sorts des adversaires du
     * lanceur ensemble s'il y en a au moins deux (`stack:<id>+<id>…`). Les autres combinaisons ne sont pas proposées.
     *
     * @return list<string>
     */
    private static function exileSpellsCandidates(GameState $s, int $playerId): array
    {
        $spells = array_values(array_filter($s->stack, static fn ($item) => !$item->isAbility()));
        $out = array_map(static fn ($item) => 'stack:' . $item->id, $spells);
        $theirs = array_map(static fn ($item) => $item->id, array_values(array_filter($spells, static fn ($item) => $item->controller !== $playerId)));
        if (count($theirs) >= 2) {
            $out[] = 'stack:' . implode('+', $theirs);
        }

        return $out;
    }

    /** @return list<string> une cible `permanent:<id>` par permanent légal, joueurs dans l'ordre du tour */
    private static function permanentTargets(GameState $s, SpellEffect $effect, ?int $caster = null): array
    {
        $out = [];
        foreach ($s->alivePlayerIds() as $id) {
            foreach ($s->player($id)->battlefield as $permanent) {
                if ($effect->canTargetPermanent($permanent, $id, $caster)) {
                    $out[] = 'permanent:' . $permanent->id;
                }
            }
        }

        return $out;
    }

    /**
     * Cibles d'une fetchland : un terrain de la bibliothèque qui convient, dédoublonné par nom (`library:<id>`), suivi de `life`
     * pour une shockland dont on paie les PV ; `library:none` si rien ne convient (chercher sans trouver est permis).
     *
     * @return list<list<string>>
     */
    private static function fetchTargets(PlayerState $p, \App\Engine\Ability\ActivatedAbility $ability): array
    {
        $out = [];
        $seen = [];
        $tapped = $ability->fetchSpec()['tapped'];
        foreach ($p->library as $card) {
            if (isset($seen[$card->name]) || !$ability->fetches($card)) {
                continue;
            }
            $seen[$card->name] = true;
            // Shockland cherchée : payer ses PV (s'il en reste assez après le coût de la fetchland) ou l'avoir engagée.
            if (!$tapped && $card->untappedLifeCost > 0 && $p->life - $ability->lifeCost >= $card->untappedLifeCost) {
                $out[] = ['library:' . $card->id, 'life'];
            }
            $out[] = ['library:' . $card->id];
        }

        return $out === [] ? [['library:none']] : $out;
    }
}
