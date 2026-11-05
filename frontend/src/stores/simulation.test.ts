import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { useSimulationStore } from './simulation'
import type { SimulationView, StopsConfig } from '../types/engine'

const stops: StopsConfig = { steps: ['upkeep', 'main1'], onOpponentSpell: true, onDeclareAttackers: false, onDeclareBlockers: true }
const base: SimulationView = {
  id: 7,
  parentId: null,
  forkEventSeq: null,
  seed: 1,
  status: 'waiting',
  pending: null,
  eventCount: 0,
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

  it("sonde GET /simulations/{id} toutes les 200 ms jusqu'à la fin puis charge le journal", async () => {
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

  it("envoie le profil d'IA choisi pour chaque siège", async () => {
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
    fetchMock.mockResolvedValueOnce(ok({ ...base, status: 'queued' })).mockResolvedValueOnce(ok({ ...base, status: 'failed', error: 'boum' }))
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

describe("rendre la main à l'IA", () => {
  const fetchMock = vi.fn()
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => vi.unstubAllGlobals())

  const ok = (body: unknown) => ({ ok: true, json: async () => body })
  const checkpoint = { seq: 3, type: 'PriorityGranted', payload: { holder: 1, checkpoint: true, stackSize: 0 }, verbose: false }

  it('forke au point de bifurcation avec le profil choisi et ouvre la branche', async () => {
    const store = useSimulationStore()
    store.simulation = { ...base, controllers: { 0: 'ai:heuristic', 1: 'human:auto' } }
    store.events = [checkpoint as never]
    store.cursor = 3
    const child = { ...base, id: 8, parentId: 7, forkEventSeq: 3, controllers: { 0: 'ai:heuristic', 1: 'ai:aggro' } }
    fetchMock.mockResolvedValueOnce(ok(child)).mockResolvedValueOnce(ok(child)).mockResolvedValueOnce(ok([]))

    await store.handBackToAi(1, 'ai:aggro')

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/simulations/7/fork')
    expect(JSON.parse(init.body)).toEqual({ atEvent: 3, controllers: { 1: 'ai:aggro' } })
    expect(store.simulation?.id).toBe(8)
    expect(store.humanSeats).toEqual([])
  })

  it('reprend le profil de création du siège par défaut, sinon ai:heuristic', async () => {
    const store = useSimulationStore()
    store.simulation = { ...base, controllers: { 0: 'ai:heuristic', 1: 'human:auto' } }
    store.events = [checkpoint as never]
    store.cursor = 3
    store.seatProfiles = { 0: 'ai:heuristic', 1: 'ai:control' }
    const child = { ...base, id: 8, controllers: { 1: 'ai:control' } }
    fetchMock.mockResolvedValue(ok(child))

    await store.handBackToAi(1)
    expect(JSON.parse(fetchMock.mock.calls[0][1].body).controllers).toEqual({ 1: 'ai:control' })

    fetchMock.mockClear()
    store.simulation = { ...base, controllers: { 0: 'ai:heuristic', 1: 'human:auto' } }
    store.events = [checkpoint as never]
    store.cursor = 3
    store.seatProfiles = {}
    await store.handBackToAi(1)
    expect(JSON.parse(fetchMock.mock.calls[0][1].body).controllers).toEqual({ 1: 'ai:heuristic' })
  })

  it('ne fait rien sans point de bifurcation', async () => {
    const store = useSimulationStore()
    store.simulation = { ...base, controllers: { 1: 'human:auto' } }
    await store.handBackToAi(1)
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('arbre des branches', () => {
  const fetchMock = vi.fn()
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => vi.unstubAllGlobals())

  const ok = (body: unknown) => ({ ok: true, json: async () => body })

  it("relit l'arbre auquel appartient la simulation courante", async () => {
    const store = useSimulationStore()
    store.simulation = base
    const tree = { current: 7, branches: [{ id: 7, parentId: null, forkEventSeq: null, status: 'waiting', eventCount: 0, humans: [0] }] }
    fetchMock.mockResolvedValueOnce(ok(tree))

    await store.refreshBranches()

    expect(fetchMock.mock.calls[0][0]).toBe('/api/simulations/7/branches')
    expect(store.branchTree).toEqual(tree)
  })

  it("ne fait pas d'appel sans simulation", async () => {
    const store = useSimulationStore()
    await store.refreshBranches()
    expect(fetchMock).not.toHaveBeenCalled()
    expect(store.branchTree).toBeNull()
  })
})

describe('choix de cible au clic', () => {
  const fetchMock = vi.fn()
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => vi.unstubAllGlobals())

  const ok = (body: unknown) => ({ ok: true, json: async () => body })
  const act = (id: string, targets: string[]) => ({ id, label: id, type: 'cast', player: 0, targets })
  /** Store à la fin du journal avec une décision en attente (la sim est « en attente » du joueur 0). */
  async function withActions(actions: unknown[]) {
    const store = useSimulationStore()
    store.simulation = { ...base, pending: { player: 0, eventSeq: 1, actions } as never }
    store.events = [{ seq: 1, type: 'PriorityGranted', payload: {}, verbose: false } as never]
    store.cursor = 1
    await nextTick() // le watch de la décision en attente s'applique avant le clic
    return store
  }

  it('envoie tout de suite une action dont la seule cible est cliquée', async () => {
    const store = await withActions([act('bolt', ['player:2'])])
    fetchMock.mockResolvedValueOnce(ok(base)).mockResolvedValueOnce(ok([]))
    expect(store.targetHighlights.has('player:2')).toBe(true)

    await store.pickTarget('player:2')

    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ actionId: 'bolt' })
    expect(store.pickedTargets).toEqual([])
  })

  it('accumule les cibles tant qu’une action plus longue reste possible', async () => {
    const store = await withActions([act('two', ['permanent:p1', 'player:2']), act('one', ['permanent:p1'])])
    fetchMock.mockResolvedValueOnce(ok(base)).mockResolvedValueOnce(ok([]))

    await store.pickTarget('permanent:p1')
    expect(fetchMock).not.toHaveBeenCalled()
    expect(store.pickedTargets).toEqual(['permanent:p1'])
    expect(store.targetHighlights.has('player:2')).toBe(true)

    await store.pickTarget('player:2')
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ actionId: 'two' })
  })

  it('laisse le choix à l’action quand deux actions ont les mêmes cibles', async () => {
    const store = await withActions([act('x1', ['player:1']), act('x2', ['player:1'])])

    await store.pickTarget('player:1')

    expect(fetchMock).not.toHaveBeenCalled()
    expect(store.pickedTargets).toEqual(['player:1'])
    expect(store.pickCandidates.map((a) => a.id)).toEqual(['x1', 'x2'])
  })

  it('ignore une cible qui ne peut pas être choisie', async () => {
    const store = await withActions([act('bolt', ['player:2'])])

    await store.pickTarget('player:9')

    expect(fetchMock).not.toHaveBeenCalled()
    expect(store.pickedTargets).toEqual([])
  })
})
