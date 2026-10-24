import { describe, expect, it } from 'vitest'
import { abilityText, colorsOf, frameOf, spellText, triggerText, typeLine } from './cardText'
import type { PublicCard } from '../types/engine'

const card = (c: Partial<PublicCard>): PublicCard => ({ id: 'c1', name: 'X', type: 'instant', cost: 0, effect: null, amount: 0, ...c })

describe('cardText', () => {
  it('cadre : une couleur, multicolore (hybride compris), incolore, terrain par son mana', () => {
    expect(frameOf(card({ manaCost: '{R}' }))).toBe('R')
    expect(colorsOf(card({ manaCost: '{1}{R/G}' }))).toEqual(['R', 'G'])
    expect(frameOf(card({ manaCost: '{1}{R/G}' }))).toBe('multi')
    expect(frameOf(card({ type: 'artifact', manaCost: '{1}' }))).toBe('C')
    expect(frameOf(card({ type: 'land', manaCost: null, produces: ['U'] }))).toBe('U')
  })

  it('ligne de type avec sous-types et commandant', () => {
    expect(typeLine(card({ type: ['artifact', 'creature'], subtypes: { creature: ['Eldrazi'] } }))).toBe('Artefact Créature — Eldrazi')
    expect(typeLine(card({ type: 'creature', commander: true }))).toBe('Commandant · Créature')
  })

  it('textes : sort, capacité, trigger', () => {
    expect(spellText(card({ effect: 'damage', amount: 3 }))).toBe('inflige 3 dégâts')
    expect(spellText(card({ effect: 'damage', amount: 0 }))).toBe('inflige X dégâts')
    expect(
      spellText(
        card({
          effect: 'damage',
          amount: 2,
          effects: [
            { effect: 'damage', amount: 2 },
            { effect: 'draw', amount: 1 },
          ],
        }),
      ),
    ).toBe('inflige 2 dégâts, puis pioche 1')
    expect(abilityText({ isManaAbility: false, effect: 'draw', tap: true, manaCost: '{2}', amount: 1 })).toBe('{2}, {T} : pioche 1')
    expect(triggerText({ event: 'etb', effect: 'damage', amount: 2 })).toBe('Quand elle arrive en jeu : inflige 2 dégâts')
  })
})
