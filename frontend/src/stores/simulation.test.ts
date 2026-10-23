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

describe('création asynchrone', () => {
  const fetchMock = vi.fn()
  beforeEach(() => {
    vi.useFakeTimers()
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  const ok = (body: unknown) => ({ ok: true, json: async () => body })

  it('sonde GET /simulations/{id} toutes les 200 ms jusqu\'à la fin puis charge le journal', async () => {
    const store = useSimulationStore()
    fetchMock
      .mockResolvedValueOnce(ok({ ...base, status: 'queued', progress: null })) // POST
      .mockResolvedValueOnce(ok({ ...base, status: 'running', progress: 3 })) // poll 1
      .mockResolvedValueOnce(ok({ ...base, status: 'over', progress: 9 })) // poll 2
      .mockResolvedValueOnce(ok({ ...base, status: 'over' })) // load: get
      .mockResolvedValueOnce(ok([])) // load: events
    const done = store.create(1, false)

    await vi.advanceTimersByTimeAsync(0)
    expect(store.waiting).toBe(true)
    await vi.advanceTimersByTimeAsync(200)
    expect(store.progress).toBe(3)
    expect(fetchMock.mock.calls[1][0]).toBe('/api/simulations/7')
    await vi.advanceTimersByTimeAsync(200)
    await done

    expect(store.waiting).toBe(false)
    expect(store.simulation?.status).toBe('over')
    expect(store.error).toBeNull()
  })

  it('envoie le profil d\'IA choisi pour chaque siège', async () => {
    const store = useSimulationStore()
    fetchMock
      .mockResolvedValueOnce(ok({ ...base, status: 'over' })) // POST
      .mockResolvedValueOnce(ok({ ...base, status: 'over' })) // load: get
      .mockResolvedValueOnce(ok([])) // load: events
    await store.create(1, true, ['ai:heuristic', 'ai:aggro', 'ai:control'])

    const body = JSON.parse(fetchMock.mock.calls[0][1].body)
    expect(body.controllers).toEqual({ 0: 'human:auto', 1: 'ai:aggro', 2: 'ai:control', 3: 'ai:heuristic' })
  })

  it('affiche une erreur quand la simulation échoue', async () => {
    const store = useSimulationStore()
    fetchMock
      .mockResolvedValueOnce(ok({ ...base, status: 'queued' }))
      .mockResolvedValueOnce(ok({ ...base, status: 'failed', error: 'boum' }))
    const done = store.create(1, false)
    await vi.advanceTimersByTimeAsync(200)
    await done
    expect(store.error).toBe('boum')
  })
})

describe('decklists', () => {
  const fetchMock = vi.fn()
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => vi.unstubAllGlobals())

  it('envoie les decks par siège dans le POST', async () => {
    const store = useSimulationStore()
    fetchMock.mockResolvedValueOnce({ ok: false, status: 422, statusText: '', json: async () => ({ error: 'Decklist refusée.' }) })
    await store.create(1, true, [], { 0: 'CMDR: X\n99 Plains', 1: { decklist: '99 Plains', commander: 'Y' } })
    const body = JSON.parse(fetchMock.mock.calls[0][1].body)
    expect(body.decks).toEqual({ 0: 'CMDR: X\n99 Plains', 1: { decklist: '99 Plains', commander: 'Y' } })
  })

  it("n'envoie pas `decks` sans decklist, et expose les problèmes d'un 422", async () => {
    const store = useSimulationStore()
    const problems = { J0: ['Rhystic Study : texte non modélisé'] }
    fetchMock.mockResolvedValueOnce({ ok: false, status: 422, statusText: '', json: async () => ({ error: 'Decklist refusée.', problems }) })
    await store.create(1, true)
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).not.toHaveProperty('decks')
    expect(store.error).toBe('Decklist refusée.')
    expect(store.deckProblems).toEqual(problems)
  })
})
