import { describe, expect, it } from 'vitest'
import type { GameEvent } from '../types/engine'
import { describeEvent } from './describe'

const spell = (payload: Record<string, unknown>): GameEvent => ({
  seq: 1,
  type: 'SpellCast',
  verbose: false,
  payload: { player: 1, card: { name: 'Éclair' }, targets: [], fromCommand: false, cost: 2, ...payload },
})

describe('describeEvent — SpellCast', () => {
  it('affiche le coût payé et X quand manaCost est présent', () => {
    expect(describeEvent(spell({ manaCost: '{X}{R}', x: 2, cost: 3 }))).toBe('J1 lance Éclair (coût {X}{R}, X=2)')
  })
  it('omet X sans symbole X', () => {
    expect(describeEvent(spell({ manaCost: '{1}{R}', x: 0 }))).toBe('J1 lance Éclair (coût {1}{R})')
  })
  it('ancien format : rien sans manaCost, coût texte depuis la commande', () => {
    expect(describeEvent(spell({}))).toBe('J1 lance Éclair')
    expect(describeEvent(spell({ fromCommand: true }))).toBe('J1 lance Éclair depuis la zone de commandement (coût 2)')
  })
})
