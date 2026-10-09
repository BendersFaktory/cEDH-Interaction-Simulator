import type { GameEvent } from '../types/engine'

import { phaseLabel } from './phases'

const target = (t: string): string =>
  t.startsWith('player:') ? `J${t.slice(7)}` : `sort ${t.slice(6)}`

/** Phrase lisible pour le journal. Les événements verbeux restent concis. */
export function describeEvent(e: GameEvent): string {
  const p = e.payload
  switch (e.type) {
    case 'GameStarted':
      return `Début de partie (seed ${p.seed})`
    case 'CardDrawn':
      return `J${p.player} pioche ${p.card.name}`
    case 'PhaseChanged':
      return `Tour ${p.turn} — J${p.activePlayer} — ${phaseLabel(p.phase)}`
    case 'PermanentsUntapped':
      return `J${p.player} dégage ses permanents`
    case 'PriorityGranted':
      return `J${p.holder} a la priorité (pile : ${p.stackSize})`
    case 'PriorityPassed':
      return `J${p.player} passe`
    case 'LandPlayed':
      return `J${p.player} joue ${p.card.name}`
    case 'SpellCast': {
      const t = (p.targets as string[]).map(target).join(', ')
      const from = p.fromCommand ? ` depuis la zone de commandement (coût ${p.cost})` : ''
      return `J${p.player} lance ${p.card.name}${from}${t ? ` → ${t}` : ''}`
    }
    case 'SpellResolved':
      return `${p.card.name} se résout`
    case 'SpellCountered':
      return `${p.card.name} (J${p.controller}) est contré${p.destination === 'command' ? ' et retourne en zone de commandement' : ''}`
    case 'SpellFizzled':
      return 'Le sort n\'a plus de cible valide'
    case 'DamageDealt':
      return `${p.source} inflige ${p.amount} à ${target(p.target)} (${p.life} PV)`
    case 'PlayerLost':
      return `J${p.player} est éliminé (${p.reason})`
    case 'GameOver':
      return p.winner === null ? `Fin de partie (${p.reason})` : `Victoire de J${p.winner}`
    default:
      return e.type
  }
}
