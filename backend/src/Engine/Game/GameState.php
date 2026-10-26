<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Event\GameEvent;

/**
 * Source de vérité unique d'une partie. 100 % sérialisable (toArray/fromArray),
 * aucun état caché ailleurs : c'est la condition pour que snapshot + fork fonctionnent.
 */
final class GameState
{
    /** Version du format de `toArray()` ; à incrémenter quand la sérialisation change de façon incompatible. */
    public const FORMAT_VERSION = 3;

    public int $turn = 1;
    public int $activePlayer;
    public Phase $phase = Phase::Untap;
    public bool $stepStarted = false;
    public PriorityState $priority;
    /** @var list<StackItem> dernier élément = dessus de la pile */
    public array $stack = [];
    public int $landsPlayed = 0;
    public int $nextStackId = 1;
    public int $nextTokenId = 1;
    public int $eventSeq = 0;
    public bool $over = false;
    public ?int $winner = null;
    public CombatState $combat;
    /** @var list<PendingTrigger> triggers en attente, dans l'ordre où ils surviennent (voir TriggerManager) */
    public array $pendingTriggers = [];
    public int $nextTriggerId = 1;
    /** Détenteur de priorité à rétablir après un choix de triggers en cours ; null hors de ce choix. */
    public ?int $triggerResumeHolder = null;
    /**
     * Ronde de priorité ouverte pendant le cleanup (règle 514.3a : des SBA ont agi ou des triggers attendent après la
     * défausse). Tant qu'elle est vraie, la défausse n'est pas rejouée ; à la fin de la ronde, nouvelle étape de cleanup.
     */
    public bool $cleanupRound = false;
    /**
     * Priorité à rétablir après un choix demandé par les SBA (règle des légendes, voir StateBasedActions) ; null hors de
     * ce choix. Sérialisé sous `sbaResume`, omis quand null.
     */
    public ?PriorityState $sbaResume = null;

    /** Un sort avec Scry attend le rangement de son contrôleur (`ScryArrange`), avant sa résolution. Sérialisé, omis quand faux. */
    public bool $scryPending = false;
    /**
     * Le sommet de pile attend un paiement à sa résolution (« sauf si son contrôleur paie », Daze ; « payez ou perdez la
     * partie », Pact of Negation) : le payeur (voir `StackResolver::paymentDue()`) choisit `PayCost`. Sérialisé, omis quand faux.
     */
    public bool $paymentPending = false;
    /**
     * Sélection de cartes en cours au milieu d'une résolution (lot G3, voir `SpellEffect::SELECTION`) : `player` décide,
     * `kind` = `put_back` (Brainstorm : `count` cartes de la main encore à remettre sur le dessus, une décision `PutBack` par
     * carte) ou `arrange` (Ponder : les `count` cartes du dessus à remettre dans l'ordre choisi ou à mélanger, une décision
     * `ArrangeTop`), `source` = nom du sort, `draw` = cartes à piocher une fois la sélection finie (Ponder). Le sort a déjà
     * quitté la pile. Sérialisé sous `selection`, omis quand null.
     *
     * @var array{player: int, kind: string, count: int, source: string, draw: int}|null
     */
    public ?array $selection = null;
    /**
     * Triggers retardés (CR 603.7) qui attendent leur événement (`TriggeredAbility::NEXT_UPKEEP`, `NEXT_MAIN`) : à ce moment
     * ils rejoignent `$pendingTriggers`. Sérialisé sous `delayedTriggers`, omis quand vide.
     *
     * @var list<PendingTrigger>
     */
    public array $delayedTriggers = [];
    /**
     * Stops de l'auto-pass par joueur (voir StopConfig) ; un joueur absent garde le comportement par défaut. Sérialisé
     * sous la clé `stops`, omise quand le tableau est vide (ne gonfle pas les états des parties sans config).
     *
     * @var array<int, StopConfig>
     */
    public array $stops = [];
    /**
     * Avant-partie (mulligan de Londres, voir MulliganManager) : par joueur, mulligans pris et main gardée ; null une fois
     * toutes les mains gardées et leurs cartes mises au fond. Sérialisé sous la clé `mulligan`, omise quand null.
     *
     * @var array<int, array{taken: int, kept: bool}>|null
     */
    public ?array $mulligan = null;

