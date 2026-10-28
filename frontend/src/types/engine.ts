// Miroir TypeScript des structures produites par le moteur PHP (voir docs/EVENTS.md).

export type CardType = 'land' | 'instant' | 'sorcery' | 'creature' | 'artifact' | 'enchantment'

/** Le moteur sérialise `type` en chaîne, ou en liste pour une carte à plusieurs types (artefact-créature). */
export const typesOf = (card: { type: CardType | CardType[] }): CardType[] => (Array.isArray(card.type) ? card.type : [card.type])
export const hasType = (card: { type: CardType | CardType[] }, t: CardType): boolean => typesOf(card).includes(t)

/** Capacité activée d'une carte (forme sérialisée par le moteur). */
export interface AbilityView {
  isManaAbility: boolean
  effect: string
  tap: boolean
  manaCost?: string | null
  /** Pour un effet de mana : alternatives produites (ex. ['{C}{C}'] ou ['{R}', '{G}']) ; suffixe `@kind` si restreinte (`{C}{C}@eldrazi`). */
  options?: string[]
  amount?: number
  /** Coût : sacrifier la source (Treasure, Mind Stone). */
  sacrifice?: boolean
  /** Coût : sacrifier une créature (Goblin Bombardment). */
  sacrificeCreature?: boolean
  /** Coût : défausser N cartes (Wild Mongrel). */
  discard?: number
  /** Activable seulement en rituel. */
  sorcerySpeed?: boolean
  /** Jeton créé (`Nom:F/E`) ou bonus (`+F/+E`) ; `oppland` / `imprint` pour un mana dont les couleurs dépendent de la partie. */
  spec?: string
  /** Activable seulement pendant l'entretien de son contrôleur (Mana Vault : payer {4} pour la dégager). */
  upkeepOnly?: boolean
  /** Activable depuis la main : `exile` (Simian Spirit Guide) ou `discard` (Channel d'Otawara). */
  fromHand?: 'exile' | 'discard'
  /** Coût : défausser toute sa main (Lion's Eye Diamond). */
  discardHand?: boolean
  /** Condition d'activation (`metalcraft` : Mox Opal). */
  requires?: string
  /** Coût réduit de {N} par créature légendaire contrôlée (Otawara). */
  legendaryDiscount?: number
  /** Coût : payer N PV (Mana Confluence, fetchlands, Necropotence). */
  lifeCost?: number
}

/** Trigger d'une carte (forme sérialisée par le moteur). */
export interface TriggerView {
  /**
   * Événement déclencheur : `etb` (entrée en jeu), `dies` (mort), `upkeep` (entretien de son contrôleur),
   * `attacks` (déclarée attaquante), `end_step` (fin de tour de son contrôleur), `cast` (son contrôleur lance un sort),
   * `discard` (son contrôleur défausse une carte), `draw_step` (début de son étape de pioche) ; triggers retardés (Pact of Negation,
   * Mana Drain) : `next_upkeep` (prochain entretien de son contrôleur), `next_main` (sa prochaine phase principale) ;
   * `opponent_cast` (un adversaire lance un sort, Rhystic Study : effet `draw_unless`, `spec` = coût que le lanceur peut payer).
   */
  event: TriggerEvent
  effect: string
  amount: number
  /** Jeton créé (`Nom:F/E`). */
  spec?: string
  /** `cast` : `instant_sorcery` = le sort lancé doit être un éphémère ou un rituel (absent = tout sort) ; `tapped` = seulement si la source est engagée. */
  filter?: TriggerFilter
}

/** Événements de `TriggeredAbility` (PHP). */
export type TriggerEvent =
  | 'etb'
  | 'dies'
  | 'upkeep'
  | 'attacks'
  | 'end_step'
  | 'cast'
  | 'opponent_cast'
  | 'discard'
  | 'draw_step'
  | 'postcombat_main'
  | 'land_played'
  | 'next_upkeep'
  | 'next_main'
  | 'next_end'
  | 'opponent_draw'
  | 'any_cast'
  | 'combat_damage_player'
  | 'each_end_step'
/** Filtres de `TriggeredAbility` (PHP). */
export type TriggerFilter = 'instant_sorcery' | 'tapped' | 'second_spell' | 'noncreature' | 'no_mana' | 'first_noncreature' | 'second_draw' | 'not_draw_step_first' | 'off_turn_once'

