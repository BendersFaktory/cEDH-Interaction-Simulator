import { phaseLabel } from './phases'
import type { GameEvent } from '../types/engine'

/** Une étape de la frise : un changement de phase du tour et les points de bifurcation qui le suivent. */
export interface FriseStep {
  seq: number
  phase: string
  label: string
  /** Points de bifurcation (checkpoints) dont le seq tombe entre cette phase et la suivante. */
  checkpoints: number[]
}

/**
 * Frise d'un tour : une étape par `PhaseChanged` de ce tour, avec les checkpoints (PriorityGranted `checkpoint`)
 * qui la suivent. `checkpointSeqs` doit être triée, comme le store la fournit.
 */
export function frise(events: GameEvent[], checkpointSeqs: number[], turn: number): FriseStep[] {
  const phases = events.filter((e) => e.type === 'PhaseChanged' && e.payload.turn === turn)
  return phases.map((e, i) => {
    const end = phases[i + 1]?.seq ?? Infinity
    return {
      seq: e.seq,
      phase: e.payload.phase,
      label: phaseLabel(e.payload.phase),
      checkpoints: checkpointSeqs.filter((s) => s >= e.seq && s < end),
    }
  })
}

/** Joueur concerné par un événement (au sens du journal filtrable), ou null. */
export function eventPlayer(e: GameEvent): number | null {
  const p = e.payload
  for (const key of ['player', 'activePlayer', 'holder', 'controller']) {
    if (typeof p[key] === 'number') return p[key]
  }
  return null
}

/** Filtre le journal par joueur (`null` = tous) et par type d'événement (`null` = tous). */
export function filterJournal(events: GameEvent[], player: number | null, type: string | null): GameEvent[] {
  return events.filter((e) => (player === null || eventPlayer(e) === player) && (type === null || e.type === type))
}
