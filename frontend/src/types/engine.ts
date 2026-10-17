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
  /** Jeton créé (`Nom:F/E`) ou bonus (`+F/+E`). */
  spec?: string
}

/** Trigger d'une carte (forme sérialisée par le moteur). */
export interface TriggerView {
  /**
   * Événement déclencheur : `etb` (entrée en jeu), `dies` (mort), `upkeep` (entretien de son contrôleur),
   * `attacks` (déclarée attaquante), `end_step` (fin de tour de son contrôleur), `cast` (son contrôleur lance un sort),
   * `discard` (son contrôleur défausse une carte).
   */
  event: TriggerEvent
  effect: string
  amount: number
  /** Jeton créé (`Nom:F/E`). */
  spec?: string
  /** Seulement pour `cast` : `instant_sorcery` = le sort lancé doit être un éphémère ou un rituel (absent = tout sort). */
  filter?: TriggerFilter
}

export type TriggerEvent = 'etb' | 'dies' | 'upkeep' | 'attacks' | 'end_step' | 'cast' | 'discard'
export type TriggerFilter = 'instant_sorcery'

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
  /** Mots-clés (absent si vide) : flying, reach, haste, vigilance, defender, menace, first_strike, double_strike, trample, deathtouch, lifelink. */
  keywords?: string[]
  /** Mana ajouté par un rituel (effet 'mana'). */
  adds?: string[] | string
  /** Jeton (absent = carte) : cesse d'exister en quittant le champ de bataille. */
  token?: boolean
  /** Triggers (absent = aucun). */
  triggers?: TriggerView[]
  /** Sous-types rangés sous leur type, ex. { creature: ['Eldrazi'] } (absent = aucun). */
  subtypes?: Record<string, string[]>
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
  type: 'pass' | 'land' | 'cast' | 'attack' | 'block' | 'done' | 'discard' | 'ability' | 'trigger' | 'assign' | 'keep' | 'mulligan' | 'bottom' | 'keepLegend'
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
export const STOP_STEPS = [
  'upkeep', 'draw', 'main1', 'begin_combat', 'declare_attackers', 'declare_blockers',
  'first_strike_damage', 'combat_damage', 'end_combat', 'main2', 'end',
] as const

/** Stops configurés d'un joueur humain (absent / null = défaut : s'arrêter dès qu'une action est possible). */
export interface StopsConfig {
  steps: string[]
  onOpponentSpell: boolean
  onDeclareAttackers: boolean
  onDeclareBlockers: boolean
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
}

// --- État reconstruit côté front par le reducer ---

export interface PlayerView {
  id: number
  life: number
  library: number
  hand: BoardCard[]
  battlefield: BoardCard[]
  graveyard: BoardCard[]
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
