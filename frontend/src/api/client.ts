import type { GameEvent, SimulationView } from '../types/engine'

async function http<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`/api${path}`, {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  })
  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    throw new Error(body.error ?? `${response.status} ${response.statusText}`)
  }
  return response.json() as Promise<T>
}

export const api = {
  create: (body: { seed?: number; controllers?: Record<number, string> } = {}) =>
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
}
