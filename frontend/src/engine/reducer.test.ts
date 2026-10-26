import { describe, expect, it } from 'vitest'
import type { GameEvent, PublicCard } from '../types/engine'
import fixture from './__fixtures__/game.json'
import { applyEvent, replay } from './reducer'

const events = fixture.events as GameEvent[]

describe('reducer — test de contrat avec le moteur PHP', () => {
  const view = replay(events)

  it("retrouve l'état final calculé par le moteur", () => {
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

  it('la pile finale ne contient que les éléments jamais résolus (partie terminée en pleine pile)', () => {
    const resolved = new Set(events.filter((e) => e.type === 'AbilityResolved' || e.type === 'SpellResolved' || e.type === 'SpellCountered').map((e) => e.payload.stackId))
    const pushed = events.filter((e) => ['SpellCast', 'AbilityActivated', 'AbilityTriggered'].includes(e.type)).map((e) => e.payload.stackId as string)
    expect(view.stack.map((i) => i.id)).toEqual(pushed.filter((id) => !resolved.has(id)))
  })

  it("peut s'arrêter à n'importe quel événement (replay partiel)", () => {
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
  it("les créatures mortes quittent le champ de bataille, l'attaque est effacée en fin de combat", () => {
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
    const died = events.find((e) => e.type === 'CreatureDied' && e.payload.destination !== 'none')!
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
      ev(1, 'GameStarted', {
        seed: 1,
        players: [
          { id: 0, life: 40, library: 0, controller: 'h' },
          { id: 1, life: 40, library: 0, controller: 'ai' },
        ],
      }),
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

  it("AbilityActivated pose l'élément de pile, AbilityResolved le retire sans bouger la carte", () => {
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

  it("mana restreint : recopié depuis le snapshot et les payloads, vidé en fin d'étape", () => {
    const r = replay([ev(1, 'GameStarted', { seed: 1, players: [{ id: 0, life: 40, library: 0, controller: 'h', restricted: { eldrazi: { C: 2 } } }] })])
    expect(r.players[0].restricted).toEqual({ eldrazi: { C: 2 } })
    expect(start().players[0].restricted).toEqual({})

    const v = start()
    applyEvent(v, ev(4, 'ManaAbilityActivated', { player: 0, card: land, tapped: ['l1'], mana: { C: 2 }, pool: {}, restricted: { eldrazi: { C: 2 } } }))
    expect(v.players[0].pool).toEqual({})
    expect(v.players[0].restricted).toEqual({ eldrazi: { C: 2 } })
    v.players[0].hand.push({ ...pinger, id: 'h1', tapped: false })
    applyEvent(v, ev(5, 'SpellCast', { player: 0, card: { ...pinger, id: 'h1' }, stackId: 's1', targets: [], tapped: [], fromCommand: false, pool: {} }))
    expect(v.players[0].restricted).toEqual({})

    applyEvent(v, ev(6, 'ManaAdded', { player: 0, mana: { R: 1 }, pool: { R: 1 }, restricted: { eldrazi: { C: 1 } }, source: 's2' }))
    expect(v.players[0].restricted).toEqual({ eldrazi: { C: 1 } })
    applyEvent(v, ev(7, 'ManaPoolEmptied', { player: 0, lost: { R: 1, C: 1 }, pool: {} }))
    expect(v.players[0].pool).toEqual({})
    expect(v.players[0].restricted).toEqual({})
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

  it('LegendRuleApplied retire les légendes non gardées (cimetière, ou rien pour un jeton)', () => {
    const v = start()
    const legend = { id: 'g1', name: 'Isamaru', type: 'creature' as const, cost: 1, effect: null, amount: 0 }
    const copy = { ...legend, id: 'p0_t1', token: true }
    v.players[0].battlefield.push({ ...legend, tapped: true }, { ...copy, tapped: false })
    applyEvent(v, ev(4, 'LegendRuleApplied', { player: 0, kept: 'p0_t1', removed: [{ card: legend, destination: 'graveyard' }] }))
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['l1', 'a1', 'p0_t1'])
    expect(v.players[0].graveyard.map((c) => c.id)).toEqual(['g1'])
    applyEvent(v, ev(5, 'LegendRuleApplied', { player: 0, kept: 'g2', removed: [{ card: copy, destination: 'none' }] }))
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['l1', 'a1'])
    expect(v.players[0].graveyard.map((c) => c.id)).toEqual(['g1'])
  })

  it("CardExiled met la carte en exil (un jeton disparaît) ; CommanderReturned depuis l'exil la vide", () => {
    const v = start()
    expect(v.players[0].exile).toEqual([])
    const spirit = { id: 'p0_t1', name: 'Spirit', type: 'creature' as const, cost: 0, effect: null, amount: 0, token: true }
    applyEvent(v, ev(4, 'TokenCreated', { player: 0, card: spirit }))
    applyEvent(v, ev(5, 'CardExiled', { player: 0, card: spirit, from: 'battlefield', destination: 'none', source: 'Path', exile: 0 }))
    applyEvent(v, ev(6, 'CardExiled', { player: 0, card: pinger, from: 'battlefield', destination: 'exile', source: 'Path', exile: 1 }))
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['l1'])
    expect(v.players[0].exile.map((c) => c.id)).toEqual(['a1'])
    expect(v.players[0].graveyard).toEqual([])
    const cmd = { id: 'cmd', name: 'Commandant', type: 'creature' as const, cost: 3, effect: null, amount: 0, commander: true }
    v.players[1].battlefield.push({ ...cmd, tapped: true })
    applyEvent(v, ev(7, 'CardExiled', { player: 1, card: cmd, from: 'battlefield', destination: 'exile', source: 'Swords', exile: 1 }))
    expect(v.players[1].exile.map((c) => c.tapped)).toEqual([false])
    applyEvent(v, ev(8, 'CommanderReturned', { player: 1, card: cmd, from: 'exile' }))
    expect(v.players[1].exile).toEqual([])
    expect(v.players[1].command.map((c) => c.id)).toContain('cmd')
  })

  it('PermanentDestroyed envoie le permanent au cimetière ; AbilitiesGranted / AbilitiesExpired ajoutent puis retirent la capacité accordée', () => {
    const v = start()
    applyEvent(v, ev(4, 'PermanentDestroyed', { player: 0, card: pinger, destination: 'graveyard', source: 'Pyroblast' }))
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['l1'])
    expect(v.players[0].graveyard.map((c) => c.id)).toEqual(['a1'])

    const grant = { isManaAbility: true, effect: 'mana', tap: false, options: ['{B}'], sacrifice: true }
    const before = v.players[0].battlefield[0].abilities?.length ?? 0
    applyEvent(v, ev(5, 'AbilitiesGranted', { player: 0, cards: ['l1'], ability: grant }))
    expect(v.players[0].battlefield[0].abilities).toHaveLength(before + 1)
    expect(v.players[0].battlefield[0].abilities?.at(-1)).toEqual(grant)
    applyEvent(v, ev(6, 'AbilitiesExpired', { player: 0, card: 'l1', count: 1 }))
    expect(v.players[0].battlefield[0].abilities ?? []).toHaveLength(before)
  })

  it('interaction gratuite : Île renvoyée en main, coût payé à la résolution, sort contré exilé ou remis sur la bibliothèque', () => {
    const v = start()
    applyEvent(v, ev(4, 'PermanentReturnedToHand', { player: 0, card: land, destination: 'hand', hand: 1, source: 'Daze' }))
    expect(v.players[0].battlefield.map((c) => c.id)).toEqual(['a1'])
    expect(v.players[0].hand.map((c) => [c.id, c.tapped])).toEqual([['l1', false]])
    v.players[1].battlefield.push({ ...land, id: 'l2', tapped: false })
    applyEvent(v, ev(5, 'CostPaid', { player: 1, cost: '{1}', source: 'Daze', stackId: 's2', tapped: ['l2'], paid: { R: 1 }, fromPool: {}, pool: {} }))
    expect(v.players[1].battlefield.find((c) => c.id === 'l2')?.tapped).toBe(true)
    applyEvent(v, ev(6, 'CostNotPaid', { player: 1, cost: '{1}', source: 'Daze', stackId: 's2' }))
    const bolt = { id: 'b1', name: 'Lightning Bolt', type: 'instant' as const, cost: 1, effect: 'damage', amount: 3 }
    v.stack.push({ id: 's1', card: bolt, controller: 1, targets: [] })
    applyEvent(v, ev(7, 'SpellCountered', { stackId: 's1', card: bolt, controller: 1, by: 's3', destination: 'exile' }))
    expect(v.players[1].exile.map((c) => c.id)).toEqual(['b1'])
    v.stack.push({ id: 's4', card: bolt, controller: 1, targets: [] })
    applyEvent(v, ev(8, 'SpellCountered', { stackId: 's4', card: bolt, controller: 1, by: 's5', destination: 'library', library: 7 }))
    expect(v.stack).toEqual([])
    expect(v.players[1].library).toBe(7)
    expect(v.players[1].graveyard).toEqual([])
  })

  it('CardImprinted fixe les couleurs retenues par le permanent (valeurs absolues)', () => {
    const v = start()
    const mox = { id: 'mox', name: 'Chrome Mox', type: 'artifact' as const, cost: 0, effect: null, amount: 0 }
    applyEvent(v, ev(4, 'SpellResolved', { stackId: 's1', card: mox, controller: 0, destination: 'battlefield' }))
    applyEvent(v, ev(5, 'CardImprinted', { player: 0, card: 'mox', exiled: 'Lightning Bolt', colors: ['R'] }))
    expect(v.players[0].battlefield.find((c) => c.id === 'mox')?.imprint).toEqual(['R'])
  })

  it("CreatureBoosted fixe la force et l'endurance (valeurs absolues)", () => {
    const v = start()
    const bear = { id: 'c1', name: 'Wild Mongrel', type: 'creature' as const, cost: 2, effect: null, amount: 0, power: 2, toughness: 2 }
    v.players[0].battlefield.push({ ...bear, tapped: false })
    applyEvent(v, ev(4, 'CreatureBoosted', { player: 0, card: 'c1', power: 3, toughness: 3 }))
    expect([v.players[0].battlefield[2].power, v.players[0].battlefield[2].toughness]).toEqual([3, 3])
    applyEvent(v, ev(5, 'CreatureBoosted', { player: 0, card: 'c1', power: 2, toughness: 2 }))
    expect(v.players[0].battlefield[2].power).toBe(2)
  })
})

describe('reducer — triggers élargis et ronde de cleanup (règle 514.3a)', () => {
  const ev = (seq: number, type: string, payload: Record<string, unknown>): GameEvent => ({ seq, type, payload, verbose: false })
  const bear: PublicCard = { id: 'b1', name: 'Grizzly Bears', type: 'creature', cost: 2, effect: null, amount: 0, power: 2, toughness: 2 }
  const start = () =>
    replay([
      ev(1, 'GameStarted', {
        seed: 1,
        players: [
          { id: 0, life: 40, library: 0, controller: 'h' },
          { id: 1, life: 40, library: 0, controller: 'ai' },
        ],
      }),
      ev(2, 'LandPlayed', { player: 0, card: bear }),
    ])

  it('un trigger cast avec filtre est posé et résolu comme les autres (le filtre est au moteur, pas au front)', () => {
    const v = start()
    const trigger = { event: 'cast' as const, effect: 'draw', amount: 1, filter: 'instant_sorcery' as const }
    applyEvent(v, ev(3, 'AbilityTriggered', { player: 0, stackId: 's1', card: bear, triggerIndex: 0, trigger, label: 'Quand vous lancez un éphémère ou un rituel : piocher 1', targets: [] }))
    expect(v.stack.map((i) => i.id)).toEqual(['s1'])
    expect(v.stack[0].trigger).toEqual(trigger)
    applyEvent(v, ev(4, 'AbilityResolved', { stackId: 's1', controller: 0, card: bear, abilityIndex: 0, trigger: true }))
    expect(v.stack).toEqual([])
  })

  it('un trigger attacks et un trigger end_step se posent sur la pile sans toucher la priorité autrement', () => {
    const v = start()
    v.priority = { holder: 1, passed: [0] }
    applyEvent(
      v,
      ev(3, 'AbilityTriggered', {
        player: 0,
        stackId: 's2',
        card: bear,
        triggerIndex: 0,
        trigger: { event: 'attacks', effect: 'damage', amount: 1 },
        label: 'Quand elle attaque : 1 dégât',
        targets: ['player:1'],
      }),
    )
    applyEvent(
      v,
      ev(4, 'AbilityTriggered', {
        player: 0,
        stackId: 's3',
        card: bear,
        triggerIndex: 1,
        trigger: { event: 'end_step', effect: 'draw', amount: 1 },
        label: 'Au début de votre fin de tour : piocher 1',
        targets: [],
      }),
    )
    expect(v.stack.map((i) => i.label)).toEqual(['Quand elle attaque : 1 dégât', 'Au début de votre fin de tour : piocher 1'])
    expect(v.priority.passed).toEqual([])
  })

  it("une seconde étape cleanup (ronde de priorité) réinitialise la priorité ; la ronde s'affiche comme un checkpoint", () => {
    const v = start()
    applyEvent(v, ev(3, 'PhaseChanged', { turn: 1, phase: 'cleanup', activePlayer: 0 }))
    expect(v.phase).toBe('cleanup')
    applyEvent(v, ev(4, 'PriorityGranted', { holder: 0, passed: [], stackSize: 0, checkpoint: true }))
    applyEvent(v, ev(5, 'PriorityPassed', { player: 0 }))
    expect(v.priority).toEqual({ holder: 0, passed: [0] })
    applyEvent(v, ev(6, 'PriorityGranted', { holder: 1, passed: [0], stackSize: 0, checkpoint: true }))
    applyEvent(v, ev(7, 'PriorityPassed', { player: 1 }))
    expect(v.priority.passed).toEqual([0, 1])
    // Fin de la ronde : nouvelle étape cleanup, la priorité repart à zéro.
    applyEvent(v, ev(8, 'PhaseChanged', { turn: 1, phase: 'cleanup', activePlayer: 0 }))
    expect(v.phase).toBe('cleanup')
    expect(v.priority).toEqual({ holder: null, passed: [] })
  })
})

describe('reducer — mulligan de Londres', () => {
  const ev = (seq: number, type: string, payload: Record<string, unknown>): GameEvent => ({ seq, type, payload, verbose: false })
  const card = (id: string): PublicCard => ({ id, name: `Carte ${id}`, type: 'land', cost: 0, effect: null, amount: 0 })
  const start = () =>
    replay([
      ev(1, 'GameStarted', {
        seed: 1,
        players: [
          { id: 0, life: 40, library: 93, controller: 'h' },
          { id: 1, life: 40, library: 93, controller: 'ai' },
        ],
      }),
      ev(2, 'CardDrawn', { player: 0, card: card('a'), library: 92 }),
      ev(3, 'CardDrawn', { player: 0, card: card('b'), library: 91 }),
      ev(4, 'CardDrawn', { player: 0, card: card('c'), library: 90 }),
    ])

  it('MulliganTaken vide la main et remet les cartes dans la bibliothèque', () => {
    const v = applyEvent(start(), ev(5, 'MulliganTaken', { player: 0, mulligans: 1, hand: 0, library: 96 }))
    expect(v.players[0].hand).toEqual([])
    expect(v.players[0].library).toBe(96)
    expect(v.players[1].library).toBe(93)
  })

  it('les 7 CardDrawn qui suivent refont la main', () => {
    const v = start()
    applyEvent(v, ev(5, 'MulliganTaken', { player: 0, mulligans: 1, hand: 0, library: 96 }))
    for (let i = 0; i < 7; i++) applyEvent(v, ev(6 + i, 'CardDrawn', { player: 0, card: card(`n${i}`), library: 89 - i }))
    expect(v.players[0].hand.map((c) => c.id)).toEqual(['n0', 'n1', 'n2', 'n3', 'n4', 'n5', 'n6'])
    expect(v.players[0].library).toBe(83)
  })

  it('CardPutOnBottom retire la carte de la main et met à jour les compteurs', () => {
    const v = start()
    applyEvent(v, ev(5, 'CardPutOnBottom', { player: 0, card: card('b'), hand: 2, library: 92 }))
    expect(v.players[0].hand.map((c) => c.id)).toEqual(['a', 'c'])
    expect(v.players[0].library).toBe(92)
  })

  it('CardPutOnTop (Brainstorm) retire la carte de la main et met à jour la bibliothèque', () => {
    const v = start()
    applyEvent(v, ev(5, 'CardPutOnTop', { player: 0, card: card('c'), hand: 2, library: 92, source: 'Brainstorm' }))
    expect(v.players[0].hand.map((c) => c.id)).toEqual(['a', 'b'])
    expect(v.players[0].library).toBe(92)
  })

  it("HandKept ne modifie pas l'état affiché", () => {
    const v = start()
    const snapshot = JSON.stringify(v.players)
    applyEvent(v, ev(5, 'HandKept', { player: 0, mulligans: 1, toBottom: 1 }))
    expect(JSON.stringify(v.players)).toBe(snapshot)
  })
})

describe('reducer — shocklands, fetchlands et PV payés', () => {
  const ev = (seq: number, type: string, payload: Record<string, unknown>): GameEvent => ({ seq, type, payload, verbose: false })
  const card = (id: string, name: string): PublicCard => ({ id, name, type: 'land', cost: 0, effect: null, amount: 0 })

  it("terrain engagé à l'arrivée, terrain cherché, mélange et PV payés en valeurs absolues", () => {
    const start = events.find((e) => e.type === 'GameStarted')!
    let v = replay(events, start.seq)
    const pl = v.players[0]
    pl.hand.push({ ...card('vents', 'Steam Vents'), tapped: false })
    let seq = start.seq
    v = applyEvent(v, ev(++seq, 'LandPlayed', { player: 0, card: card('vents', 'Steam Vents'), tapped: true }))
    expect(v.players[0].battlefield.find((c) => c.id === 'vents')?.tapped).toBe(true)
    expect(v.players[0].hand.some((c) => c.id === 'vents')).toBe(false)

    v = applyEvent(v, ev(++seq, 'LifePaid', { player: 0, amount: 1, life: 39, source: 'Polluted Delta' }))
    v = applyEvent(v, ev(++seq, 'LandFetched', { player: 0, card: card('grave', 'Watery Grave'), library: 80 }))
    v = applyEvent(v, ev(++seq, 'LifePaid', { player: 0, amount: 2, life: 37, source: 'Watery Grave' }))
    v = applyEvent(v, ev(seq + 1, 'LibraryShuffled', { player: 0, library: 80 }))
    const after = v.players[0]
    expect(after.life).toBe(37)
    expect(after.library).toBe(80)
    expect(after.battlefield.find((c) => c.id === 'grave')?.tapped).toBe(false)
  })
})

describe('reducer — pile et restrictions (lot H3)', () => {
  const ev = (seq: number, type: string, payload: Record<string, unknown>): GameEvent => ({ seq, type, payload, verbose: false })
  const bolt: PublicCard = { id: 'b1', name: 'Lightning Bolt', type: 'instant', cost: 1, effect: 'damage', amount: 3 }
  const start = () =>
    replay([
      ev(1, 'GameStarted', {
        seed: 1,
        players: [
          { id: 0, life: 40, library: 10, controller: 'h' },
          { id: 1, life: 40, library: 10, controller: 'ai' },
        ],
      }),
    ])

  it('copie, nouvelle cible, contrôle pris : la pile suit ; la copie disparaît, le sort volé va chez son propriétaire', () => {
    const v = start()
    v.stack.push({ id: 's1', card: bolt, controller: 1, targets: ['player:0'] })
    v.priority.passed = [0]
    applyEvent(v, ev(2, 'SpellCopied', { player: 1, card: bolt, stackId: 's3', targets: ['player:0'], source: 'Storm', copyOf: 's1' }))
    expect(v.stack.map((i) => i.id)).toEqual(['s1', 's3'])
    expect(v.priority.passed).toEqual([])
    applyEvent(v, ev(3, 'TargetChanged', { stackId: 's1', targets: ['player:1'], by: 's4', source: 'Deflecting Swat' }))
    expect(v.stack[0].targets).toEqual(['player:1'])
    applyEvent(v, ev(4, 'SpellControlChanged', { stackId: 's1', controller: 0, previous: 1, source: 'Commandeer' }))
    expect(v.stack[0].controller).toBe(0)
    applyEvent(v, ev(5, 'SpellResolved', { stackId: 's3', card: bolt, controller: 1, destination: 'none' }))
    applyEvent(v, ev(6, 'SpellResolved', { stackId: 's1', card: bolt, controller: 0, owner: 1, destination: 'graveyard' }))
    expect(v.stack).toEqual([])
    expect(v.players[0].graveyard).toEqual([])
    expect(v.players[1].graveyard.map((c) => c.id)).toEqual(['b1'])
  })

  it('SpellExiled exile chez le propriétaire ; CardsMilled ; jetons engagés et attaquants', () => {
    const v = start()
    v.stack.push({ id: 's1', card: bolt, controller: 1, targets: [] })
    applyEvent(v, ev(2, 'SpellExiled', { stackId: 's1', card: bolt, controller: 1, owner: 1, by: 's2', destination: 'exile' }))
    expect(v.stack).toEqual([])
    expect(v.players[1].exile.map((c) => c.id)).toEqual(['b1'])
    applyEvent(
      v,
      ev(3, 'CardsMilled', {
        player: 0,
        cards: [
          { ...bolt, id: 'm1' },
          { ...bolt, id: 'm2' },
        ],
        library: 8,
        source: 'Brain Freeze',
      }),
    )
    expect(v.players[0].library).toBe(8)
    expect(v.players[0].graveyard.map((c) => c.id)).toEqual(['m1', 'm2'])
    const warrior: PublicCard = { id: 'p0_t1', name: 'Warrior', type: 'creature', cost: 0, effect: null, amount: 0, token: true }
    applyEvent(v, ev(4, 'TokenCreated', { player: 0, card: warrior, tapped: true, attacking: 1 }))
    expect(v.players[0].battlefield[0]).toMatchObject({ tapped: true, attacking: 1 })
    applyEvent(v, ev(5, 'TokenCreated', { player: 1, card: { ...warrior, id: 'p1_t2' } }))
    expect(v.players[1].battlefield[0].tapped).toBe(false)
    expect(v.players[1].battlefield[0].attacking).toBeUndefined()
  })
})
