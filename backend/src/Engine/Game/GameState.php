<?php

declare(strict_types=1);

namespace App\Engine\Game;

use App\Engine\Ability\ActivatedAbility;
use App\Engine\Ability\TriggeredAbility;
use App\Engine\Card\ReplacementEffect;
use App\Engine\Event\GameEvent;
use App\Engine\Mana\ManaCost;

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
     * Chain of Vapor (kind `chain`) : `card` = le sort à copier (forme `CardInstance::toArray()`), une décision `ChainCopy`.
     * Effet de remplacement d'entrée (kind `replace`, Mox Diamond) : `card` = le permanent qui devait arriver, `owner` son
     * propriétaire s'il n'est pas `player`, une décision `ReplaceChoice` (voir `replaceEntering()`).
     *
     * @var array{player: int, kind: string, count: int, source: string, draw: int, card?: array<string, mixed>, owner?: int}|null
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
     * Sorts lancés ce tour par joueur (Kraum : deuxième sort d'un adversaire), remis à zéro à chaque nouveau tour. Une copie
     * n'est pas lancée. Sérialisé sous `spellsCast`, omis quand vide.
     *
     * @var array<int, int>
     */
    public array $spellsCast = [];
    /**
     * Sorts non-créature lancés ce tour par joueur (Esper Sentinel : premier sort non-créature d'un adversaire), remis à zéro à
     * chaque nouveau tour. Sérialisé sous `noncreatureCast`, omis quand vide.
     *
     * @var array<int, int>
     */
    public array $noncreatureCast = [];
    /**
     * Cartes piochées ce tour par joueur, hors avant-partie (Faerie Mastermind : deuxième carte d'un adversaire), remis à zéro à
     * chaque nouveau tour. Sérialisé sous `cardsDrawn`, omis quand vide.
     *
     * @var array<int, int>
     */
    public array $cardsDrawn = [];
    /**
     * Sources dont un trigger « une seule fois par tour » s'est déjà déclenché ce tour (Tataru Taru), remis à zéro à chaque
     * nouveau tour. Sérialisé sous `onceTriggered`, omis quand vide.
     *
     * @var list<string>
     */
    public array $onceTriggered = [];
    /**
     * Joueurs blessés au combat ce tour (Tymna), remis à zéro à chaque nouveau tour. Sérialisé sous `combatDamaged`, omis quand vide.
     *
     * @var list<int>
     */
    public array $combatDamaged = [];
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
    /**
     * Joueurs dont les adversaires ne peuvent pas lancer de sort non-créature ce tour (Ranger-Captain of Eos sacrifié) ; vidé au
     * cleanup. Sérialisé sous `noncreatureLocks`, omis quand vide.
     *
     * @var list<int>
     */
    public array $noncreatureLocks = [];
    /**
     * Joueurs qui ne peuvent pas lancer de sort ce tour (Silence, Orim's Chant) ; vidé au cleanup. Sérialisé sous `castLocks`,
     * omis quand vide.
     *
     * @var list<int>
     */
    public array $castLocks = [];
    /**
     * Joueurs qui lancent leurs sorts comme s'ils avaient le flash ce tour (Borne Upon a Wind) ; vidé au cleanup. Sérialisé
     * sous `flashGrants`, omis quand vide.
     *
     * @var list<int>
     */
    public array $flashGrants = [];
    /**
     * Permissions de jouer une carte exilée (CR 601.2, 305.1 : Ragavan, Opposition Agent) : `player` peut lancer la carte `card`
     * (dans la zone d'exil de son propriétaire), et aussi la jouer si c'est un terrain quand `lands` est vrai ; `anyMana` : son
     * mana se dépense comme s'il était de n'importe quel type (couleurs du coût payables par n'importe quel mana). `until` :
     * `turn` (vidé au cleanup) ou `exiled` (tant que la carte reste en exil). Une permission tombe quand la carte quitte l'exil.
     * Sérialisé sous `exilePlays`, omis quand vide.
     *
     * @var list<array{card: string, player: int, until: string, lands?: true, anyMana?: true}>
     */
    public array $exilePlays = [];
    /** Les créatures ne peuvent pas attaquer ce tour (Orim's Chant avec kicker) ; remis à faux au cleanup. Sérialisé sous `attackLocked`, omis à faux. */
    public bool $attackLocked = false;

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
     * vérification des SBA (CR 704.5b), pas tout de suite. Hors avant-partie (`$setup` : main de départ, mulligan), la carte est
     * comptée dans `$cardsDrawn` et les triggers `opponent_draw` des adversaires sont mis en file ; `$drawStep` = la pioche de
     * l'étape de pioche (action basée sur le tour, donc toujours la première carte piochée dans cette étape).
     */
    public function drawCard(int $playerId, bool $setup = false, bool $drawStep = false): bool
    {
        $p = $this->player($playerId);
        $card = array_shift($p->library);
        if ($card === null) {
            $p->drewFromEmpty = true;

            return false;
        }
        $p->hand[] = $card;
        $this->emit('CardDrawn', ['player' => $playerId, 'card' => $card->toPublic(), 'library' => count($p->library)]);
        if (!$setup) {
            $this->cardsDrawn[$playerId] = ($this->cardsDrawn[$playerId] ?? 0) + 1;
            foreach ($this->players as $o) {
                if ($o->id === $playerId || $o->lost) {
                    continue;
                }
                foreach ($o->battlefield as $permanent) {
                    if ($permanent->triggers !== []) {
                        $this->queueTriggers($o->id, $permanent, TriggeredAbility::OPPONENT_DRAW, opponent: $playerId, drawStepFirst: $drawStep);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Ajoute `$n` marqueurs de la sorte `$kind` (`CardInstance::PLUS_ONE`, `AGE`) au permanent `$card` de `$playerId` ;
     * `CountersAdded` porte le total résultant et, pour une créature, sa force / endurance résultantes.
     */
    public function addCounters(int $playerId, CardInstance $card, string $kind, int $n): void
    {
        $card->counters[$kind] = ($card->counters[$kind] ?? 0) + $n;
        $this->emit('CountersAdded', ['player' => $playerId, 'card' => $card->id, 'kind' => $kind, 'count' => $card->counters[$kind]]
            + ($card->isCreature() ? ['power' => $card->currentPower(), 'toughness' => $card->currentToughness()] : []));
    }

    /** Retire un marqueur `$kind` du permanent `$card` de `$playerId` (coût de Wishclaw Talisman) ; `CountersRemoved` porte le total restant. */
    public function removeCounter(int $playerId, CardInstance $card, string $kind): void
    {
        $left = max(0, ($card->counters[$kind] ?? 0) - 1);
        if ($left === 0) {
            unset($card->counters[$kind]);
        } else {
            $card->counters[$kind] = $left;
        }
        $this->emit('CountersRemoved', ['player' => $playerId, 'card' => $card->id, 'kind' => $kind, 'count' => $left]);
    }

    /**
     * Envoie une carte au cimetière de son propriétaire, SAUF un jeton qui cesse d'exister (règles 111.7 et 704.5d).
     * Un commandant va bien au cimetière (ses triggers « meurt » se déclenchent) ; les SBA le renvoient ensuite en zone
     * de commandement (CR 903.9a, voir StateBasedActions). Retourne la destination réelle : 'graveyard' | 'none'.
     */
    public function sendToGraveyard(int $ownerId, CardInstance $card): string
    {
        $card = $card->original ?? $card; // Mockingbird : redevient la carte
        $card->grantedAbilities = [];
        $card->counters = [];
        $card->dashed = false;
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
        $card = $card->original ?? $card; // Mockingbird : redevient la carte
        $card->grantedAbilities = [];
        $card->counters = [];
        $card->dashed = false;
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
        $owner = $card->ownerOr($playerId);
        $destination = $this->exile($owner, $card);
        $this->emit('CardExiled', [
            'player' => $playerId,
            'card' => $card->printed()->toPublic(),
            'from' => 'battlefield',
            'destination' => $destination,
            'source' => $source,
            'exile' => count($this->player($owner)->exile),
        ] + self::ownerField($playerId, $card));

        return true;
    }

    /**
     * Champ `owner` d'un événement de départ du champ de bataille : présent seulement quand le propriétaire n'est pas le
     * contrôleur `$playerId` (permanent dont le contrôle a changé), pour que le reducer range la carte dans la bonne zone.
     *
     * @return array{owner?: int}
     */
    public static function ownerField(int $playerId, CardInstance $card): array
    {
        $owner = $card->ownerOr($playerId);

        return $owner === $playerId ? [] : ['owner' => $owner];
    }

    /**
     * Un adversaire (ou n'importe quel joueur) prend le contrôle d'un permanent (Wishclaw Talisman ; CR 108.4) : il passe du
     * champ de bataille de `$from` à celui de `$to`, sans changer de zone (ni triggers d'entrée, ni marqueurs perdus). Il a le
     * mal d'invocation chez son nouveau contrôleur (CR 302.6) et sort du combat (CR 506.4). Son propriétaire ne change pas :
     * quand il quitte le champ de bataille, il va dans la zone de son propriétaire. `PermanentControlChanged`.
     * Retourne false si le permanent n'est plus là.
     */
    public function gainControl(int $from, string $cardId, int $to, string $source): bool
    {
        if ($from === $to || ($card = $this->player($from)->takeFromBattlefield($cardId)) === null) {
            return false;
        }
        $card->enteredTurn = $this->turn;
        unset($this->combat->attackers[$cardId]);
        foreach ($this->combat->blockers as $attacker => $list) {
            $this->combat->blockers[$attacker] = array_values(array_diff($list, [$cardId]));
        }
        $this->player($to)->battlefield[] = $card;
        $this->emit('PermanentControlChanged', ['player' => $to, 'from' => $from, 'card' => $card->toPublic(), 'tapped' => $card->tapped, 'source' => $source]);

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
            $destination = $this->sendToGraveyard($card->ownerOr($playerId), $card);
            $this->emit('PermanentSacrificed', ['player' => $playerId, 'card' => $card->printed()->toPublic(), 'destination' => $destination] + self::ownerField($playerId, $card));
            if ($card->isCreature()) {
                $this->queueTriggers($playerId, $card, TriggeredAbility::DIES);
            }
        }
    }

    /** Crée un jeton Treasure sur le champ de bataille du joueur (`TokenCreated`), engagé si `$tapped` (Tataru Taru). */
    public function createTreasure(int $playerId, bool $tapped = false): CardInstance
    {
        return $this->createToken($playerId, 'Treasure', CardInstance::ARTIFACT, abilities: [ActivatedAbility::treasure()], subtypes: [CardInstance::ARTIFACT => ['Treasure']], tapped: $tapped);
    }

    /**
     * Crée un jeton sur le champ de bataille du joueur (id `p<joueur>_t<n>`, déterministe) et journalise `TokenCreated`.
     *
     * @param list<ActivatedAbility>      $abilities
     * @param array<string, list<string>> $subtypes
     */
    public function createToken(int $playerId, string $name, string $type, int $power = 0, int $toughness = 0, array $abilities = [], array $subtypes = [], array $keywords = [], bool $tapped = false, ?int $attacking = null): CardInstance
    {
        $token = new CardInstance(
            sprintf('p%d_t%d', $playerId, $this->nextTokenId++), $name, $type,
            power: $power, toughness: $toughness, abilities: $abilities, token: true, subtypes: $subtypes, keywords: $keywords, tapped: $tapped,
        );
        $this->enterBattlefield($playerId, $token);
        if ($attacking !== null) {
            $this->combat->attackers[$token->id] = $attacking; // créé attaquant (Mobilize) : jamais déclaré, aucun trigger d'attaque
        }
        $this->emit('TokenCreated', ['player' => $playerId, 'card' => $token->toPublic()] + ($tapped ? ['tapped' => true] : []) + ($attacking !== null ? ['attacking' => $attacking] : []));

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
    public function putLandOntoBattlefield(int $playerId, CardInstance $card, ?bool $payLife, string $event, bool $tapped = false, ?int $fromExileOf = null): void
    {
        $pays = $card->untappedLifeCost > 0 && $payLife === true && $this->player($playerId)->life >= $card->untappedLifeCost;
        $tapped = $tapped || ($card->untappedLifeCost > 0 && !$pays)
            || ($card->tappedAfterOwnTurn > 0 && $this->player($playerId)->turnsTaken > $card->tappedAfterOwnTurn); // Starting Town
        $card->tapped = $tapped;
        $this->enterBattlefield($playerId, $card);
        $this->emit($event, ['player' => $playerId, 'card' => $card->toPublic()] + ($tapped ? ['tapped' => true] : [])
            + ($event === 'LandFetched' ? ['library' => count($this->player($playerId)->library)] : [])
            + ($fromExileOf !== null ? ['fromExile' => true, 'owner' => $fromExileOf] : [])); // Opposition Agent
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

    /**
     * Détruit un permanent de `$playerId` (Pyroblast, Red Elemental Blast) : champ de bataille -> cimetière via `sendToGraveyard()`
     * (un commandant y passe puis revient par les SBA), `PermanentDestroyed`, triggers « meurt » si c'est une créature. Retourne
     * false si le permanent n'est plus là.
     */
    public function destroyPermanent(int $playerId, string $cardId, string $source): bool
    {
        $card = $this->player($playerId)->takeFromBattlefield($cardId);
        if ($card === null) {
            return false;
        }
        $card->damage = 0;
        $card->deathtouchDamage = false;
        $card->tapped = false;
        $destination = $this->sendToGraveyard($card->ownerOr($playerId), $card);
        $this->emit('PermanentDestroyed', ['player' => $playerId, 'card' => $card->printed()->toPublic(), 'destination' => $destination, 'source' => $source] + self::ownerField($playerId, $card));
        if ($card->isCreature()) {
            $this->queueTriggers($playerId, $card, TriggeredAbility::DIES);
        }

        return true;
    }

    /**
     * « Quand vous jouez un autre terrain » (City of Traitors) : met en file les triggers `land_played` des permanents du joueur,
     * sauf ceux du terrain qui vient d'être joué.
     */
    public function queueLandPlayedTriggers(int $playerId, CardInstance $played): void
    {
        foreach ($this->player($playerId)->battlefield as $card) {
            if ($card !== $played && $card->triggers !== []) {
                $this->queueTriggers($playerId, $card, TriggeredAbility::LAND_PLAYED);
            }
        }
    }

    /** Un adversaire de `$playerId` l'empêche-t-il de lancer des sorts non-créature ce tour (`$noncreatureLocks`) ? */
    public function castLockedFor(int $playerId): bool
    {
        foreach ($this->noncreatureLocks as $locker) {
            if ($locker !== $playerId && !$this->player($locker)->lost) {
                return true;
            }
        }

        return false;
    }

    /**
     * `$playerId` peut-il lancer `$card` (X = `$x`) au regard des interdictions en cours ? Silence / Orim's Chant (`$castLocks`),
     * Ranger-Captain of Eos (`$noncreatureLocks`), et capacités statiques des permanents de ses adversaires : Voice of Victory
     * (aucun sort pendant le tour de son contrôleur), Lavinia (aucun sort non-créature de valeur de mana supérieure à ses
     * terrains). Le moment (flash) n'est pas vérifié ici : voir `castsAtInstantSpeed()`.
     */
    public function canCast(int $playerId, CardInstance $card, int $x = 0): bool
    {
        if (in_array($playerId, $this->castLocks, true) || (!$card->isCreature() && $this->castLockedFor($playerId))) {
            return false;
        }
        $lands = null;
        foreach ($this->players as $o) {
            if ($o->id === $playerId || $o->lost) {
                continue;
            }
            foreach ($o->battlefield as $permanent) {
                if ($permanent->statics === []) {
                    continue;
                }
                if ($permanent->hasStatic(CardInstance::STATIC_OPPONENTS_NO_CAST_YOUR_TURN) && $this->activePlayer === $o->id) {
                    return false;
                }
                if ($permanent->hasStatic(CardInstance::STATIC_OPPONENTS_MV_LANDS) && !$card->isCreature()) {
                    $lands ??= count(array_filter($this->player($playerId)->battlefield, static fn (CardInstance $c) => $c->isLand()));
                    if ($card->manaCost()->manaValue($x) > $lands) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /** `$playerId` peut-il lancer `$card` à tout moment où il a la priorité (éphémère, flash, Borne Upon a Wind, Valley Floodcaller) ? */
    public function castsAtInstantSpeed(int $playerId, CardInstance $card): bool
    {
        if ($card->isInstantSpeed() || in_array($playerId, $this->flashGrants, true)) {
            return true;
        }
        if ($card->isCreature()) {
            return false;
        }
        foreach ($this->player($playerId)->battlefield as $permanent) {
            if ($permanent->hasStatic(CardInstance::STATIC_NONCREATURE_FLASH)) {
                return true;
            }
        }

        return false;
    }

    /** Remplace l'objet de pile de même id (cibles changées, contrôle pris). */
    public function replaceStackItem(StackItem $item): void
    {
        foreach ($this->stack as $i => $existing) {
            if ($existing->id === $item->id) {
                $this->stack[$i] = $item;

                return;
            }
        }
        throw new \DomainException("Objet de pile introuvable : {$item->id}");
    }

    /**
     * Meule (CR 701.13) : les `$n` cartes du dessus de la bibliothèque de `$playerId` vont dans son cimetière (`CardsMilled`).
     * Une bibliothèque vide ne fait rien perdre (seule la pioche le fait).
     */
    public function mill(int $playerId, int $n, string $source): void
    {
        $p = $this->player($playerId);
        $milled = array_splice($p->library, 0, min($n, count($p->library)));
        foreach ($milled as $card) {
            $this->sendToGraveyard($playerId, $card);
        }
        $this->emit('CardsMilled', ['player' => $playerId, 'cards' => array_map(static fn (CardInstance $c) => $c->toPublic(), $milled), 'library' => count($p->library), 'source' => $source]);
    }

    /**
     * Effet de remplacement (CR 614) qui s'applique à `$card` pour l'événement `$event` (`ReplacementEffect::ENTERS`), ou null.
     * Seuls les remplacements « de soi » de la carte existent (CR 614.15, Mox Diamond) ; ceux d'autres permanents (Necropotence,
     * Opposition Agent) se chercheront ici, sur les champs de bataille.
     */
    public function replacementFor(CardInstance $card, string $event): ?ReplacementEffect
    {
        foreach ($card->replacements as $replacement) {
            if ($replacement->event === $event) {
                return $replacement;
            }
        }

        return null;
    }

    /**
     * Applique le remplacement d'entrée `$replacement` à `$card`, un permanent qui devait arriver sous le contrôle de
     * `$playerId` (déjà hors de toute zone). Mox Diamond : avec une carte de terrain en main, le joueur choisit au milieu de la
     * résolution (`$selection`, kind `replace`, décision `ReplaceChoice` : `finishReplacement()`) ; sans terrain, la carte va
     * directement au cimetière (`ReplacementApplied`).
     */
    public function replaceEntering(int $playerId, int $ownerId, CardInstance $card, ReplacementEffect $replacement, int $manaSpent = 0): void
    {
        if ($replacement->instead === ReplacementEffect::COPY_CREATURE) {
            // Mockingbird : choisir une créature de valeur de mana ≤ mana dépensé, ou arriver telle quelle.
            if ($this->copyCandidates($manaSpent) === []) {
                $this->finishReplacement($playerId, $ownerId, $card, null);

                return;
            }
            $this->selection = ['player' => $playerId, 'kind' => 'replace', 'instead' => ReplacementEffect::COPY_CREATURE, 'maxValue' => $manaSpent,
                'count' => 1, 'source' => $card->name, 'draw' => 0, 'card' => $card->toArray()] + ($ownerId === $playerId ? [] : ['owner' => $ownerId]);

            return;
        }
        $lands = array_filter($this->player($playerId)->hand, static fn (CardInstance $c) => $c->isLand());
        if ($replacement->instead === ReplacementEffect::DISCARD_LAND && $lands !== []) {
            $this->selection = ['player' => $playerId, 'kind' => 'replace', 'count' => 1, 'source' => $card->name, 'draw' => 0, 'card' => $card->toArray()]
                + ($ownerId === $playerId ? [] : ['owner' => $ownerId]);

            return;
        }
        $this->finishReplacement($playerId, $ownerId, $card, null);
    }

    /**
     * Fin d'un remplacement d'entrée : `$discard` (id d'une carte de terrain de la main) est défaussé et la carte arrive sur le
     * champ de bataille (ses triggers d'entrée se déclenchent) ; sans défausse, elle va au cimetière de son propriétaire `$ownerId`.
     */
    public function finishReplacement(int $playerId, int $ownerId, CardInstance $card, ?string $discard, ?string $copyId = null): void
    {
        $discarded = null;
        $copied = null;
        if ($this->replacementFor($card, ReplacementEffect::ENTERS)?->instead === ReplacementEffect::COPY_CREATURE) {
            $source = $copyId === null ? null : ($this->findPermanent($copyId) ?? throw new \DomainException("Créature à copier introuvable : $copyId"));
            $entering = $source === null ? $card : self::copyCard($card->id, $source, $card, [CardInstance::CREATURE => ['Bird']], [CardInstance::FLYING]);
            $copied = $source?->id;
            $this->enterBattlefield($playerId, $entering);
            $card = $entering;
            $destination = 'battlefield';
        } elseif ($discard !== null) {
            $land = $this->player($playerId)->findInHand($discard);
            if ($land === null || !$land->isLand()) {
                throw new \DomainException("Carte de terrain à défausser absente de la main : $discard");
            }
            $discarded = $land->toPublic();
            $this->discard($playerId, $discard);
            $this->enterBattlefield($playerId, $card);
            $destination = 'battlefield';
        } else {
            $destination = $this->sendToGraveyard($ownerId, $card);
        }
        $this->emit('ReplacementApplied', [
            'player' => $playerId,
            'card' => $card->toPublic(),
            'event' => ReplacementEffect::ENTERS,
            'destination' => $destination,
        ] + ($ownerId === $playerId ? [] : ['owner' => $ownerId]) + ($discarded === null ? [] : ['discarded' => $discarded])
            + ($copied === null ? [] : ['copyOf' => $copied]));
    }

    /** Créatures sur le champ de bataille de valeur de mana ≤ `$maxValue` (Mockingbird), dans l'ordre des joueurs. @return list<CardInstance> */
    public function copyCandidates(int $maxValue): array
    {
        $out = [];
        foreach ($this->players as $p) {
            foreach ($p->battlefield as $c) {
                if ($c->isCreature() && $c->manaCost()->manaValue() <= $maxValue) {
                    $out[] = $c;
                }
            }
        }

        return $out;
    }

    /** Permanent d'id `$cardId` sur n'importe quel champ de bataille, ou null. */
    public function findPermanent(string $cardId): ?CardInstance
    {
        foreach ($this->players as $p) {
            if (($card = $p->findOnBattlefield($cardId)) !== null) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Copie de `$source` (valeurs copiables, CR 707.2 : nom, types, supertypes, sous-types, force / endurance de base, coût,
     * mots-clés, capacités et triggers), d'id `$id`, avec des sous-types et mots-clés en plus (CR 707.9b, Mockingbird : Bird,
     * vol) ; `$original` est la carte qu'elle redevient en quittant le champ de bataille (null : un jeton).
     *
     * @param array<string, list<string>> $extraSubtypes
     * @param list<string>                $extraKeywords
     */
    public static function copyCard(string $id, CardInstance $source, ?CardInstance $original, array $extraSubtypes = [], array $extraKeywords = []): CardInstance
    {
        $subtypes = $source->subtypes;
        foreach ($extraSubtypes as $type => $names) {
            $subtypes[$type] = array_values(array_unique([...($subtypes[$type] ?? []), ...$names]));
        }
        $copy = new CardInstance(
            $id, $source->name, $source->types, $source->cost,
            power: $source->power, toughness: $source->toughness, manaCost: $source->manaCost, produces: $source->produces,
            abilities: $source->abilities, token: $original === null, triggers: $source->triggers, subtypes: $subtypes,
            keywords: array_values(array_unique([...$source->keywords, ...$extraKeywords])), supertypes: $source->supertypes,
            effects: $source->effects, statics: $source->statics, altCasts: $source->altCasts,
        );
        $copy->original = $original;

        return $copy;
    }

    public function enterBattlefield(int $playerId, CardInstance $card): void
    {
        $card->enteredTurn = $this->turn;
        $this->player($playerId)->battlefield[] = $card;
        foreach ($card->entersWithCounters as $kind => $n) {
            $this->addCounters($playerId, $card, $kind, $n); // « enters with three wish counters » (Wishclaw Talisman)
        }
        $this->queueTriggers($playerId, $card, TriggeredAbility::ETB);
    }

    /**
     * Met en file les triggers de `$card` pour l'événement `$event` (constantes de `TriggeredAbility`). Pour `cast`,
     * `$spell` est le sort lancé : seuls les triggers dont le filtre l'accepte sont retenus. La source est copiée
     * (information de dernière connaissance, et aucun état partagé avec le champ de bataille : voir AbilityManager).
     */
    public function queueTriggers(int $controller, CardInstance $card, string $event, ?CardInstance $spell = null, ?int $opponent = null, ?string $stackId = null, bool $manaSpent = true, bool $drawStepFirst = false): void
    {
        foreach ($card->triggers as $index => $trigger) {
            if ($trigger->event === $event && ($spell === null || $trigger->matches($spell))
                && ($trigger->filter !== TriggeredAbility::TAPPED || $card->tapped)
                && ($trigger->filter !== TriggeredAbility::SECOND_SPELL || ($this->spellsCast[$opponent] ?? 0) === 2)
                && ($trigger->filter !== TriggeredAbility::FIRST_NONCREATURE || ($this->noncreatureCast[$opponent] ?? 0) === 1)
                && ($trigger->filter !== TriggeredAbility::SECOND_DRAW || ($this->cardsDrawn[$opponent] ?? 0) === 2)
                && ($trigger->filter !== TriggeredAbility::NOT_DRAW_STEP_FIRST || !$drawStepFirst)
                && ($trigger->filter !== TriggeredAbility::NO_MANA || !$manaSpent)
                && ($trigger->filter !== TriggeredAbility::OFF_TURN_ONCE || ($opponent !== $this->activePlayer && !in_array($card->id, $this->onceTriggered, true)))) {
                if ($trigger->filter === TriggeredAbility::OFF_TURN_ONCE) {
                    $this->onceTriggered[] = $card->id; // Tataru Taru : « This ability triggers only once each turn. »
                }
                $this->pendingTriggers[] = new PendingTrigger('tr' . $this->nextTriggerId++, $controller, clone $card, $index, opponent: $opponent, stackId: $stackId);
            }
        }
    }

    /**
     * « Quand un adversaire lance un sort » (Rhystic Study) : met en file les triggers `opponent_cast` des permanents de chaque
     * autre joueur en vie, avec le lanceur `$caster` (il paiera ou non à la résolution), le sort sur la pile `$stackId` et
     * le fait que du mana ait été dépensé pour le lancer (Lavinia).
     */
    public function queueOpponentCastTriggers(int $caster, CardInstance $spell, ?string $stackId = null, bool $manaSpent = true): void
    {
        foreach ($this->players as $p) {
            if ($p->lost) {
                continue;
            }
            foreach ($p->battlefield as $card) {
                if ($card->triggers !== []) {
                    if ($p->id !== $caster) {
                        $this->queueTriggers($p->id, $card, TriggeredAbility::OPPONENT_CAST, $spell, $caster, $stackId, $manaSpent);
                    }
                    $this->queueTriggers($p->id, $card, TriggeredAbility::ANY_CAST, $spell, $caster, $stackId, $manaSpent); // Lotho
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
        $card->grantedAbilities = [];
        $card->counters = [];
        $card->dashed = false;
        $owner = $this->player($card->ownerOr($playerId));
        if (!$card->token) {
            $owner->hand[] = $card->original ?? $card; // Mockingbird : redevient la carte
        }
        $this->emit('PermanentReturnedToHand', ['player' => $playerId, 'card' => $card->printed()->toPublic(), 'destination' => $card->token ? 'none' : 'hand', 'hand' => count($owner->hand), 'source' => $source] + self::ownerField($playerId, $card));
    }

    public const EXILE_PLAY_TURN = 'turn';
    public const EXILE_PLAY_EXILED = 'exiled';

    /**
     * Donne à `$playerId` la permission de jouer `$card`, déjà dans la zone d'exil de son propriétaire (voir `$exilePlays`).
     * `ExilePlayGranted`.
     */
    public function grantExilePlay(int $playerId, CardInstance $card, string $until, string $source, bool $lands = false, bool $anyMana = false): void
    {
        $this->exilePlays[] = ['card' => $card->id, 'player' => $playerId, 'until' => $until] + ($lands ? ['lands' => true] : []) + ($anyMana ? ['anyMana' => true] : []);
        $this->emit('ExilePlayGranted', ['player' => $playerId, 'card' => $card->toPublic(), 'owner' => $card->ownerOr($playerId), 'until' => $until, 'source' => $source]
            + ($lands ? ['lands' => true] : []) + ($anyMana ? ['anyMana' => true] : []));
    }

    /**
     * Cartes exilées que `$playerId` peut jouer maintenant, avec leur permission (la première qui s'applique). Une carte qui
     * n'est plus dans une zone d'exil n'y figure pas.
     *
     * @return list<array{card: CardInstance, owner: int, lands: bool, anyMana: bool}>
     */
    public function exilePlayable(int $playerId): array
    {
        $out = [];
        $seen = [];
        foreach ($this->exilePlays as $grant) {
            if ($grant['player'] !== $playerId || isset($seen[$grant['card']])) {
                continue;
            }
            foreach ($this->players as $owner) {
                foreach ($owner->exile as $card) {
                    if ($card->id === $grant['card']) {
                        $seen[$card->id] = true;
                        $out[] = ['card' => $card, 'owner' => $owner->id, 'lands' => isset($grant['lands']), 'anyMana' => isset($grant['anyMana'])];
                        continue 3;
                    }
                }
            }
        }

        return $out;
    }

    /** Permission de `$playerId` sur la carte exilée `$cardId`, ou null (voir exilePlayable()). @return array{card: CardInstance, owner: int, lands: bool, anyMana: bool}|null */
    public function exilePlayFor(int $playerId, string $cardId): ?array
    {
        if ($this->exilePlays === []) {
            return null; // cas courant : aucun balayage des zones d'exil
        }
        foreach ($this->exilePlayable($playerId) as $play) {
            if ($play['card']->id === $cardId) {
                return $play;
            }
        }

        return null;
    }

    /** Carte que `$playerId` peut lancer : main, zone de commandement, puis carte exilée qu'il a la permission de jouer. */
    public function castable(int $playerId, string $cardId): ?CardInstance
    {
        return $this->player($playerId)->findCastable($cardId) ?? $this->exilePlayFor($playerId, $cardId)['card'] ?? null;
    }

    /**
     * Coût de lancement de `$card` par `$playerId` : `PlayerState::castCost()` (taxe de commandant), où chaque symbole coloré
     * devient générique si une permission `anyMana` s'applique (Opposition Agent : n'importe quel mana paie les couleurs).
     */
    public function castCostFor(int $playerId, CardInstance $card): ManaCost
    {
        $cost = $this->player($playerId)->castCost($card);

        return $this->exilePlays !== [] && ($this->exilePlayFor($playerId, $card->id)['anyMana'] ?? false) ? $cost->withAnyType() : $cost;
    }

    /**
     * Sort de l'exil la carte `$cardId` que `$playerId` joue en vertu d'une permission (toutes les permissions sur elle tombent :
     * c'est un nouvel objet, CR 400.7). Retourne la carte et son propriétaire, ou null sans permission.
     *
     * @return array{card: CardInstance, owner: int}|null
     */
    public function takeExilePlayable(int $playerId, string $cardId): ?array
    {
        $play = $this->exilePlayFor($playerId, $cardId);
        if ($play === null) {
            return null;
        }
        $owner = $this->player($play['owner']);
        $owner->exile = array_values(array_filter($owner->exile, static fn (CardInstance $c) => $c->id !== $cardId));
        $this->exilePlays = array_values(array_filter($this->exilePlays, static fn (array $g) => $g['card'] !== $cardId));

        return ['card' => $play['card'], 'owner' => $play['owner']];
    }

    /**
     * Opposition Agent : contrôleur d'un Opposition Agent adverse quand `$searcher` cherche dans sa bibliothèque (le premier dans
     * l'ordre du tour après lui), ou null. Approximation : « you control your opponents while they're searching » n'est pas
     * modélisé, celui qui cherche choisit la carte ; seul l'exil de ce qu'il trouve l'est.
     */
    public function searchWatcher(int $searcher): ?int
    {
        for ($id = $this->nextAlive($searcher); $id !== $searcher; $id = $this->nextAlive($id)) {
            foreach ($this->player($id)->battlefield as $card) {
                if (in_array(CardInstance::STATIC_OPPOSITION_AGENT, $card->statics, true)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Opposition Agent : la carte `$card` que `$searcher` vient de trouver (déjà sortie de sa bibliothèque) est exilée chez lui
     * (`CardExiled`, `from: library`) et `$watcher` peut la jouer tant qu'elle reste exilée, avec n'importe quel mana.
     */
    public function exileFound(int $searcher, CardInstance $card, int $watcher): void
    {
        $this->exile($searcher, $card);
        $p = $this->player($searcher);
        $this->emit('CardExiled', ['player' => $searcher, 'card' => $card->toPublic(), 'from' => 'library', 'destination' => 'exile', 'source' => 'Opposition Agent',
            'exile' => count($p->exile), 'library' => count($p->library)]);
        $this->grantExilePlay($watcher, $card, self::EXILE_PLAY_EXILED, 'Opposition Agent', true, true);
    }

    /** Cleanup : les permissions « jusqu'à la fin du tour » tombent, ainsi que celles dont la carte a quitté l'exil. */
    public function expireExilePlays(): void
    {
        if ($this->exilePlays === []) {
            return;
        }
        $exiled = [];
        foreach ($this->players as $p) {
            foreach ($p->exile as $card) {
                $exiled[$card->id] = true;
            }
        }
        $this->exilePlays = array_values(array_filter($this->exilePlays,
            static fn (array $g) => $g['until'] !== self::EXILE_PLAY_TURN && isset($exiled[$g['card']])));
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
        if ($this->spellsCast !== []) {
            $d['spellsCast'] = $this->spellsCast;
        }
        if ($this->noncreatureCast !== []) {
            $d['noncreatureCast'] = $this->noncreatureCast;
        }
        if ($this->onceTriggered !== []) {
            $d['onceTriggered'] = $this->onceTriggered;
        }
        if ($this->cardsDrawn !== []) {
            $d['cardsDrawn'] = $this->cardsDrawn;
        }
        if ($this->combatDamaged !== []) {
            $d['combatDamaged'] = $this->combatDamaged;
        }
        if ($this->noncreatureLocks !== []) {
            $d['noncreatureLocks'] = $this->noncreatureLocks;
        }
        if ($this->castLocks !== []) {
            $d['castLocks'] = $this->castLocks;
        }
        if ($this->exilePlays !== []) {
            $d['exilePlays'] = $this->exilePlays;
        }
        if ($this->flashGrants !== []) {
            $d['flashGrants'] = $this->flashGrants;
        }
        if ($this->attackLocked) {
            $d['attackLocked'] = true;
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
            $s->selection = ['player' => (int) $sel['player'], 'kind' => (string) $sel['kind'], 'count' => (int) $sel['count'], 'source' => (string) $sel['source'], 'draw' => (int) $sel['draw']]
                + (isset($sel['card']) ? ['card' => $sel['card']] : []) + (isset($sel['owner']) ? ['owner' => (int) $sel['owner']] : []);
        }
        $s->delayedTriggers = array_map([PendingTrigger::class, 'fromArray'], $d['delayedTriggers'] ?? []);
        foreach ($d['spellsCast'] ?? [] as $id => $count) {
            $s->spellsCast[(int) $id] = (int) $count;
        }
        foreach ($d['noncreatureCast'] ?? [] as $id => $count) {
            $s->noncreatureCast[(int) $id] = (int) $count;
        }
        $s->onceTriggered = $d['onceTriggered'] ?? [];
        foreach ($d['cardsDrawn'] ?? [] as $id => $count) {
            $s->cardsDrawn[(int) $id] = (int) $count;
        }
        $s->combatDamaged = array_map('intval', $d['combatDamaged'] ?? []);
        $s->stack = array_map([StackItem::class, 'fromArray'], $d['stack']);
        $s->landsPlayed = (int) $d['landsPlayed'];
        $s->nextStackId = (int) $d['nextStackId'];
        $s->nextTokenId = (int) ($d['nextTokenId'] ?? 1);
        $s->eventSeq = (int) $d['eventSeq'];
        $s->over = (bool) $d['over'];
        $s->winner = $d['winner'] === null ? null : (int) $d['winner'];
        $s->noncreatureLocks = array_map('intval', $d['noncreatureLocks'] ?? []);
        $s->castLocks = array_map('intval', $d['castLocks'] ?? []);
        $s->flashGrants = array_map('intval', $d['flashGrants'] ?? []);
        $s->exilePlays = $d['exilePlays'] ?? [];
        $s->attackLocked = (bool) ($d['attackLocked'] ?? false);
        foreach ($d['stops'] ?? [] as $id => $config) {
            $s->stops[(int) $id] = StopConfig::fromArray($config);
        }
        if (isset($d['mulligan'])) {
            $s->mulligan = [];
            foreach ($d['mulligan'] as $id => $m) {
                $s->mulligan[(int) $id] = ['taken' => (int) $m['taken'], 'kept' => (bool) $m['kept']] + (isset($m['pregame']) ? ['pregame' => $m['pregame']] : []);
            }
        }

        return $s;
    }
}
