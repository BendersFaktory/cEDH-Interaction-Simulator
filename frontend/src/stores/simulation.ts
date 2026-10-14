import { defineStore } from 'pinia'
import { computed, onScopeDispose, ref } from 'vue'
import { api } from '../api/client'
import { replay } from '../engine/reducer'
import type { GameEvent, SimulationView, StopsConfig } from '../types/engine'

/**
 * Store central : journal d'événements + curseur.
 * L'état affiché (`view`) est TOUJOURS dérivé : replay(events, cursor).
 * Lecture, pause, pas à pas, retour arrière et fork reposent tous sur ce seul principe.
 */
export const useSimulationStore = defineStore('simulation', () => {
  const simulation = ref<SimulationView | null>(null)
  const events = ref<GameEvent[]>([])
  const cursor = ref(0) // seq du dernier événement appliqué (0 = rien)
  const playing = ref(false)
  const speedMs = ref(250)
  const showVerbose = ref(false)
  const loading = ref(false)
  const error = ref<string | null>(null)

  let timer: ReturnType<typeof setInterval> | null = null

  const lastSeq = computed(() => events.value.at(-1)?.seq ?? 0)
  const atEnd = computed(() => cursor.value >= lastSeq.value)
  const view = computed(() => replay(events.value, cursor.value))
  const visibleEvents = computed(() => events.value.filter((e) => showVerbose.value || !e.verbose))

  /**
   * Points de bifurcation valides : les PriorityGranted marqués `checkpoint` par le moteur
   * (début de ronde, ou pile non vide). Les autres octrois n'ont pas de snapshot.
   */
  const checkpointSeqs = computed(() =>
    events.value.filter((e) => e.type === 'PriorityGranted' && e.payload.checkpoint).map((e) => e.seq),
  )
  const forkPoint = computed(() => {
    const before = checkpointSeqs.value.filter((s) => s <= cursor.value)
    return before.length ? before[before.length - 1] : null
  })

  /** La décision en attente n'est proposée que quand on regarde la fin du journal. */
  const pending = computed(() => (atEnd.value ? (simulation.value?.pending ?? null) : null))

  async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    loading.value = true
    error.value = null
    try {
      return await fn()
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      return undefined
    } finally {
      loading.value = false
    }
  }

  async function load(id: number, toEnd = false): Promise<void> {
    pause()
    await run(async () => {
      simulation.value = await api.get(id)
      events.value = await api.events(id)
      cursor.value = toEnd ? lastSeq.value : 0
    })
  }

  /**
   * Nouvelle partie Commander à 4. `playAsJ0` : tu prends le siège J0 (human:auto) contre 3 IA ;
   * sinon les 4 sièges sont des IA et tu regardes, quitte à bifurquer ensuite.
   */
  async function create(seed?: number, playAsJ0 = true): Promise<void> {
    pause()
    await run(async () => {
      const ai = 'ai:heuristic'
      const sim = await api.create({
        seed,
        controllers: { 0: playAsJ0 ? 'human:auto' : ai, 1: ai, 2: ai, 3: ai },
      })
      // Si la partie t'attend déjà, on se place à la fin du journal pour voir tes actions possibles.
      await load(sim.id, sim.status === 'waiting')
    })
  }

  // --- navigation ---

  function nextVisibleSeq(from: number): number | null {
    return visibleEvents.value.find((e) => e.seq > from)?.seq ?? null
  }

  function prevVisibleSeq(from: number): number {
    const before = visibleEvents.value.filter((e) => e.seq < from)
    return before.length ? before[before.length - 1].seq : 0
  }

  function step(): void {
    const next = nextVisibleSeq(cursor.value)
    if (next === null) pause()
    else cursor.value = next
  }

  function stepBack(): void {
    cursor.value = prevVisibleSeq(cursor.value)
  }

  function seek(seq: number): void {
    cursor.value = Math.max(0, Math.min(seq, lastSeq.value))
  }

  function play(): void {
    if (timer) return
    playing.value = true
    timer = setInterval(step, speedMs.value)
  }

  function pause(): void {
    playing.value = false
    if (timer) clearInterval(timer)
    timer = null
  }

  function setSpeed(ms: number): void {
    speedMs.value = ms
    if (playing.value) {
      pause()
      play()
    }
  }

  // --- fork & décisions ---

  /** « Reprendre la main ici » : crée une branche et prend le contrôle des joueurs donnés. */
  async function forkHere(takeOver: number[]): Promise<void> {
    if (!simulation.value || forkPoint.value === null || takeOver.length === 0) return
    pause()
    await run(async () => {
      const controllers = Object.fromEntries(takeOver.map((id) => [id, 'human:auto']))
      const child = await api.fork(simulation.value!.id, forkPoint.value!, controllers)
      await load(child.id, true)
    })
  }

  async function decide(actionId: string): Promise<void> {
    if (!simulation.value) return
    await run(async () => {
      const after = lastSeq.value
      simulation.value = await api.decide(simulation.value!.id, actionId)
      events.value = [...events.value, ...(await api.events(simulation.value.id, after))]
      cursor.value = lastSeq.value
    })
  }

  /** Sièges humains (contrôleur `human:*`) de la simulation courante. */
  const humanSeats = computed(() =>
    Object.entries(simulation.value?.controllers ?? {})
      .filter(([, c]) => c.startsWith('human'))
      .map(([id]) => Number(id)),
  )

  /** Stops d'un joueur tels que renvoyés par le serveur (null = défaut). */
  function stopsOf(player: number): StopsConfig | null {
    return simulation.value?.stops?.[String(player)] ?? null
  }

  /** Applique des stops (`null` = défaut) ; la réponse est traitée comme celle d'une décision. */
  async function setStops(player: number, stops: StopsConfig | null): Promise<void> {
    if (!simulation.value) return
    await run(async () => {
      const after = lastSeq.value
      simulation.value = await api.setStops(simulation.value!.id, player, stops)
      events.value = [...events.value, ...(await api.events(simulation.value.id, after))]
      cursor.value = lastSeq.value
    })
  }

  onScopeDispose(pause)

  return {
    simulation, events, cursor, playing, speedMs, showVerbose, loading, error,
    lastSeq, atEnd, view, visibleEvents, checkpointSeqs, forkPoint, pending,
    humanSeats, stopsOf, setStops,
    load, create, step, stepBack, seek, play, pause, setSpeed, forkHere, decide,
  }
})
