// Libellés d'affichage d'une carte (aucune règle de jeu : on met en forme ce que le moteur envoie).
import { hasType, typesOf, type AbilityView, type CardType, type PublicCard, type TriggerView } from '../types/engine'

export const keywordLabels: Record<string, string> = {
  flying: 'vol',
  reach: 'portée',
  haste: 'célérité',
  vigilance: 'vigilance',
  defender: 'défenseur',
  menace: 'menace',
  first_strike: 'initiative',
  double_strike: 'double initiative',
  trample: 'piétinement',
  deathtouch: 'contact mortel',
  lifelink: 'lien de vie',
  flash: 'flash',
}

export const keywordsText = (card: PublicCard): string => (card.keywords ?? []).map((k) => keywordLabels[k] ?? k).join(', ')

/** Symboles d'un coût '{X}{2}{R/G}' => ['X', '2', 'R/G']. */
export const manaSymbols = (cost: string | null | undefined): string[] => (cost ? [...cost.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]) : [])
export const symbolClass = (s: string): string => (s.includes('/') ? 'hybrid' : /^[WUBRGC]$/.test(s) ? `m-${s}` : 'm-n')

/** Couleurs de la carte (W U B R G, dans cet ordre) : coût de mana, ou mana produit pour un terrain. */
export function colorsOf(card: PublicCard): string[] {
  const letters = hasType(card, 'land') ? (card.produces ?? []).join('') : manaSymbols(card.manaCost).join('')
  return ['W', 'U', 'B', 'R', 'G'].filter((c) => letters.includes(c))
}

/** Couleur du cadre : une couleur, `multi` (or) ou `C` (incolore). */
export function frameOf(card: PublicCard): string {
  const colors = colorsOf(card)
  return colors.length === 1 ? colors[0] : colors.length > 1 ? 'multi' : 'C'
}

const typeLabels: Record<CardType, string> = {
  land: 'Terrain',
  instant: 'Éphémère',
  sorcery: 'Rituel',
  creature: 'Créature',
  artifact: 'Artefact',
  enchantment: 'Enchantement',
}

/** Ligne de type : 'Artefact Créature — Eldrazi'. */
export function typeLine(card: PublicCard): string {
  const types = typesOf(card)
  const subtypes = types.flatMap((t) => card.subtypes?.[t] ?? [])
  return `${card.commander ? 'Commandant · ' : ''}${types.map((t) => typeLabels[t] ?? t).join(' ')}${subtypes.length ? ` — ${subtypes.join(' ')}` : ''}`
}

/** Effet commun aux sorts, capacités et triggers. */
function effectText(effect: string, amount: number | undefined, spec?: string): string {
  const n = amount ? String(amount) : 'X'
  switch (effect) {
    case 'damage':
      return `inflige ${n} dégâts`
    case 'counter':
      return spec === 'instant' ? 'contrecarre le sort instantané ciblé' : 'contrecarre le sort ciblé'
    case 'exile':
      return spec === 'creature' ? 'exile la créature ciblée' : 'exile le permanent ciblé'
    case 'draw':
      return `pioche ${n}`
    case 'token':
      return `crée ${amount ?? 1} jeton ${(spec ?? '').replace(':', ' ')}`
    case 'treasure':
      return `crée ${amount ?? 1} Trésor`
    case 'pump':
      return spec ?? 'bonus'
    default:
      return `${effect}${amount ? ` ${amount}` : ''}`
  }
}

export function abilityText(a: AbilityView): string {
  const cost = [a.manaCost, a.tap ? '{T}' : '', a.sacrifice ? 'sacrifier' : '', a.sacrificeCreature ? 'sacrifier une créature' : '', a.discard ? `défausser ${a.discard}` : '']
    .filter(Boolean)
    .join(', ')
  const what = a.isManaAbility ? (a.options ?? []).map((o) => o.replace(/@(\w+)$/, ' ($1 seulement)')).join(' | ') : effectText(a.effect, a.amount, a.spec)
  return `${cost} : ${what}${a.sorcerySpeed ? ' (rituel)' : ''}`
}

const triggerEvents: Record<string, string> = {
  etb: 'Quand elle arrive en jeu',
  dies: 'Quand elle meurt',
  upkeep: 'Au début de ton entretien',
  attacks: 'Quand elle attaque',
  end_step: 'Au début de ta fin de tour',
  cast: 'Quand tu lances un sort',
  discard: 'Quand tu défausses une carte',
}

export function triggerText(t: TriggerView): string {
  const when = t.event === 'cast' && t.filter === 'instant_sorcery' ? 'Quand tu lances un éphémère ou un rituel' : (triggerEvents[t.event] ?? t.event)
  return `${when} : ${effectText(t.effect, t.amount, t.spec)}`
}

/** Effet d'un sort (dégâts, contre, pioche...), tel que le moteur le décrit. */
export function spellText(card: PublicCard): string | null {
  const effects = card.effects ?? (card.effect ? [{ effect: card.effect, amount: card.amount, adds: card.adds }] : [])
  if (!effects.length) return null
  return effects
    .map((e) =>
      e.effect === 'mana'
        ? `ajoute ${Array.isArray(e.adds) ? e.adds.join('') : (e.adds ?? '')}`
        : effectText(e.effect, e.amount, 'target' in e ? e.target : undefined) + ('powerLife' in e && e.powerLife ? ' (son contrôleur gagne autant de PV que sa force)' : ''),
    )
    .join(', puis ')
}
