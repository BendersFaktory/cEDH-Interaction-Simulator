// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useSimulationStore } from '../stores/simulation'
import type { BoardCard, SimulationView } from '../types/engine'
import CardView from './CardView.vue'

const card: BoardCard = { id: 'c1', name: 'Counterspell', type: 'instant', cost: 2, effect: 'counter', manaCost: '{U}{U}', amount: 0, tapped: false }
const sim: SimulationView = {
  id: 1,
  parentId: null,
  forkEventSeq: null,
  seed: 1,
  status: 'waiting',
  pending: null,
  eventCount: 0,
  controllers: { 0: 'human:auto' },
  cardImages: { Counterspell: { small: 'https://img.example/c.jpg', normal: null } },
}

describe('CardView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useSimulationStore().simulation = sim
  })

  it("affiche l'art, puis se replie sur le texte quand l'image ne charge pas", async () => {
    const w = mount(CardView, { props: { card } })
    const img = w.find('img.card-art')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe('https://img.example/c.jpg')
    await img.trigger('error')
    expect(w.find('img.card-art').exists()).toBe(false)
    expect(w.find('.card-name').text()).toBe('Counterspell')
  })

  it("n'affiche pas l'art en mode compact", () => {
    const w = mount(CardView, { props: { card, compact: true } })
    expect(w.find('img.card-art').exists()).toBe(false)
    expect(w.classes()).toContain('compact')
  })

  it("n'a pas d'art pour un jeton ni quand aucune image n'est connue", () => {
    expect(
      mount(CardView, { props: { card: { ...card, token: true } } })
        .find('img.card-art')
        .exists(),
    ).toBe(false)
    expect(
      mount(CardView, { props: { card: { ...card, name: 'Inconnue' } } })
        .find('img.card-art')
        .exists(),
    ).toBe(false)
  })

  it('marque une carte engagée et publie la carte survolée', async () => {
    const w = mount(CardView, { props: { card: { ...card, tapped: true } } })
    expect(w.classes()).toContain('tapped')
    await w.trigger('mouseenter')
    expect(useSimulationStore().hoveredCard?.id).toBe('c1')
    await w.trigger('mouseleave')
    expect(useSimulationStore().hoveredCard).toBeNull()
  })
})