    /** @var list<GameEvent> tampon vidé par drainEvents(), jamais sérialisé */
    private array $pendingEvents = [];

    /**
     * @param array<int, PlayerState> $players     indexés par id, ordre croissant = ordre du tour
     * @param array<int, string>      $controllers  id joueur => clé d'agent ('ai:heuristic', 'human', ...)
     */
    public function __construct(
        public array $players,
        public Rng $rng,
        public array $controllers,
        public int $maxTurns = 400, // tours cumulés de tous les joueurs
    ) {
        ksort($this->players);
        $this->activePlayer = (int) array_key_first($this->players);
        $this->priority = new PriorityState($this->activePlayer);
        $this->combat = new CombatState();
        // Branche chaque joueur sur ses adversaires (couleurs de leurs terrains) ; référence faible : pas de cycle d'objets.
        $self = \WeakReference::create($this);
        foreach ($this->players as $p) {
            $id = $p->id;
            $p->opponentColors = static fn (): array => $self->get()?->opponentLandColors($id) ?? [];
        }
    }

    /**
     * Couleurs (lettres WUBRG, `[lettre => true]`) qu'un terrain contrôlé par un adversaire de `$playerId` pourrait produire
     * (Fellwar Stone, Exotic Orchard). Seules comptent les capacités de mana fixes : une capacité dynamique (un autre
     * Exotic Orchard) ne produit rien par elle-même (CR 106.7). L'incolore n'est pas une couleur. Calcul à la demande.
     *
     * @return array<string, true>
     */
    public function opponentLandColors(int $playerId): array
    {
        $set = [];
        foreach ($this->players as $o) {
            if ($o->id === $playerId || $o->lost) {
                continue;
            }
            foreach ($o->battlefield as $card) {
                if (!$card->isLand()) {
                    continue;
                }
                foreach ($card->abilities() as $ability) {
                    if (!$ability->isManaAbility || $ability->effect !== 'mana' || $ability->isDynamicMana()) {
                        continue;
                    }
                    foreach ($ability->options as $option) {
                        foreach ($option as $color) {
                            if ($color !== \App\Engine\Mana\Color::Colorless) {
                                $set[$color->symbol()] = true;
                            }
                        }
                    }
                }
            }
        }

        return $set;
    }

    public function player(int $id): PlayerState
    {
        return $this->players[$id];
    }

