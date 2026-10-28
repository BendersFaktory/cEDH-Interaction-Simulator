import { describe, expect, it } from 'vitest'
import { abilityText, altCastText, colorsOf, frameOf, spellText, staticText, triggerText, typeLine } from './cardText'
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

  it('lot H5a : triggers, capacités et mana à marqueur', () => {
    expect(triggerText({ event: 'any_cast', effect: 'lose_life_treasure', amount: 1, filter: 'second_spell' })).toBe(
      'Quand un joueur lance un sort (son deuxième du tour) : perd 1 PV et crée un Trésor',
    )
    expect(triggerText({ event: 'opponent_draw', effect: 'treasure', amount: 1, spec: 'tapped', filter: 'off_turn_once' })).toBe(
      'Quand un adversaire pioche une carte hors de son tour (une fois par tour) : crée 1 Trésor engagé',
    )
    expect(abilityText({ isManaAbility: false, effect: 'two_players_draw', tap: false, manaCost: '{2}{W}', amount: 1 })).toBe('{2}{W} : deux joueurs ciblés piochent 1')
    expect(abilityText({ isManaAbility: true, effect: 'mana', tap: true, spec: 'luck', options: [] })).toContain('marqueur de chance')
  })

  it('Flashback et Dash', () => {
    expect(altCastText(card({ altCasts: { flashback: '{2}{R}', dash: '{1}{R}' } }))).toEqual([
      'Flashback {2}{R} (depuis le cimetière, puis exilée)',
      'Dash {1}{R} (célérité, revient en main à la fin du tour)',
    ])
    expect(altCastText(card({}))).toEqual([])
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
    expect(triggerText({ event: 'etb', effect: 'treasure', amount: 1, spec: 'tapped' })).toBe('Quand elle arrive en jeu : crée 1 Trésor engagé')
    expect(
      spellText(
        card({
          effect: 'counter',
          effects: [
            { effect: 'counter', target: 'noncreature' },
            { effect: 'treasure', amount: 2, target: 'spell_controller' },
          ],
        }),
      ),
    ).toContain('le contrôleur du sort visé crée 2 Trésor')
    expect(triggerText({ event: 'draw_step', effect: 'self_damage', amount: 1, filter: 'tapped' })).toBe('Au début de ton étape de pioche, si elle est engagée : inflige 1 dégâts à son contrôleur')
    expect(abilityText({ isManaAbility: false, effect: 'untap', tap: false, manaCost: '{4}', upkeepOnly: true })).toBe('{4} : se dégage (à ton entretien)')
    expect(spellText(card({ effect: 'counter', effects: [{ effect: 'counter', payCost: '{1}' }] }))).toBe('contrecarre le sort ciblé sauf si son contrôleur paie {1}')
    expect(spellText(card({ effect: 'counter', effects: [{ effect: 'counter', manaValue: 1 }] }))).toBe('contrecarre le sort ciblé de valeur de mana 1')
    expect(
      spellText(
        card({
          effect: 'counter',
          effects: [{ effect: 'counter' }, { effect: 'pact', payCost: '{3}{U}{U}' }],
        }),
      ),
    ).toBe('contrecarre le sort ciblé, puis à ton prochain entretien, paie {3}{U}{U} ou perds la partie')
    expect(triggerText({ event: 'next_main', effect: 'mana', amount: 2 })).toBe('Au début de ta prochaine phase principale : ajoute {C}{C}')
    expect(triggerText({ event: 'opponent_cast', effect: 'draw_unless', amount: 1, spec: '{1}' })).toBe("Quand un adversaire lance un sort : pioche 1 sauf s'il paie {1}")
    expect(
      spellText(
        card({
          effect: 'draw',
          effects: [
            { effect: 'draw', amount: 3 },
            { effect: 'put_back', amount: 2 },
          ],
        }),
      ),
    ).toBe("pioche 3, puis remet 2 cartes de sa main sur le dessus de sa bibliothèque, dans l'ordre de son choix")
    expect(
      spellText(
        card({
          effect: 'arrange',
          effects: [
            { effect: 'arrange', amount: 3 },
            { effect: 'draw', amount: 1 },
          ],
        }),
      ),
    ).toBe("regarde les 3 cartes du dessus et les remet dans l'ordre de son choix (ou mélange), puis pioche 1")
    expect(triggerText({ event: 'next_upkeep', effect: 'pact', amount: 0, spec: '{3}{U}{U}' })).toBe('Au début de ton prochain entretien : paie {3}{U}{U} ou perd la partie')
    expect(abilityText({ isManaAbility: true, effect: 'mana', tap: true, options: ['{W}', '{U}'], spec: 'oppland' })).toBe("{T} : un mana d'une couleur que produit un terrain adverse")
  })
})

describe('cardText — combos (lot H5b)', () => {
  const base: PublicCard = { id: 'x', name: 'X', type: 'sorcery', cost: 1, effect: null, amount: 0 }
  it('effets, triggers, capacité et statiques des cartes de combo', () => {
    expect(spellText({ ...base, effect: 'extra_turn', amount: 1 })).toBe("joue un tour supplémentaire après celui-ci ; perd la partie au début de l'étape de fin de ce tour")
    expect(spellText({ ...base, effect: 'tutor', effects: [{ effect: 'tutor', freeCastMax: 4 }] })).toContain('valeur de mana ≤ 4')
    expect(triggerText({ event: 'each_end_step', effect: 'sacrifice_self', amount: 0 })).toBe('Au début de chaque étape de fin : se sacrifie')
    expect(abilityText({ isManaAbility: false, effect: 'necro', tap: false, lifeCost: 1 })).toBe('payer 1 PV : exile face cachée la carte du dessus ; elle arrive en main à ta prochaine étape de fin')
    expect(staticText({ ...base, statics: ['skip_draw'], extraCost: 'bargain' })).toEqual([
      'Passe ton étape de pioche',
      'Bargain : tu peux sacrifier un artefact, un enchantement ou un jeton en le lançant',
    ])
  })
})
