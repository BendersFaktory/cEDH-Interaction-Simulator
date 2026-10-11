import { describe, expect, it } from 'vitest'
import type { GameEvent, PublicCard } from '../types/engine'
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

describe('reducer — capacités et réserve de mana', () => {
  const ev = (seq: number, type: string, payload: Record<string, unknown>): GameEvent => ({ seq, type, payload, verbose: false })
  const land: PublicCard = { id: 'l1', name: 'Mountain', type: 'land', cost: 0, effect: null, amount: 0 }
  const pinger: PublicCard = { id: 'a1', name: 'Pinger', type: 'artifact', cost: 2, effect: null, amount: 0 }
  const start = () =>
    replay([
      ev(1, 'GameStarted', { seed: 1, players: [{ id: 0, life: 40, library: 0, controller: 'h' }, { id: 1, life: 40, library: 0, controller: 'ai' }] }),
      ev(2, 'LandPlayed', { player: 0, card: land }),
      ev(3, 'LandPlayed', { player: 0, card: pinger }),
    ])

  it('pool vide par défaut, lu depuis le snapshot si présent', () => {
    expect(start().players[0].pool).toEqual({})
    const v = replay([ev(1, 'GameStarted', { seed: 1, players: [{ id: 0, life: 40, library: 0, controller: 'h', pool: { R: 1 } }] })])
    expect(v.players[0].pool).toEqual({ R: 1 })
  })

  it('ManaAbilityActivated engage la source et fixe la réserve', () => {
    const v = applyEvent(start(), ev(4, 'ManaAbilityActivated', { player: 0, card: land, tapped: ['l1'], mana: { R: 1 }, pool: { R: 1 } }))
    expect(v.players[0].battlefield[0].tapped).toBe(true)
    expect(v.players[0].pool).toEqual({ R: 1 })
    expect(v.stack).toEqual([])
  })

  it('AbilityActivated pose l\'élément de pile, AbilityResolved le retire sans bouger la carte', () => {
    const v = start()
    v.priority.passed = [1]
    const ability = { isManaAbility: false, effect: 'damage', tap: true, amount: 1 }
    applyEvent(v, ev(4, 'AbilityActivated', { player: 0, stackId: 's1', card: pinger, ability, targets: ['player:1'], tapped: ['a1'] }))
    expect(v.stack).toEqual([{ id: 's1', card: pinger, controller: 0, targets: ['player:1'], ability }])
    expect(v.players[0].battlefield[1].tapped).toBe(true)
    expect(v.priority.passed).toEqual([])
    applyEvent(v, ev(5, 'AbilityResolved', { stackId: 's1', controller: 0, card: pinger }))
    expect(v.stack).toEqual([])
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['l1', 'a1'])
  })

  it('AbilityTriggered pose le trigger sur la pile (source hors du champ de bataille possible), AbilityResolved le retire', () => {
    const v = start()
    v.priority.passed = [1, 2]
    const myr = { id: 'm1', name: 'Perilous Myr', type: 'creature' as const, cost: 2, effect: null, amount: 0 }
    const trigger = { event: 'dies' as const, effect: 'damage', amount: 2 }
    applyEvent(v, ev(4, 'AbilityTriggered', { player: 0, stackId: 's7', card: myr, triggerIndex: 0, trigger, label: 'Quand elle meurt : 2 dégâts', targets: ['player:3'] }))
    expect(v.stack).toEqual([{ id: 's7', card: myr, controller: 0, targets: ['player:3'], trigger, label: 'Quand elle meurt : 2 dégâts' }])
    expect(v.priority.passed).toEqual([])
    applyEvent(v, ev(5, 'AbilityResolved', { stackId: 's7', controller: 0, card: myr, abilityIndex: 0, trigger: true }))
    expect(v.stack).toEqual([])
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['l1', 'a1'])
  })

  it('ManaAdded, ManaPoolEmptied et SpellCast.pool', () => {
    const v = start()
    applyEvent(v, ev(4, 'ManaAdded', { player: 0, mana: { R: 2 }, pool: { R: 2 }, source: 's1' }))
    expect(v.players[0].pool).toEqual({ R: 2 })
    applyEvent(v, ev(5, 'ManaPoolEmptied', { player: 0, lost: { R: 2 }, pool: {} }))
    expect(v.players[0].pool).toEqual({})

    v.players[0].pool = { R: 2 }
    v.players[0].hand.push({ ...pinger, id: 'h1', tapped: false })
    applyEvent(v, ev(6, 'SpellCast', { player: 0, card: pinger, stackId: 's2', targets: [], tapped: [], fromCommand: false }))
    expect(v.players[0].pool).toEqual({ R: 2 })
    applyEvent(v, ev(7, 'SpellCast', { player: 0, card: { ...pinger, id: 'h2' }, stackId: 's3', targets: [], tapped: [], fromCommand: false, pool: { R: 1 } }))
    expect(v.players[0].pool).toEqual({ R: 1 })
  })

  it('un artefact résolu arrive sur le champ de bataille', () => {
    const v = start()
    v.stack.push({ id: 's9', card: pinger, controller: 1, targets: [] })
    applyEvent(v, ev(4, 'SpellResolved', { stackId: 's9', controller: 1, card: pinger, destination: 'battlefield' }))
    expect(v.players[1].battlefield.map((c) => c.id)).toEqual(['a1'])
  })

  it('TokenCreated pose le jeton ; PermanentSacrificed le fait disparaître (destination none), une carte va au cimetière', () => {
    const v = start()
    const treasure = { id: 'p0_t1', name: 'Treasure', type: 'artifact' as const, cost: 0, effect: null, amount: 0, token: true }
    applyEvent(v, ev(4, 'TokenCreated', { player: 0, card: treasure }))
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['l1', 'a1', 'p0_t1'])
    applyEvent(v, ev(5, 'PermanentSacrificed', { player: 0, card: treasure, destination: 'none' }))
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['l1', 'a1'])
    expect(v.players[0].graveyard).toEqual([])
    applyEvent(v, ev(6, 'PermanentSacrificed', { player: 0, card: pinger, destination: 'graveyard' }))
    expect(v.players[0].graveyard.map((c) => c.id)).toEqual(['a1'])
  })

  it('CreatureBoosted fixe la force et l\'endurance (valeurs absolues)', () => {
    const v = start()
    const bear = { id: 'c1', name: 'Wild Mongrel', type: 'creature' as const, cost: 2, effect: null, amount: 0, power: 2, toughness: 2 }
    v.players[0].battlefield.push({ ...bear, tapped: false })
    applyEvent(v, ev(4, 'CreatureBoosted', { player: 0, card: 'c1', power: 3, toughness: 3 }))
    expect([v.players[0].battlefield[2].power, v.players[0].battlefield[2].toughness]).toEqual([3, 3])
    applyEvent(v, ev(5, 'CreatureBoosted', { player: 0, card: 'c1', power: 2, toughness: 2 }))
    expect(v.players[0].battlefield[2].power).toBe(2)
  })
})
