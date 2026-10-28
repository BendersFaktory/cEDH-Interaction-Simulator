// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useSimulationStore } from '../stores/simulation'
import { STOP_STEPS, type SimulationView } from '../types/engine'
import StopsPanel from './StopsPanel.vue'

const sim: SimulationView = {
  id: 3,
  parentId: null,
  forkEventSeq: null,
  seed: 1,
  status: 'waiting',
  pending: null,
  eventCount: 0,
  controllers: { 0: 'human:auto', 1: 'ai:heuristic' },
}

describe('StopsPanel', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('propose le nettoyage comme arrêt (le dégagement n’a pas de ronde de priorité, CR 502.3)', () => {
    const store = useSimulationStore()
    store.simulation = sim
    const wrapper = mount(StopsPanel)

    const labels = wrapper.findAll('label').map((l) => l.text())
    expect(labels).toContain('Nettoyage')
    expect(labels.some((t) => t.includes('Dégagement'))).toBe(false)
    expect(STOP_STEPS.at(-1)).toBe('cleanup')
  })
})