    /** @return list<int> ids des joueurs encore en jeu, dans l'ordre du tour */
    public function alivePlayerIds(): array
    {
        $ids = [];
        foreach ($this->players as $id => $p) {
            if (!$p->lost) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Prochain joueur en vie après $from, dans le sens du tour. */
    public function nextAlive(int $from): int
    {
        $ids = array_keys($this->players);
        $n = count($ids);
        $start = (int) array_search($from, $ids, true);
        for ($i = 1; $i <= $n; $i++) {
            $id = $ids[($start + $i) % $n];
            if (!$this->players[$id]->lost) {
                return $id;
            }
        }

        return $from;
    }

    public function topOfStack(): ?StackItem
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    public function findStackItem(string $id): ?StackItem
    {
        foreach ($this->stack as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public function emit(string $type, array $payload = [], bool $verbose = false): GameEvent
    {
        $event = new GameEvent(++$this->eventSeq, $type, $payload, $verbose);
        $this->pendingEvents[] = $event;

        return $event;
    }

    /** @return list<GameEvent> */
    public function drainEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    /**
     * Pioche une carte et journalise. Retourne false si la bibliothèque est vide : le joueur perdra à la prochaine
     * vérification des SBA (CR 704.5b), pas tout de suite.
     */
    public function drawCard(int $playerId): bool
    {
        $p = $this->player($playerId);
        $card = array_shift($p->library);
        if ($card === null) {
            $p->drewFromEmpty = true;

            return false;
        }
        $p->hand[] = $card;
        $this->emit('CardDrawn', ['player' => $playerId, 'card' => $card->toPublic(), 'library' => count($p->library)]);

        return true;
    }

    /**
     * Envoie une carte au cimetière de son propriétaire, SAUF un jeton qui cesse d'exister (règles 111.7 et 704.5d).
     * Un commandant va bien au cimetière (ses triggers « meurt » se déclenchent) ; les SBA le renvoient ensuite en zone
     * de commandement (CR 903.9a, voir StateBasedActions). Retourne la destination réelle : 'graveyard' | 'none'.
     */
    public function sendToGraveyard(int $ownerId, CardInstance $card): string
    {
        if ($card->token) {
            return 'none';
        }
        $this->player($ownerId)->graveyard[] = $card;

        return 'graveyard';
    }

    /**
     * Met une carte dans la zone d'exil de son propriétaire, SAUF un jeton qui cesse d'exister (règles 111.7 et 704.5d).
     * Un commandant va bien en exil ; les SBA le renvoient ensuite en zone de commandement (CR 903.9a, voir
     * StateBasedActions), comme depuis le cimetière. Seul chemin d'entrée en exil. Retourne 'exile' | 'none'.
     */
    public function exile(int $ownerId, CardInstance $card): string
    {
        if ($card->token) {
            return 'none';
        }
        $this->player($ownerId)->exile[] = $card;

        return 'exile';
    }

    /** Exile une carte de la main de `$playerId` payée en coût alternatif (Force of Will) ; `CardExiled` avec `from: hand`. */
    public function exileFromHand(int $playerId, CardInstance $card, string $source): void
    {
        $destination = $this->exile($playerId, $card);
        $this->emit('CardExiled', [
            'player' => $playerId,
            'card' => $card->toPublic(),
            'from' => 'hand',
            'destination' => $destination,
            'source' => $source,
            'exile' => count($this->player($playerId)->exile),
        ]);
    }

    /**
     * Exile un permanent du champ de bataille de `$playerId` (Swords to Plowshares, Path to Exile) : il redevient un objet
     * neuf (dégagé, sans dégâts ni bonus), passe par `exile()` et `CardExiled` est journalisé. Aucun trigger « meurt ».
     * Retourne false si le permanent n'est plus là.
     */
    public function exilePermanent(int $playerId, string $cardId, string $source): bool
    {
        $card = $this->player($playerId)->takeFromBattlefield($cardId);
        if ($card === null) {
            return false;
        }
        $card->tapped = false;
        $card->damage = 0;
        $card->deathtouchDamage = false;
        $card->powerBonus = 0;
        $card->toughnessBonus = 0;
        $destination = $this->exile($playerId, $card);
        $this->emit('CardExiled', [
            'player' => $playerId,
            'card' => $card->toPublic(),
            'from' => 'battlefield',
            'destination' => $destination,
            'source' => $source,
            'exile' => count($this->player($playerId)->exile),
        ]);

        return true;
    }

    /**
     * Sacrifie des permanents du joueur (coût déjà payé : ex. Treasure engagé par `payWithPlan`) : champ de bataille ->
     * cimetière via `sendToGraveyard()`, un événement `PermanentSacrificed` par permanent. Seul chemin du sacrifice.
     *
     * @param list<string> $cardIds
     */
    public function sacrifice(int $playerId, array $cardIds): void
    {
        $p = $this->player($playerId);
        foreach ($cardIds as $id) {
            $card = $p->takeFromBattlefield($id) ?? throw new \DomainException("Permanent à sacrifier introuvable : $id");
            $card->tapped = false;
            $destination = $this->sendToGraveyard($playerId, $card);
            $this->emit('PermanentSacrificed', ['player' => $playerId, 'card' => $card->toPublic(), 'destination' => $destination]);
            if ($card->isCreature()) {
                $this->queueTriggers($playerId, $card, TriggeredAbility::DIES);
            }
        }
    }

    /** Crée un jeton Treasure sur le champ de bataille du joueur (`TokenCreated`). */
    public function createTreasure(int $playerId): CardInstance
    {
        return $this->createToken($playerId, 'Treasure', CardInstance::ARTIFACT, abilities: [ActivatedAbility::treasure()], subtypes: [CardInstance::ARTIFACT => ['Treasure']]);
    }

    /**
     * Crée un jeton sur le champ de bataille du joueur (id `p<joueur>_t<n>`, déterministe) et journalise `TokenCreated`.
     *
     * @param list<ActivatedAbility>      $abilities
     * @param array<string, list<string>> $subtypes
     */
    public function createToken(int $playerId, string $name, string $type, int $power = 0, int $toughness = 0, array $abilities = [], array $subtypes = [], array $keywords = []): CardInstance
    {
        $token = new CardInstance(
            sprintf('p%d_t%d', $playerId, $this->nextTokenId++), $name, $type,
            power: $power, toughness: $toughness, abilities: $abilities, token: true, subtypes: $subtypes, keywords: $keywords,
        );
        $this->enterBattlefield($playerId, $token);
        $this->emit('TokenCreated', ['player' => $playerId, 'card' => $token->toPublic()]);

        return $token;
    }

    /**
     * Crée un jeton qui est une copie de `$source` (CR 707.2) : valeurs copiables seulement (nom, types, supertypes dont légendaire,
     * sous-types, force / endurance de base, mots-clés, capacités et triggers) ; ni marqueurs, ni bonus, ni dégâts, ni statut de
     * commandant (CR 903.3 : le commandant est une carte). `TokenCreated` porte `copyOf` (id de la source).
     */
    public function createTokenCopy(int $playerId, CardInstance $source): CardInstance
    {
        $token = new CardInstance(
            sprintf('p%d_t%d', $playerId, $this->nextTokenId++), $source->name, $source->types, $source->cost,
            power: $source->power, toughness: $source->toughness, manaCost: $source->manaCost, produces: $source->produces,
            abilities: $source->abilities, token: true, triggers: $source->triggers, subtypes: $source->subtypes,
            keywords: $source->keywords, supertypes: $source->supertypes,
        );
        $this->enterBattlefield($playerId, $token);
        $this->emit('TokenCreated', ['player' => $playerId, 'card' => $token->toPublic(), 'copyOf' => $source->id]);

        return $token;
    }

    /**
     * Seul chemin d'entrée sur le champ de bataille (sort résolu, terrain joué, jeton créé) : note le tour d'arrivée (mal
     * d'invocation) et met en file les triggers `etb` de la carte. L'appelant journalise.
     */
    /**
     * Met un terrain sur le champ de bataille (joué depuis la main, ou cherché par une fetchland) et émet `$event` (`LandPlayed`
     * ou `LandFetched`) avec `tapped: true` s'il arrive engagé. Shockland (`untappedLifeCost`) : « en arrivant, vous pouvez payer
     * N PV ; sinon il arrive engagé » ; `$payLife` porte le choix (payé seulement s'il reste assez de PV, CR 119.4).
     */
    public function putLandOntoBattlefield(int $playerId, CardInstance $card, ?bool $payLife, string $event, bool $tapped = false): void
    {
        $pays = $card->untappedLifeCost > 0 && $payLife === true && $this->player($playerId)->life >= $card->untappedLifeCost;
        $tapped = $tapped || ($card->untappedLifeCost > 0 && !$pays);
        $card->tapped = $tapped;
        $this->enterBattlefield($playerId, $card);
        $this->emit($event, ['player' => $playerId, 'card' => $card->toPublic()] + ($tapped ? ['tapped' => true] : [])
            + ($event === 'LandFetched' ? ['library' => count($this->player($playerId)->library)] : []));
        if ($pays) {
            $this->payLife($playerId, $card->untappedLifeCost, $card->name);
        }
    }

    /** Payer des PV (coût : Mana Confluence, fetchland, shockland) : `LifePaid` avec les PV résultants. */
    public function payLife(int $playerId, int $amount, string $source): void
    {
        $p = $this->player($playerId);
        $p->life -= $amount;
        $this->emit('LifePaid', ['player' => $playerId, 'amount' => $amount, 'life' => $p->life, 'source' => $source]);
    }

    /** Gagner des PV hors combat (Swords to Plowshares) : `LifeGained` avec les PV résultants ; un gain nul ne fait rien (CR 119.3). */
    public function gainLife(int $playerId, int $amount, string $source): void
    {
        if ($amount <= 0) {
            return;
        }
        $p = $this->player($playerId);
        $p->life += $amount;
        $this->emit('LifeGained', ['player' => $playerId, 'amount' => $amount, 'life' => $p->life, 'source' => $source]);
    }

    /** Perte de PV hors dégâts (Vampiric Tutor) : `LifeLost`. Les PV peuvent passer sous 0 : les SBA font perdre le joueur. */
    public function loseLife(int $playerId, int $amount, string $source): void
    {
        if ($amount <= 0) {
            return;
        }
        $p = $this->player($playerId);
        $p->life -= $amount;
        $this->emit('LifeLost', ['player' => $playerId, 'amount' => $amount, 'life' => $p->life, 'source' => $source]);
    }

    /** Dégâts qu'une source inflige à son propre contrôleur (Ancient Tomb, Talismans) : `DamageDealt` vers lui. */
    public function damageSelf(int $playerId, int $amount, string $source): void
    {
        $p = $this->player($playerId);
        $p->life -= $amount;
        $this->emit('DamageDealt', ['source' => $source, 'target' => 'player:' . $playerId, 'amount' => $amount, 'life' => $p->life]);
    }

    /**
     * Applique les blessures d'un paiement (`PlayerState::payWithPlan()['pain']`) : PV payés puis dégâts, source par source.
     *
     * @param list<array{source: string, lifeCost: int, selfDamage: int}> $pain
     */
    public function hurtByMana(int $playerId, array $pain): void
    {
        foreach ($pain as ['source' => $source, 'lifeCost' => $life, 'selfDamage' => $damage]) {
            if ($life > 0) {
                $this->payLife($playerId, $life, $source);
            }
            if ($damage > 0) {
                $this->damageSelf($playerId, $damage, $source);
            }
        }
    }

    public function enterBattlefield(int $playerId, CardInstance $card): void
    {
        $card->enteredTurn = $this->turn;
        $this->player($playerId)->battlefield[] = $card;
        $this->queueTriggers($playerId, $card, TriggeredAbility::ETB);
    }

    /**
     * Met en file les triggers de `$card` pour l'événement `$event` (constantes de `TriggeredAbility`). Pour `cast`,
     * `$spell` est le sort lancé : seuls les triggers dont le filtre l'accepte sont retenus. La source est copiée
     * (information de dernière connaissance, et aucun état partagé avec le champ de bataille : voir AbilityManager).
     */
    public function queueTriggers(int $controller, CardInstance $card, string $event, ?CardInstance $spell = null, ?int $opponent = null): void
    {
        foreach ($card->triggers as $index => $trigger) {
            if ($trigger->event === $event && ($spell === null || $trigger->matches($spell))
                && ($trigger->filter !== TriggeredAbility::TAPPED || $card->tapped)) {
                $this->pendingTriggers[] = new PendingTrigger('tr' . $this->nextTriggerId++, $controller, clone $card, $index, opponent: $opponent);
            }
        }
    }

    /**
     * « Quand un adversaire lance un sort » (Rhystic Study) : met en file les triggers `opponent_cast` des permanents de chaque
     * autre joueur en vie, avec le lanceur `$caster` (il paiera ou non à la résolution).
     */
    public function queueOpponentCastTriggers(int $caster, CardInstance $spell): void
    {
        foreach ($this->players as $p) {
            if ($p->id === $caster || $p->lost) {
                continue;
            }
            foreach ($p->battlefield as $card) {
                if ($card->triggers !== []) {
                    $this->queueTriggers($p->id, $card, TriggeredAbility::OPPONENT_CAST, $spell, $caster);
                }
            }
        }
    }

    /**
     * Met en file, pour chaque permanent du joueur, les triggers de l'événement `$event` (`upkeep`, `end_step`, `discard`,
     * `cast` avec le sort lancé `$spell`), dans l'ordre du champ de bataille.
     */
    public function queuePlayerTriggers(int $playerId, string $event, ?CardInstance $spell = null): void
    {
        foreach ($this->player($playerId)->battlefield as $card) {
            if ($card->triggers !== []) {
                $this->queueTriggers($playerId, $card, $event, $spell);
            }
        }
    }

    /**
     * Défausse une carte de la main (cimetière via `sendToGraveyard()`), journalise `CardDiscarded` et met en file les
     * triggers `discard` du joueur. Seul chemin de la défausse (cleanup, coût de capacité).
     */
    public function discard(int $playerId, string $cardId): void
    {
        $p = $this->player($playerId);
        $card = $p->takeFromHand($cardId) ?? throw new \DomainException("Carte à défausser absente de la main : $cardId");
        $destination = $this->sendToGraveyard($playerId, $card);
        $this->emit('CardDiscarded', ['player' => $playerId, 'card' => $card->toPublic(), 'destination' => $destination, 'hand' => count($p->hand)]);
        $this->queuePlayerTriggers($playerId, TriggeredAbility::DISCARD);
    }

    /**
     * Crée un trigger retardé (CR 603.7) contrôlé par `$controller`, de source `$source` (le sort qui le crée, copié) :
     * il attend son événement dans `$delayedTriggers` (`DelayedTriggerCreated`).
     */
    public function addDelayedTrigger(int $controller, CardInstance $source, TriggeredAbility $ability): void
    {
        $pending = new PendingTrigger('tr' . $this->nextTriggerId++, $controller, clone $source, 0, $ability);
        $this->delayedTriggers[] = $pending;
        $this->emit('DelayedTriggerCreated', ['player' => $controller, 'id' => $pending->id, 'card' => $source->toPublic(), 'trigger' => $ability->toArray(), 'label' => $ability->label()]);
    }

    /**
     * Les triggers retardés de l'événement `$event` (`next_upkeep`, `next_main`) du joueur actif se déclenchent : ils passent
     * dans `$pendingTriggers` (mis sur la pile avant la prochaine priorité). Ceux d'un joueur éliminé disparaissent.
     */
    public function fireDelayedTriggers(string $event): void
    {
        if ($this->delayedTriggers === []) {
            return;
        }
        $kept = [];
        foreach ($this->delayedTriggers as $pending) {
            if ($this->player($pending->controller)->lost) {
                continue;
            }
            if ($pending->controller === $this->activePlayer && $pending->ability()->event === $event) {
                $this->pendingTriggers[] = $pending;
            } else {
                $kept[] = $pending;
            }
        }
        $this->delayedTriggers = $kept;
    }

    /**
     * Renvoie un permanent de `$playerId` dans la main de son propriétaire (coût de Daze, Gush) ; un jeton cesse d'exister
     * (`destination: none`). Il redevient un objet neuf (dégagé, sans dégâts ni bonus). `PermanentReturnedToHand`.
     */
    public function returnToHand(int $playerId, string $cardId, string $source): void
    {
        $p = $this->player($playerId);
        $card = $p->takeFromBattlefield($cardId) ?? throw new \DomainException("Permanent à renvoyer en main introuvable : $cardId");
        $card->tapped = false;
        $card->damage = 0;
        $card->deathtouchDamage = false;
        $card->powerBonus = 0;
        $card->toughnessBonus = 0;
        if (!$card->token) {
            $p->hand[] = $card;
        }
        $this->emit('PermanentReturnedToHand', ['player' => $playerId, 'card' => $card->toPublic(), 'destination' => $card->token ? 'none' : 'hand', 'hand' => count($p->hand), 'source' => $source]);
    }

    /** Change les stops d'un joueur ; `null` (ou une config équivalente au défaut) revient au comportement par défaut. */
    public function setStops(int $playerId, ?StopConfig $config): void
    {
        if (!isset($this->players[$playerId])) {
            throw new \InvalidArgumentException("Joueur inconnu : $playerId");
        }
        if ($config === null || $config->isDefault()) {
            unset($this->stops[$playerId]);
        } else {
            $this->stops[$playerId] = $config;
            ksort($this->stops);
        }
    }

    public function toArray(): array
    {
        $d = [
            'version' => self::FORMAT_VERSION,
            'turn' => $this->turn,
            'activePlayer' => $this->activePlayer,
            'phase' => $this->phase->value,
            'stepStarted' => $this->stepStarted,
            'priority' => $this->priority->toArray(),
            'combat' => $this->combat->toArray(),
            'pendingTriggers' => array_map(static fn (PendingTrigger $t) => $t->toArray(), $this->pendingTriggers),
            'nextTriggerId' => $this->nextTriggerId,
            'triggerResumeHolder' => $this->triggerResumeHolder,
            'cleanupRound' => $this->cleanupRound,
            'stack' => array_map(static fn (StackItem $i) => $i->toArray(), $this->stack),
            'landsPlayed' => $this->landsPlayed,
            'nextStackId' => $this->nextStackId,
            'nextTokenId' => $this->nextTokenId,
            'eventSeq' => $this->eventSeq,
            'over' => $this->over,
            'winner' => $this->winner,
            'maxTurns' => $this->maxTurns,
            'rng' => $this->rng->state(),
            'controllers' => $this->controllers,
            'players' => array_map(static fn (PlayerState $p) => $p->toArray(), $this->players),
        ];
        if ($this->stops !== []) {
            $d['stops'] = array_map(static fn (StopConfig $c) => $c->toArray(), $this->stops);
        }
        if ($this->mulligan !== null) {
            $d['mulligan'] = $this->mulligan;
        }
        if ($this->scryPending) {
            $d['scryPending'] = true;
        }
        if ($this->paymentPending) {
            $d['paymentPending'] = true;
        }
        if ($this->selection !== null) {
            $d['selection'] = $this->selection;
        }
        if ($this->delayedTriggers !== []) {
            $d['delayedTriggers'] = array_map(static fn (PendingTrigger $t) => $t->toArray(), $this->delayedTriggers);
        }
        if ($this->sbaResume !== null) {
            $d['sbaResume'] = $this->sbaResume->toArray();
        }

        return $d;
    }

    public static function fromArray(array $d): self
    {
        // Une ancre écrite sans version est un état d'avant le versionnage (format 1).
        $version = (int) ($d['version'] ?? 1);
        if ($version > self::FORMAT_VERSION) {
            throw new \DomainException("Format d'état $version plus récent que le moteur (" . self::FORMAT_VERSION . ')');
        }
        $players = [];
        foreach ($d['players'] as $id => $p) {
            $players[(int) $id] = PlayerState::fromArray($p);
        }
        $controllers = [];
        foreach ($d['controllers'] as $id => $key) {
            $controllers[(int) $id] = $key;
        }

        $s = new self($players, Rng::fromState((int) $d['rng']), $controllers, (int) $d['maxTurns']);
        $s->turn = (int) $d['turn'];
        $s->activePlayer = (int) $d['activePlayer'];
        $s->phase = Phase::from($d['phase']);
        $s->stepStarted = (bool) $d['stepStarted'];
        $s->priority = PriorityState::fromArray($d['priority']);
        $s->combat = CombatState::fromArray($d['combat'] ?? []);
        $s->pendingTriggers = array_map([PendingTrigger::class, 'fromArray'], $d['pendingTriggers'] ?? []);
        $s->nextTriggerId = (int) ($d['nextTriggerId'] ?? 1);
        $s->triggerResumeHolder = isset($d['triggerResumeHolder']) ? (int) $d['triggerResumeHolder'] : null;
        $s->cleanupRound = (bool) ($d['cleanupRound'] ?? false);
        $s->sbaResume = isset($d['sbaResume']) ? PriorityState::fromArray($d['sbaResume']) : null;
        $s->scryPending = (bool) ($d['scryPending'] ?? false);
        $s->paymentPending = (bool) ($d['paymentPending'] ?? false);
        if (isset($d['selection'])) {
            $sel = $d['selection'];
            $s->selection = ['player' => (int) $sel['player'], 'kind' => (string) $sel['kind'], 'count' => (int) $sel['count'], 'source' => (string) $sel['source'], 'draw' => (int) $sel['draw']];
        }
        $s->delayedTriggers = array_map([PendingTrigger::class, 'fromArray'], $d['delayedTriggers'] ?? []);
        $s->stack = array_map([StackItem::class, 'fromArray'], $d['stack']);
        $s->landsPlayed = (int) $d['landsPlayed'];
        $s->nextStackId = (int) $d['nextStackId'];
        $s->nextTokenId = (int) ($d['nextTokenId'] ?? 1);
        $s->eventSeq = (int) $d['eventSeq'];
        $s->over = (bool) $d['over'];
        $s->winner = $d['winner'] === null ? null : (int) $d['winner'];
        foreach ($d['stops'] ?? [] as $id => $config) {
            $s->stops[(int) $id] = StopConfig::fromArray($config);
        }
        if (isset($d['mulligan'])) {
            $s->mulligan = [];
            foreach ($d['mulligan'] as $id => $m) {
                $s->mulligan[(int) $id] = ['taken' => (int) $m['taken'], 'kept' => (bool) $m['kept']];
            }
        }

        return $s;
    }
}
