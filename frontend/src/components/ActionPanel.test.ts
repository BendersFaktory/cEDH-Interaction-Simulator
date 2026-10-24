// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSimulationStore } from '../stores/simulation'
import type { ActionView, DecisionRequest, SimulationView } from '../types/engine'
import ActionPanel from './ActionPanel.vue'

function simWith(actions: ActionView[], extra: Partial<DecisionRequest> = {}): SimulationView {
  return {
    id: 1,
    parentId: null,
    forkEventSeq: null,
    seed: 1,
    status: 'waiting',
    eventCount: 0,
    controllers: { 0: 'human:auto' },
    pending: { player: 0, eventSeq: 1, turn: 3, phase: 'main1', activePlayer: 0, declaring: null, priority: { holder: 0, passed: [], announced: false }, stack: [], actions, ...extra },
  }
}

describe('ActionPanel', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('ne montre rien sans décision en attente', () => {
    const w = mount(ActionPanel)
    expect(w.find('section.actions').exists()).toBe(false)
  })

  it('range les actions par groupe, dans un ordre fixe, et envoie la décision cliquée', async () => {
    const store = useSimulationStore()
    store.simulation = simWith([
      { id: 'a-pass', label: 'Passer', type: 'pass', player: 0 },
      { id: 'a-land', label: 'Jouer Island', type: 'land', player: 0, cardId: 'c1' },
      { id: 'a-ab', label: 'Engager Island', type: 'ability', player: 0, cardId: 'c1', mana: true },
    ])
    const decide = vi.fn()
    store.decide = decide
    const w = mount(ActionPanel)
    expect(w.find('h3').text()).toContain('J0')
    expect(w.findAll('h4').map((h) => h.text())).toEqual(['Terrains', 'Capacités', 'Priorité'])
    const mana = w.find('button.mana-ability')
    expect(mana.text()).toBe('Engager Island')
    await w.findAll('button')[0].trigger('click')
    expect(decide).toHaveBeenCalledWith('a-land')
  })

  it('propose le choix de légende (KeepLegend) avec son titre', async () => {
    const store = useSimulationStore()
    store.simulation = simWith([
      { id: 'k1', label: 'Garder Dark Ritual (c1)', type: 'keepLegend', player: 0, cardId: 'c1' },
      { id: 'k2', label: 'Garder Dark Ritual (c2)', type: 'keepLegend', player: 0, cardId: 'c2' },
    ])
    const decide = vi.fn()
    store.decide = decide
    const w = mount(ActionPanel)
    expect(w.findAll('h4').map((h) => h.text())).toEqual(['Règle des légendes : quelle légende garder ?'])
    const buttons = w.findAll('button')
    expect(buttons).toHaveLength(2)
    await buttons[1].trigger('click')
    expect(decide).toHaveBeenCalledWith('k2')
  })

  it('indique la déclaration en cours et désactive les boutons pendant un chargement', () => {
    const store = useSimulationStore()
    store.simulation = simWith([{ id: 'd', label: 'Terminer', type: 'done', player: 0 }], { declaring: 'attackers' })
    store.loading = true
    const w = mount(ActionPanel)
    expect(w.find('p.muted').text()).toContain('déclaration des attaquants')
    expect(w.find('button').attributes('disabled')).toBeDefined()
  })
})
