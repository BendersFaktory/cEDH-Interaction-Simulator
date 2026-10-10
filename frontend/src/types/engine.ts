// Miroir TypeScript des structures produites par le moteur PHP (voir docs/EVENTS.md).

export type CardType = 'land' | 'instant' | 'sorcery' | 'creature' | 'artifact' | 'enchantment'

/** Capacité activée d'une carte (forme sérialisée par le moteur). */
export interface AbilityView {
  isManaAbility: boolean
  effect: string
  tap: boolean
  manaCost?: string | null
  /** Pour un effet de mana : alternatives produites (ex. ['CC'] ou ['R', 'G']). */
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

export interface PublicCard {
  id: string
  name: string
  type: CardType
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
  /** Mana ajouté par un rituel (effet 'mana'). */
  adds?: string[] | string
  /** Jeton (absent = carte) : cesse d'exister en quittant le champ de bataille. */
  token?: boolean
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
  type: 'pass' | 'land' | 'cast' | 'attack' | 'block' | 'done' | 'discard' | 'ability'
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
}

export interface StackItemView {
  id: string
  card: PublicCard
  controller: number
  targets: string[]
  x?: number
  /** Présent pour une capacité activée (la carte est sa source). */
  ability?: AbilityView
}

export interface DecisionRequest {
  player: number
  eventSeq: number
  turn: number
  phase: string
  activePlayer: number
  /** Déclaration de combat en cours (attaquants/bloqueurs) : le déclarant occupe `priority.holder`. */
  declaring: 'attackers' | 'blockers' | null
  priority: { holder: number; passed: number[]; announced: boolean }
  stack: StackItemView[]
  actions: ActionView[]
}

export type SimulationStatus = 'running' | 'waiting' | 'over'

export interface SimulationView {
  id: number
  parentId: number | null
  forkEventSeq: number | null
  seed: number
  status: SimulationStatus
  pending: DecisionRequest | null
  eventCount: number
  controllers: Record<string, string>
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
  /** Réserve de mana (symbole => quantité). */
  pool: Record<string, number>
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
