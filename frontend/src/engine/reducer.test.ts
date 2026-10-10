import { describe, expect, it } from 'vitest'
import type { GameEvent } from '../types/engine'
import fixture from './__fixtures__/game.json'
import { applyEvent, replay } from './reducer'

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
        commanderDamage: p.commanderDamage,
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

describe('reducer — combat', () => {
  it('les créatures mortes quittent le champ de bataille, l\'attaque est effacée en fin de combat', () => {
    const attacker = events.find((e) => e.type === 'AttackerDeclared')!
    const after = replay(events, attacker.seq)
    const card = after.players[attacker.payload.player].battlefield.find((c) => c.id === attacker.payload.card)!
    expect(card.attacking).toBe(attacker.payload.defender)
    expect(card.tapped).toBe(true)

    const ended = events.find((e) => e.type === 'CombatEnded' && e.seq > attacker.seq)!
    const over = replay(events, ended.seq)
    for (const pl of Object.values(over.players)) {
      expect(pl.battlefield.some((c) => c.attacking !== undefined || c.blocking !== undefined)).toBe(false)
    }
  })

  it('une créature morte est dans un cimetière ou en zone de commandement, jamais sur le champ de bataille', () => {
    const died = events.find((e) => e.type === 'CreatureDied')!
    const view = replay(events, died.seq)
    const pl = view.players[died.payload.player]
    expect(pl.battlefield.some((c) => c.id === died.payload.card.id)).toBe(false)
    const zone = died.payload.destination === 'command' ? pl.command : pl.graveyard
    expect(zone.some((c) => c.id === died.payload.card.id)).toBe(true)
  })
})

describe('reducer — défausse', () => {
  it('une carte défaussée quitte la main pour le cimetière (ou la zone de commandement)', () => {
    const drawn = events.find((e) => e.type === 'CardDrawn')!
    const seq = Math.max(...events.map((e) => e.seq)) + 1
    const discard: GameEvent = {
      seq,
      type: 'CardDiscarded',
      payload: { player: drawn.payload.player, card: drawn.payload.card, destination: 'graveyard', hand: 0 },
      verbose: false,
    }
    const before = replay(events, drawn.seq)
    expect(before.players[drawn.payload.player].hand.some((c) => c.id === drawn.payload.card.id)).toBe(true)
    const after = applyEvent(before, discard)
    const pl = after.players[drawn.payload.player]
    expect(pl.hand.some((c) => c.id === drawn.payload.card.id)).toBe(false)
    expect(pl.graveyard.some((c) => c.id === drawn.payload.card.id)).toBe(true)
  })
})
