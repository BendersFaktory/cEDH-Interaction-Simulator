import type { BranchTree, DeckInput, GameEvent, SimulationView, StopsConfig } from '../types/engine'

/** Erreur d'API ; `problems` = problèmes d'une decklist refusée (422), par siège. */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly problems?: Record<string, string[]>,
  ) {
    super(message)
  }
}

async function http<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`/api${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  })
  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    throw new ApiError(body.error ?? `${response.status} ${response.statusText}`, body.problems)
  }
  return response.json() as Promise<T>
}

export const api = {
  create: (body: { seed?: number; controllers?: Record<number, string>; decks?: Record<number, DeckInput> } = {}) =>
    http<SimulationView>('/simulations', { method: 'POST', body: JSON.stringify(body) }),

  get: (id: number) => http<SimulationView>(`/simulations/${id}`),

  events: (id: number, after = 0) => http<GameEvent[]>(`/simulations/${id}/events?after=${after}`),

  /** Bifurque au checkpoint le plus proche (<= atEvent) ; `controllers` remplace des agents (ex: {1: 'human:auto'}). */
  fork: (id: number, atEvent: number, controllers: Record<number, string>) =>
    http<SimulationView>(`/simulations/${id}/fork`, {
      method: 'POST',
      body: JSON.stringify({ atEvent, controllers }),
    }),

  decide: (id: number, actionId: string) =>
    http<SimulationView>(`/simulations/${id}/decisions`, {
      method: 'POST',
      body: JSON.stringify({ actionId }),
    }),

  /** Arbre des branches auquel appartient la simulation. */
  branches: (id: number) => http<BranchTree>(`/simulations/${id}/branches`),

  /** Configure les stops d'un siège humain (`null` = comportement par défaut). */
  setStops: (id: number, player: number, stops: StopsConfig | null) =>
    http<SimulationView>(`/simulations/${id}/stops`, {
      method: 'PUT',
      body: JSON.stringify({ player, stops }),
    }),
}