export interface PublicCard {
  id: string
  name: string
  type: CardType | CardType[]
  cost: number
  effect: string | null
  /** Coût de mana canonique ('{X}{2}{R/G}{U}{U}'), null pour un terrain. */
  manaCost?: string | null
  /** Couleurs produites (W U B R G C) ; vide pour un terrain incolore/historique. */
  produces?: string[]
  amount: number
  commander?: boolean
  power?: number
  toughness?: number
  abilities?: AbilityView[]
  /** Mots-clés (absent si vide) : flying, reach, haste, vigilance, defender, menace, first_strike, double_strike, trample, deathtouch, lifelink, flash. */
  keywords?: string[]
  /** Mana ajouté par un rituel (effet 'mana'). */
  adds?: string[] | string
  /** Tous les effets du sort, dans l'ordre ; présent seulement à partir de deux (sinon `effect` / `amount` / `adds`). */
  effects?: {
    effect: string
    amount?: number
    adds?: string
    target?: string
    powerLife?: boolean
    /** `counter` : « sauf si son contrôleur paie » (Daze) ; `pact` : coût du prochain entretien. */
    payCost?: string
    /** `counter` : seulement un sort de cette valeur de mana (Mental Misstep). */
    manaValue?: number
    /** `counter` : sort contré exilé (`exile`) ou remis sur le dessus de la bibliothèque (`library_top`). */
    counterTo?: 'exile' | 'library_top'
    /** `counter` : Mana Drain ({C} à la prochaine phase principale). */
    drainMana?: boolean
    /** `tutor` : Beseech the Mirror (lancer la carte trouvée sans payer son coût si sa valeur de mana est au plus ce nombre). */
    freeCastMax?: number
  }[]
  /** Coût alternatif : `pitch_blue`, `commander`, `pitch_blue_off_turn`, `return_islands:N` (absent = aucun). */
  altCost?: string
  /** Autres façons de la lancer, sorte => coût de mana : `flashback` (depuis le cimetière, puis exilée), `dash` (célérité, revient en main à la fin du tour). */
  altCasts?: Partial<Record<'flashback' | 'dash', string>>
  /**
   * Capacités statiques (absent = aucune) : `noncreature_flash`, `opponents_mv_lands`, `opponents_no_cast_your_turn`,
   * `pregame_luck`, `opposition_agent`, `skip_draw` (Necropotence), `escape` (Underworld Breach).
   */
  statics?: string[]
  /** Coût additionnel : `sacrifice_creature`, `bargain` (Beseech the Mirror ; absent = aucun). */
  extraCost?: string
  /** Jeton (absent = carte) : cesse d'exister en quittant le champ de bataille. */
  token?: boolean
  /** Triggers (absent = aucun). */
  triggers?: TriggerView[]
  /** Sous-types rangés sous leur type, ex. { creature: ['Eldrazi'] } (absent = aucun). */
  subtypes?: Record<string, string[]>
  /** Ne se dégage pas à l'étape de dégagement (Mana Vault, Grim Monolith ; absent = se dégage). */
  noUntap?: boolean
  /** Couleurs (lettres) de la carte exilée par l'imprint d'un Chrome Mox ; absent = rien d'exilé. */
  imprint?: string[]
  /** Marqueurs par sorte (`+1/+1`, `age`), posés par `CountersAdded` ; absent = aucun. */
  counters?: Record<string, number>
}

export interface BoardCard extends PublicCard {
  tapped: boolean
  /** Joueur attaqué, tant que la créature attaque (efface à la fin du combat). */
  attacking?: number
  /** Id de l'attaquant bloqué, tant que la créature bloque. */
  blocking?: string
}

export interface GameEvent {
  seq: number
  type: string
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  payload: Record<string, any>
  verbose: boolean
}

export interface ActionView {
  id: string
  label: string
  type:
    | 'pass'
    | 'land'
    | 'cast'
    | 'attack'
    | 'block'
    | 'done'
    | 'discard'
    | 'ability'
    | 'trigger'
    | 'assign'
    | 'keep'
    | 'mulligan'
    | 'bottom'
    | 'keepLegend'
    | 'scry'
    | 'pay'
    | 'putBack'
    | 'arrange'
    | 'chain'
    | 'replace'
    | 'pregame'
    | 'choice'
  player: number
  cardId?: string
  targets?: string[]
  defender?: number
  attackerId?: string
  /** Action `ability` : index de la capacité et alternative de mana choisie. */
  ability?: number
  option?: number
  /** Action `ability` : vrai pour une capacité de mana. */
  mana?: boolean
  /** Action `trigger` : trigger en attente à mettre sur la pile. */
  pendingId?: string
  /** Action `assign` : répartition des dégâts de l'attaquant (`target` = id de bloqueur ou `player:N`). */
  assignment?: { target: string; amount: number }[]
  /** Action `assign` : répartition par défaut (létal dans l'ordre de déclaration). */
  default?: boolean
  /** Action `pay` (paiement à la résolution : Daze, Pact of Negation) : payer ou refuser `cost`. */
  pay?: boolean
  cost?: string
  /** Action `cast` : coût alternatif (`free`, `pitch:<id>`, `return:<id>[,<id>]`). */
  alt?: string
  /** Action `arrange` (Ponder) : ordre des cartes du dessus (dessus en premier), ou mélange (`shuffle`, `order` vide). */
  order?: string[]
  shuffle?: boolean
}

