import { describe, expect, it } from 'vitest'
import type { GameEvent } from '../types/engine'
import fixture from './__fixtures__/game.json'
import { replay } from './reducer'

const events = fixture.events as GameEvent[]

describe('reducer — test de contrat avec le moteur PHP', () => {
  const view = replay(events)

  it('retrouve l\'état final calculé par le moteur', () => {
    expect(view.over).toBe(fixture.final.over)
    expect(view.winner).toBe(fixture.final.winner)
    expect(view.turn).toBe(fixture.final.turn)

    for (const [id, expected] of Object.entries(fixture.final.players)) {
      const p = view.players[Number(id)]
      expect({
        life: p.life,
        lost: p.lost,
        library: p.library,
        hand: p.hand.length,
        battlefield: p.battlefield.length,
        tappedLands: p.battlefield.filter((c) => c.tapped).length,
        graveyard: p.graveyard.length,
        command: p.command.length,
      }).toEqual(expected)
    }
  })

  it('termine avec une pile vide', () => {
    expect(view.stack).toEqual([])
  })

  it('peut s\'arrêter à n\'importe quel événement (replay partiel)', () => {
    const mid = events[Math.floor(events.length / 2)].seq
    const partial = replay(events, mid)
    expect(partial.lastSeq).toBe(mid)
    expect(partial.over).toBe(false)
  })

  it('un sort lancé apparaît sur la pile avant sa résolution', () => {
    const cast = events.find((e) => e.type === 'SpellCast')!
    const resolved = events.find((e) => e.type === 'SpellResolved' && e.payload.stackId === cast.payload.stackId)!
    expect(replay(events, cast.seq).stack.map((i) => i.id)).toContain(cast.payload.stackId)
    expect(replay(events, resolved.seq).stack.map((i) => i.id)).not.toContain(cast.payload.stackId)
  })
})
