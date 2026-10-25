import { defineStore } from 'pinia'
import { computed, onScopeDispose, ref, watch } from 'vue'
import { api, ApiError } from '../api/client'
import { replay } from '../engine/reducer'
import type { ActionView, BranchTree, DeckInput, GameEvent, PublicCard, SimulationView, StopsConfig } from '../types/engine'

/** Profils d'IA proposés pour un siège (clés acceptées par l'API, cf. `AgentRegistry`). */
export const AI_PROFILES = [
  { key: 'ai:heuristic', label: 'Équilibrée' },
  { key: 'ai:aggro', label: 'Aggro' },
  { key: 'ai:control', label: 'Contrôle' },
]

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
  /** Problèmes d'une decklist refusée (422), par siège (« J0 » → messages). */
  const deckProblems = ref<Record<string, string[]> | null>(null)
  /** Tour courant de la simulation en cours côté serveur (null hors attente). */
  const progress = ref<number | null>(null)
  const waiting = ref(false)
  /** Profil d'IA choisi par siège à la création : sert de défaut quand on rend la main à l'IA. */
  const seatProfiles = ref<Record<number, string>>({})
  const POLL_MS = 200

  let timer: ReturnType<typeof setInterval> | null = null

  const lastSeq = computed(() => events.value.at(-1)?.seq ?? 0)
  const atEnd = computed(() => cursor.value >= lastSeq.value)
  const view = computed(() => replay(events.value, cursor.value))
  const visibleEvents = computed(() => events.value.filter((e) => showVerbose.value || !e.verbose))

  /**
   * Points de bifurcation valides : les PriorityGranted marqués `checkpoint` par le moteur
   * (début de ronde, ou pile non vide). Les autres octrois n'ont pas de snapshot.
   */
  const checkpointSeqs = computed(() => events.value.filter((e) => e.type === 'PriorityGranted' && e.payload.checkpoint).map((e) => e.seq))
  const forkPoint = computed(() => {
    const before = checkpointSeqs.value.filter((s) => s <= cursor.value)
    return before.length ? before[before.length - 1] : null
  })

  /** La décision en attente n'est proposée que quand on regarde la fin du journal. */
  const pending = computed(() => (atEnd.value ? (simulation.value?.pending ?? null) : null))

  async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    loading.value = true
    error.value = null
    deckProblems.value = null
    try {
      return await fn()
    } catch (e) {
      deckProblems.value = e instanceof ApiError ? (e.problems ?? null) : null
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
   * `ai` : profil d'IA par siège (`ai:heuristic`, `ai:aggro`, `ai:control`), `ai:heuristic` par défaut.
   */
  async function create(seed?: number, playAsJ0 = true, ai: string[] = [], decks: Record<number, DeckInput> = {}): Promise<void> {
    pause()
    const seat = (i: number) => ai[i] ?? 'ai:heuristic'
    seatProfiles.value = { 0: seat(0), 1: seat(1), 2: seat(2), 3: seat(3) }
    await run(async () => {
      const sim = await api.create({
        seed,
        controllers: { 0: playAsJ0 ? 'human:auto' : seat(0), 1: seat(1), 2: seat(2), 3: seat(3) },
        ...(Object.keys(decks).length ? { decks } : {}),
      })
      const done = await waitUntilPlayed(sim)
      // Si la partie t'attend déjà, on se place à la fin du journal pour voir tes actions possibles.
      await load(done.id, done.status === 'waiting')
    })
  }

  /** La simulation est jouée en tâche de fond : on sonde GET /simulations/{id} jusqu'à un autre statut que queued/running. */
  async function waitUntilPlayed(sim: SimulationView): Promise<SimulationView> {
    let current = sim
    waiting.value = true
    try {
      while (current.status === 'queued' || current.status === 'running') {
        progress.value = current.progress ?? null
        await new Promise((resolve) => setTimeout(resolve, POLL_MS))
        current = await api.get(sim.id)
      }
    } finally {
      waiting.value = false
      progress.value = null
    }
    if (current.status === 'failed') throw new Error(current.error ?? 'La simulation a échoué.')
    return current
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

  // --- arbre des branches ---

  /** Arbre des branches de la simulation courante (null avant le premier chargement). */
  const branchTree = ref<BranchTree | null>(null)

  /** Relit l'arbre des branches auquel appartient la simulation courante. */
  async function refreshBranches(): Promise<void> {
    if (!simulation.value) return
    await run(async () => {
      branchTree.value = await api.branches(simulation.value!.id)
    })
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

  /**
   * « Rendre la main à l'IA » : crée une branche à partir du point de bifurcation, où le siège passe
   * à l'agent `profile` (`ai:heuristic` par défaut). Le journal de la branche garde l'historique avant.
   */
  async function handBackToAi(seat: number, profile = seatProfiles.value[seat] ?? 'ai:heuristic'): Promise<void> {
    if (!simulation.value || forkPoint.value === null) return
    pause()
    await run(async () => {
      const child = await api.fork(simulation.value!.id, forkPoint.value!, { [seat]: profile })
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

  // --- choix de cible au clic (plateau) ---

  /** Cibles déjà choisies au plateau pour l'action en cours (vide hors choix). */
  const pickedTargets = ref<string[]>([])

  /** Actions légales à cibles dont les cibles contiennent toutes `picked` (ordre indifférent). */
  function candidatesFor(picked: string[]): ActionView[] {
    return (pending.value?.actions ?? []).filter((a) => (a.targets ?? []).length > 0 && picked.every((t) => a.targets!.includes(t)))
  }

  /** Cibles qu'un clic peut encore choisir (surbrillance sur le plateau). */
  const targetHighlights = computed(() => {
    const picked = pickedTargets.value
    const next = new Set<string>()
    for (const a of candidatesFor(picked)) for (const t of a.targets ?? []) if (!picked.includes(t)) next.add(t)
    return next
  })

  /** Actions encore possibles après les cibles choisies : listées quand les clics ne suffisent pas à trancher. */
  const pickCandidates = computed(() => candidatesFor(pickedTargets.value))

  /**
   * Clic sur une cible (joueur, permanent, sort de la pile). Une cible impossible est ignorée. Dès qu'une seule action
   * légale est complète et qu'aucune autre ne la prolonge, elle est envoyée ; sinon les cibles s'accumulent (sorts à
   * plusieurs cibles) et `pickCandidates` liste les actions restantes.
   */
  async function pickTarget(target: string): Promise<void> {
    if (!pending.value || pickedTargets.value.includes(target)) return
    const next = [...pickedTargets.value, target]
    const cands = candidatesFor(next)
    if (!cands.length) return
    const exact = cands.filter((a) => (a.targets ?? []).length === next.length)
    if (exact.length === 1 && exact.length === cands.length) {
      pickedTargets.value = []
      await decide(exact[0].id)
      return
    }
    pickedTargets.value = next
  }

  function cancelTarget(): void {
    pickedTargets.value = []
  }

  /** Carte choisie au clic (menu de carte) : ses actions légales sont listées sous la main. */
  const selectedCardId = ref<string | null>(null)
  const cardActions = computed(() => (selectedCardId.value ? (pending.value?.actions ?? []).filter((a) => a.cardId === selectedCardId.value) : []))

  function selectCard(id: string): void {
    selectedCardId.value = selectedCardId.value === id ? null : id
  }

  // Un choix de cible ou de carte ne survit pas à la décision suivante.
  watch(
    () => pending.value?.eventSeq,
    () => {
      cancelTarget()
      selectedCardId.value = null
    },
  )

  /** Stops d'un joueur tels que renvoyés par le serveur (null = défaut). */
  /** URL d'image d'une carte (`small` pour le plateau, `normal` pour l'aperçu) ; null si inconnue (deck de démo, jeton). */
  function cardImage(name: string, size: 'small' | 'normal' = 'small'): string | null {
    return simulation.value?.cardImages?.[name]?.[size] ?? null
  }

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

  /** Cartes que le siège humain peut utiliser maintenant, d'après les actions légales du serveur (aucune règle côté front). */
  const playableCardIds = computed(() => {
    const p = pending.value
    if (!p || !humanSeats.value.includes(p.player)) return new Set<string>()
    return new Set(p.actions.filter((a) => a.cardId && a.player === p.player).map((a) => a.cardId!))
  })

  /** Carte survolée, montrée en grand par `CardPreview`. */
  const hoveredCard = ref<PublicCard | null>(null)

  onScopeDispose(pause)

  return {
    simulation,
    events,
    cursor,
    playing,
    speedMs,
    showVerbose,
    loading,
    error,
    deckProblems,
    progress,
    waiting,
    lastSeq,
    atEnd,
    view,
    visibleEvents,
    checkpointSeqs,
    forkPoint,
    pending,
    humanSeats,
    cardImage,
    stopsOf,
    setStops,
    playableCardIds,
    hoveredCard,
    load,
    create,
    step,
    stepBack,
    seek,
    play,
    pause,
    setSpeed,
    seatProfiles,
    pickedTargets,
    pickCandidates,
    targetHighlights,
    pickTarget,
    cancelTarget,
    selectedCardId,
    cardActions,
    selectCard,
    branchTree,
    refreshBranches,
    forkHere,
    handBackToAi,
    decide,
  }
})
