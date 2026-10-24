// Petit client de l'API pour la recette : crée des parties et les pilote sans passer par l'UI (rapide, parallélisable).
// Après POST, on attend que le statut quitte queued/running (marche aussi si la création est synchrone).
export const API = 'http://127.0.0.1:8000/api/simulations'
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

const json = async (url, init) => {
  const r = await fetch(url, init)
  const body = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(`${init?.method ?? 'GET'} ${url} -> HTTP ${r.status} ${JSON.stringify(body).slice(0, 200)}`)
  return body
}
const post = (url, data) => json(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })

/** Attend la fin du calcul (status queued/running -> waiting/over/failed). */
export const waitReady = async (sim, timeout = 120_000) => {
  const t0 = Date.now()
  while (['queued', 'running'].includes(sim.status)) {
    if (Date.now() - t0 > timeout) throw new Error(`simulation #${sim.id} toujours ${sim.status} après ${timeout} ms`)
    await sleep(200)
    sim = await json(`${API}/${sim.id}`)
  }
  if (sim.status === 'failed') throw new Error(`simulation #${sim.id} en échec`)
  return sim
}

export const create = async (seed, controllers, { keep = true } = {}) => {
  const sim = await waitReady(await post(API, { seed, controllers }))
  return keep ? keepHands(sim) : sim
}
/** Même configuration que le bouton « Nouvelle partie Commander » (J0 humain contre 3 IA). */
export const createHuman = (seed) => create(seed, { 0: 'human:auto', 1: 'ai:heuristic', 2: 'ai:heuristic', 3: 'ai:heuristic' }, { keep: true })
/** J0 humain avec une decklist (texte) : demande l'API démarrée avec SCRYFALL_FIXTURES=1 (Scryfall figé, voir recette.mjs). */
export const createHumanWithDeck = async (seed, decklist) =>
  keepHands(await waitReady(await post(API, { seed, controllers: { 0: 'human:auto', 1: 'ai:heuristic', 2: 'ai:heuristic', 3: 'ai:heuristic' }, decks: { 0: decklist } })))
export const get =(id) => json(`${API}/${id}`)
export const pending = (id) => json(`${API}/${id}/pending`)
export const events = (id) => json(`${API}/${id}/events`)
export const state = async (id, seq) => (await json(`${API}/${id}/state${seq === undefined ? '' : `?at=${seq}`}`)).state
export const decide = async (id, actionId) => waitReady(await post(`${API}/${id}/decisions`, { actionId }))

/** Mulligan de Londres : tant qu'un siège humain a une décision « Main de départ » / « Mettre au fond », garde la main (keep, puis 1re carte au fond). */
export const keepHands = async (sim) => {
  for (let i = 0; i < 40; i++) {
    const actions = sim.status === 'waiting' ? (sim.pending?.actions ?? []) : []
    const a = actions.find((x) => x.type === 'keep') ?? actions.find((x) => x.type === 'bottom')
    if (!a) return sim
    sim = await decide(sim.id, a.id)
  }
  throw new Error(`simulation #${sim.id} : mulligan sans fin`)
}

/** Noms des cartes en main d'un joueur et nombre de terrains (basiques ou non, d'après le type de la carte). */
export const handOf = async (id, player = 0) => {
  const hand = (await state(id)).players[player].hand
  const names = hand.map((c) => c.name)
  const lands = hand.filter((c) => [c.type].flat().includes('land')).length
  return { names, lands }
}

/**
 * Pilote une partie : à chaque décision en attente, `pick(pending, step)` renvoie une action, 'stop' (situation atteinte)
 * ou null (rien à faire : abandon). Renvoie { stopped, steps, sim }.
 */
export const drive = async (id, pick, maxSteps = 400) => {
  let sim = await get(id)
  for (let step = 0; step < maxSteps; step++) {
    if (sim.status !== 'waiting' || !sim.pending) return { stopped: false, steps: step, sim }
    const a = pick(sim.pending, step)
    if (a === 'stop') return { stopped: true, steps: step, sim }
    if (!a) return { stopped: false, steps: step, sim }
    sim = await decide(id, a.id)
  }
  return { stopped: false, steps: maxSteps, sim }
}
