// Miroir TypeScript des structures produites par le moteur PHP (voir docs/EVENTS.md).

export type CardKind = 'land' | 'instant' | 'sorcery' | 'creature'

export interface PublicCard {
  id: string
  name: string
  kind: CardKind
  cost: number
  effect: string | null
  amount: number
  commander?: boolean
}

export interface BoardCard extends PublicCard {
  tapped: boolean
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
  type: 'pass' | 'land' | 'cast'
  player: number
  cardId?: string
  targets?: string[]
}

export interface StackItemView {
  id: string
  card: PublicCard
  controller: number
  targets: string[]
}

export interface DecisionRequest {
  player: number
  eventSeq: number
  turn: number
  phase: string
  activePlayer: number
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
