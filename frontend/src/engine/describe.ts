import type { GameEvent } from '../types/engine'

import { phaseLabel } from './phases'

/** Cible lisible : `player:N` → `JN`, `permanent:<id>` → `permanent <id>`, `stack:<id>` → `sort <id>`. */
export const target = (t: string): string => (t.startsWith('player:') ? `J${t.slice(7)}` : t.startsWith('permanent:') ? `permanent ${t.slice(10)}` : `sort ${t.slice(6)}`)

const manaStr = (m: Record<string, number> | undefined): string =>
  Object.entries(m ?? {})
    .map(([s, n]) => `{${s}}`.repeat(n))
    .join('')

/** Libellé d'une restriction de mana (`kind` du moteur). */
export const restrictionLabel = (kind: string): string => (kind === 'eldrazi' ? "seulement pour un sort ou une capacité d'Eldrazi incolore" : kind)

/** Phrase lisible pour le journal. Les événements verbeux restent concis. */
export function describeEvent(e: GameEvent): string {
  const p = e.payload
  switch (e.type) {
    case 'GameStarted':
      return p.firstPlayer === undefined ? `Début de partie (seed ${p.seed})` : `Début de partie (seed ${p.seed}) — J${p.firstPlayer} commence`
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
      return `J${p.player} joue ${p.card.name}${p.tapped ? ' (engagé)' : ''}`
    case 'LandFetched':
      return `J${p.player} cherche ${p.card.name}${p.tapped ? ' (engagé)' : ''}`
    case 'Scried':
      return `J${p.player} range ${p.top + p.bottom} cartes du dessus (${p.top} dessus, ${p.bottom} dessous)`
    case 'CardTutored':
      return p.to === 'top' ? `J${p.player} cherche ${p.card.name} et la met sur le dessus de sa bibliothèque` : `J${p.player} cherche ${p.card.name} dans sa bibliothèque`
    case 'LibraryShuffled':
      return `J${p.player} mélange sa bibliothèque (${p.library} cartes)`
    case 'LifePaid':
      return `J${p.player} paie ${p.amount} PV (${p.source}) → ${p.life}`
    case 'SpellCast': {
      const t = (p.targets as string[]).map(target).join(', ')
      const from = p.fromCommand ? ' depuis la zone de commandement' : ''
      const paid = p.manaCost ? ` (coût ${p.manaCost}${p.x !== undefined && p.manaCost.includes('{X}') ? `, X=${p.x}` : ''})` : p.fromCommand ? ` (coût ${p.cost})` : ''
      return `J${p.player} lance ${p.card.name}${from}${paid}${t ? ` → ${t}` : ''}`
    }
    case 'ManaAbilityActivated': {
      const tapped = (p.tapped as string[] | undefined)?.length ? 'engage' : 'active'
      const restriction = ((p.ability?.options ?? []) as string[])[p.option ?? 0]?.split('@')[1]
      return `J${p.player} ${tapped} ${p.card.name} : ${manaStr(p.mana)}${restriction ? ` (mana restreint : ${restrictionLabel(restriction)})` : ''}`
    }
    case 'AbilityActivated': {
      const t = ((p.targets ?? []) as string[]).map(target).join(', ')
      const a = p.ability ?? {}
      const detail = a.effect === 'damage' && a.amount !== undefined ? ` (${a.amount} dégât${a.amount > 1 ? 's' : ''})` : ''
      return `J${p.player} active ${p.card.name}${detail}${t ? ` → ${t}` : ''}`
    }
    case 'AbilityTriggered': {
      const t = ((p.targets ?? []) as string[]).map(target).join(', ')
      return `Trigger de ${p.card.name} (J${p.player}) : ${p.label}${t ? ` → ${t}` : ''}`
    }
    case 'AbilityResolved':
      return p.trigger ? `Trigger de ${p.card.name} résolu` : `Capacité de ${p.card.name} résolue`
    case 'ManaAdded':
      return `J${p.player} gagne ${manaStr(p.mana)}`
    case 'ManaPoolEmptied':
      return `J${p.player} perd son mana flottant ${manaStr(p.lost)}`
    case 'SpellResolved':
      return `${p.card.name} se résout`
    case 'SpellCountered': {
      const where: Record<string, string> = { command: ' et retourne en zone de commandement', exile: ' et exilé', library: ' et remis sur le dessus de la bibliothèque' }
      return `${p.card.name} (J${p.controller}) est contré${where[p.destination] ?? ''}`
    }
    case 'CostPaid':
      return `J${p.player} paie ${p.cost} (${p.source})`
    case 'CostNotPaid':
      return `J${p.player} ne paie pas ${p.cost} (${p.source})`
    case 'DelayedTriggerCreated':
      return `Trigger retardé de ${p.card.name} (J${p.player}) : ${p.label}`
    case 'PermanentReturnedToHand':
      return `J${p.player} renvoie ${p.card.name} en main (${p.source})`
    case 'SpellFizzled':
      return "Le sort n'a plus de cible valide"
    case 'DamageDealt':
      return `${p.source} inflige ${p.amount} à ${target(p.target)} (${p.life} PV)${p.commanderDamage ? ` — dégâts de commandant : ${p.commanderDamage.total}/21` : ''}`
    case 'LifeGained':
      return `J${p.player} gagne ${p.amount} PV (${p.source}) → ${p.life}`
    case 'LifeLost':
      return `J${p.player} perd ${p.amount} PV (${p.source}) → ${p.life}`
    case 'CardDiscarded':
      return `J${p.player} défausse ${p.card.name} (main : ${p.hand})`
    case 'MulliganTaken':
      return `J${p.player} prend un mulligan (${p.mulligans})`
    case 'HandKept':
      return p.toBottom ? `J${p.player} garde sa main (${p.toBottom} carte${p.toBottom > 1 ? 's' : ''} au fond)` : `J${p.player} garde sa main`
    case 'CardPutOnBottom':
      return `J${p.player} met une carte au fond de sa bibliothèque`
    case 'CardPutOnTop':
      return `J${p.player} remet une carte sur le dessus de sa bibliothèque (${p.source})`
    case 'AttackerDeclared':
      return `J${p.player} attaque J${p.defender} avec ${p.card}`
    case 'BlockerDeclared':
      return `J${p.player} bloque ${p.attacker} avec ${p.blocker}`
    case 'CreatureDamaged':
      return `${p.card} subit ${p.amount} dégâts (${p.damage} marqués)`
    case 'CreatureDied':
      return `${p.card.name} (J${p.player}) meurt${p.destination === 'command' ? ' et retourne en zone de commandement' : ''}`
    case 'CardExiled':
      return `${p.card.name} (J${p.player}) est exilé par ${p.source}${p.destination === 'none' ? " (le jeton cesse d'exister)" : ''}`
    case 'CardImprinted':
      return `J${p.player} exile ${p.exiled} (imprint)${p.colors.length ? `, couleurs ${p.colors.join('')}` : ', sans couleur'}`
    case 'CommanderReturned':
      return `${p.card.name} (J${p.player}) retourne en zone de commandement`
    case 'CreatureBoosted':
      return `${p.card} (J${p.player}) passe à ${p.power}/${p.toughness}`
    case 'TokenCreated':
      return `J${p.player} crée un jeton ${p.copyOf ? 'copie de ' : ''}${p.card.name}`
    case 'LegendRuleApplied':
      return `Règle des légendes : J${p.player} garde ${p.kept}, ${p.removed.map((r: { card: { id: string } }) => r.card.id).join(', ')} part au cimetière`
    case 'PermanentSacrificed':
      return `J${p.player} sacrifie ${p.card.name}${p.destination === 'command' ? ' (retour en zone de commandement)' : ''}`
    case 'CombatEnded':
      return 'Fin du combat'
    case 'PlayerLost':
      return `J${p.player} est éliminé (${p.reason === 'pact_unpaid' ? 'pacte non payé' : p.reason})`
    case 'PlayerLeftGame':
      return `Les objets de J${p.player} quittent la partie`
    case 'GameOver':
      return p.winner === null ? `Fin de partie (${p.reason})` : `Victoire de J${p.winner}`
    default:
      return e.type
  }
}
