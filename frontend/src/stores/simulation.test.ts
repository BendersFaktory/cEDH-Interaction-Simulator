import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSimulationStore } from './simulation'
import type { SimulationView, StopsConfig } from '../types/engine'

const stops: StopsConfig = { steps: ['upkeep', 'main1'], onOpponentSpell: true, onDeclareAttackers: false, onDeclareBlockers: true }
const base: SimulationView = {
  id: 7, parentId: null, forkEventSeq: null, seed: 1, status: 'waiting', pending: null, eventCount: 0,
  controllers: { 0: 'human:auto', 1: 'ai:heuristic' },
}

describe('stops', () => {
  const fetchMock = vi.fn()
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => vi.unstubAllGlobals())

  const ok = (body: unknown) => ({ ok: true, json: async () => body })

  it('envoie un PUT /stops puis traite la réponse comme une décision', async () => {
    const store = useSimulationStore()
    store.simulation = base
    expect(store.humanSeats).toEqual([0])
    expect(store.stopsOf(0)).toBeNull()

    fetchMock.mockResolvedValueOnce(ok({ ...base, stops: { 0: stops } })).mockResolvedValueOnce(ok([]))
    await store.setStops(0, stops)

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/simulations/7/stops')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(init.body)).toEqual({ player: 0, stops })
    expect(fetchMock.mock.calls[1][0]).toBe('/api/simulations/7/events?after=0')
    expect(store.stopsOf(0)).toEqual(stops)
  })

  it('envoie null pour revenir au défaut', async () => {
    const store = useSimulationStore()
    store.simulation = { ...base, stops: { 0: stops } }
    fetchMock.mockResolvedValueOnce(ok(base)).mockResolvedValueOnce(ok([]))
    await store.setStops(0, null)
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ player: 0, stops: null })
    expect(store.stopsOf(0)).toBeNull()
  })
})
