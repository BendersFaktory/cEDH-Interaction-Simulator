import { describe, expect, it } from 'vitest'
import { eventPlayer, filterJournal, frise } from './navigation'
import type { GameEvent } from '../types/engine'

const ev = (seq: number, type: string, payload: Record<string, unknown> = {}): GameEvent => ({ seq, type, payload, verbose: false })

describe('frise', () => {
  const events = [
    ev(1, 'PhaseChanged', { turn: 1, phase: 'main1' }),
    ev(2, 'PriorityGranted', { checkpoint: true }),
    ev(3, 'PhaseChanged', { turn: 1, phase: 'combat_damage' }),
    ev(4, 'PhaseChanged', { turn: 2, phase: 'upkeep' }),
  ]

  it('garde les phases du tour demandé, avec les checkpoints qui les suivent', () => {
    const steps = frise(events, [2, 3, 5], 1)
    expect(steps.map((s) => [s.seq, s.phase])).toEqual([
      [1, 'main1'],
      [3, 'combat_damage'],
    ])
    expect(steps[0].checkpoints).toEqual([2])
    expect(steps[1].checkpoints).toEqual([3, 5])
    expect(steps[0].label).not.toBe('main1')
  })

  it('renvoie une liste vide pour un tour sans phase', () => {
    expect(frise(events, [], 9)).toEqual([])
  })
})

describe('journal filtrable', () => {
  const events = [
    ev(1, 'PhaseChanged', { turn: 1, phase: 'main1', activePlayer: 0 }),
    ev(2, 'SpellCast', { player: 2, card: { name: 'Bolt' } }),
    ev(3, 'PriorityGranted', { holder: 2 }),
    ev(4, 'LifeGained', { player: 0 }),
    ev(5, 'GameOver', {}),
  ]

  it('identifie le joueur d’un événement', () => {
    expect(events.map(eventPlayer)).toEqual([0, 2, 2, 0, null])
  })

  it('filtre par joueur et par type', () => {
    expect(filterJournal(events, 2, null).map((e) => e.seq)).toEqual([2, 3])
    expect(filterJournal(events, null, 'LifeGained').map((e) => e.seq)).toEqual([4])
    expect(filterJournal(events, 0, 'PhaseChanged').map((e) => e.seq)).toEqual([1])
    expect(filterJournal(events, null, null)).toHaveLength(5)
  })
})