export interface StackItemView {
  id: string
  card: PublicCard
  controller: number
  targets: string[]
  x?: number
  /** Présent pour une capacité activée (la carte est sa source). */
  ability?: AbilityView
  /** Présent pour un trigger (la carte est sa source, telle qu'au moment du trigger). */
  trigger?: TriggerView
  /** Libellé du trigger, fourni par le moteur. */
  label?: string
}

export interface DecisionRequest {
  player: number
  eventSeq: number
  turn: number
  phase: string
  activePlayer: number
  /** Déclaration de combat en cours (attaquants/bloqueurs, répartition des dégâts) : le déclarant occupe `priority.holder`. */
  declaring: 'attackers' | 'blockers' | 'damage' | null
  priority: { holder: number; passed: number[]; announced: boolean }
  stack: StackItemView[]
  actions: ActionView[]
}

export type SimulationStatus = 'queued' | 'running' | 'waiting' | 'over' | 'failed'

/** Étapes où un siège humain peut s'arrêter (valeurs de `PhaseChanged.phase`). */
export const STOP_STEPS = ['upkeep', 'draw', 'main1', 'begin_combat', 'declare_attackers', 'declare_blockers', 'first_strike_damage', 'combat_damage', 'end_combat', 'main2', 'end', 'cleanup'] as const

/** Stops configurés d'un joueur humain (absent / null = défaut : s'arrêter dès qu'une action est possible). */
export interface StopsConfig {
  steps: string[]
  onOpponentSpell: boolean
  onDeclareAttackers: boolean
  onDeclareBlockers: boolean
}

/** Une branche de l'arbre (GET /simulations/{id}/branches). */
export interface BranchNode {
  id: number
  parentId: number | null
  forkEventSeq: number | null
  status: SimulationStatus
  eventCount: number
  /** Sièges humains (ids) de la branche. */
  humans: number[]
}

export interface BranchTree {
  current: number
  /** Racine d'abord, puis les fils en largeur. */
  branches: BranchNode[]
}

export interface SimulationView {
  id: number
  parentId: number | null
  forkEventSeq: number | null
  seed: number
  status: SimulationStatus
  /** Tour courant pendant la simulation asynchrone (queued/running). */
  progress?: number | null
  error?: string | null
  pending: DecisionRequest | null
  eventCount: number
  controllers: Record<string, string>
  /** Stops par id de joueur ; clé absente = défaut. */
  stops?: Record<string, StopsConfig | null>
  /** URL d'images Scryfall par nom de carte (decks chargés seulement ; absent pour les decks de démo). */
  cardImages?: Record<string, { small: string | null; normal: string | null }>
}

// --- État reconstruit côté front par le reducer ---

export interface PlayerView {
  id: number
  life: number
  library: number
  hand: BoardCard[]
  battlefield: BoardCard[]
  graveyard: BoardCard[]
  /** Zone d'exil (cartes exilées dont le joueur est propriétaire). */
  exile: BoardCard[]
  command: BoardCard[]
  lost: boolean
  controller: string
  /** Dégâts de commandant subis : "propriétaire:idCommandant" => total. */
  commanderDamage: Record<string, number>
  /** Réserve de mana libre (symbole => quantité). */
  pool: Record<string, number>
  /** Mana restreint (restriction => symbole => quantité), ex. { eldrazi: { C: 2 } } ; vide/absent = aucun. */
  restricted?: Record<string, Record<string, number>>
}

export interface GameView {
  turn: number
  phase: string
  activePlayer: number | null
  priority: { holder: number | null; passed: number[] }
  players: Record<number, PlayerView>
  stack: StackItemView[]
  over: boolean
  winner: number | null
  lastSeq: number
}

/** Decklist d'un siège (texte Moxfield / Archidekt / MTGO) ; `commander` surcharge la ligne « CMDR: ». */
export type DeckInput = string | { decklist: string; commander?: string }
