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

describe('describeEvent — capacités', () => {
  const e = (type: string, payload: Record<string, unknown>): GameEvent => ({ seq: 1, type, verbose: false, payload })
  it('mana', () => {
    expect(describeEvent(e('ManaAbilityActivated', { player: 0, card: { name: 'Mountain' }, tapped: ['x'], mana: { R: 1 } }))).toBe('J0 engage Mountain : {R}')
    expect(describeEvent(e('ManaAbilityActivated', { player: 0, card: { name: 'Sol Ring' }, tapped: ['x'], mana: { C: 2 } }))).toBe('J0 engage Sol Ring : {C}{C}')
    expect(describeEvent(e('ManaAbilityActivated', { player: 0, card: { name: 'Sol Ring' }, tapped: [], mana: { C: 2 } }))).toBe('J0 active Sol Ring : {C}{C}')
    expect(describeEvent(e('ManaAbilityActivated', { player: 0, card: { name: 'Eldrazi Temple' }, tapped: ['x'], mana: { C: 2 }, ability: { options: ['{C}{C}@eldrazi'] }, option: 0 }))).toBe(
      "J0 engage Eldrazi Temple : {C}{C} (mana restreint : seulement pour un sort ou une capacité d'Eldrazi incolore)",
    )
    expect(describeEvent(e('ManaAdded', { player: 0, mana: { R: 2 } }))).toBe('J0 gagne {R}{R}')
    expect(describeEvent(e('ManaPoolEmptied', { player: 0, lost: { R: 1 } }))).toBe('J0 perd son mana flottant {R}')
  })
  it('capacité non-mana', () => {
    expect(describeEvent(e('AbilityActivated', { player: 0, card: { name: 'Pinger' }, ability: { effect: 'damage', amount: 1 }, targets: ['player:1'] }))).toBe('J0 active Pinger (1 dégât) → J1')
    expect(describeEvent(e('AbilityResolved', { card: { name: 'Pinger' } }))).toBe('Capacité de Pinger résolue')
    expect(describeEvent(e('AbilityTriggered', { player: 2, card: { name: 'Perilous Myr' }, label: 'Quand elle meurt : 2 dégâts', targets: ['player:1'] }))).toBe(
      'Trigger de Perilous Myr (J2) : Quand elle meurt : 2 dégâts → J1',
    )
    expect(describeEvent(e('AbilityTriggered', { player: 0, card: { name: 'Elvish Visionary' }, label: 'Quand elle entre : piocher 1', targets: [] }))).toBe(
      'Trigger de Elvish Visionary (J0) : Quand elle entre : piocher 1',
    )
    expect(describeEvent(e('AbilityResolved', { card: { name: 'Perilous Myr' }, trigger: true }))).toBe('Trigger de Perilous Myr résolu')
  })
  it('jetons et sacrifices', () => {
    expect(describeEvent(e('TokenCreated', { player: 0, card: { name: 'Treasure' } }))).toBe('J0 crée un jeton Treasure')
    expect(describeEvent(e('PermanentSacrificed', { player: 0, card: { name: 'Treasure' }, destination: 'none' }))).toBe('J0 sacrifie Treasure')
  })
  it('bonus de force/endurance', () => {
    expect(describeEvent(e('CreatureBoosted', { player: 0, card: 'p0_c3', power: 3, toughness: 3 }))).toBe('p0_c3 (J0) passe à 3/3')
  })
  it('jetons et sacrifices', () => {
    expect(describeEvent(e('TokenCreated', { player: 0, card: { name: 'Treasure' } }))).toBe('J0 crée un jeton Treasure')
    expect(describeEvent(e('PermanentSacrificed', { player: 0, card: { name: 'Treasure' }, destination: 'none' }))).toBe('J0 sacrifie Treasure')
  })
  it('bonus de force/endurance', () => {
    expect(describeEvent(e('CreatureBoosted', { player: 0, card: 'p0_c3', power: 3, toughness: 3 }))).toBe('p0_c3 (J0) passe à 3/3')
  })
  it('exil', () => {
    expect(describeEvent(e('CardExiled', { player: 1, card: { name: 'Goblin Guide' }, destination: 'exile', source: 'Swords to Plowshares' }))).toBe(
      'Goblin Guide (J1) est exilé par Swords to Plowshares',
    )
    expect(describeEvent(e('CardExiled', { player: 1, card: { name: 'Spirit' }, destination: 'none', source: 'Path' }))).toBe("Spirit (J1) est exilé par Path (le jeton cesse d'exister)")
    expect(describeEvent(spell({ card: { name: 'Swords to Plowshares' }, targets: ['permanent:p1_c4'] }))).toBe('J1 lance Swords to Plowshares → permanent p1_c4')
  })
})

describe('describeEvent — mulligan de Londres', () => {
  const e = (type: string, payload: Record<string, unknown>): GameEvent => ({ seq: 1, type, verbose: false, payload })
  it('prise de mulligan, garde et mise au fond', () => {
    expect(describeEvent(e('MulliganTaken', { player: 0, mulligans: 1, hand: 0, library: 96 }))).toBe('J0 prend un mulligan (1)')
    expect(describeEvent(e('HandKept', { player: 0, mulligans: 1, toBottom: 1 }))).toBe('J0 garde sa main (1 carte au fond)')
    expect(describeEvent(e('HandKept', { player: 2, mulligans: 2, toBottom: 2 }))).toBe('J2 garde sa main (2 cartes au fond)')
    expect(describeEvent(e('HandKept', { player: 0, mulligans: 0, toBottom: 0 }))).toBe('J0 garde sa main')
    expect(describeEvent(e('CardPutOnBottom', { player: 0, card: { name: 'Forest' }, hand: 6, library: 94 }))).toBe('J0 met une carte au fond de sa bibliothèque')
  })
})
