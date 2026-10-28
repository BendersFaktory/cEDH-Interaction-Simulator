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
      // `spell_controller` : An Offer You Can't Refuse ; `tapped` : Tataru Taru.
      return `${spec === 'spell_controller' ? 'le contrôleur du sort visé ' : ''}crée ${amount || 1} Trésor${spec === 'tapped' ? ' engagé' : ''}`
    case 'tutor':
      return 'cherche une carte dans sa bibliothèque et la met en main'
    case 'scry':
      return `regarde les ${n} cartes du dessus et en range une partie dessous`
    case 'put_back':
      return `remet ${n} cartes de sa main sur le dessus de sa bibliothèque, dans l'ordre de son choix`
    case 'arrange':
      return `regarde les ${n} cartes du dessus et les remet dans l'ordre de son choix (ou mélange)`
    case 'draw_unless':
      return `pioche ${n} sauf s'il paie ${spec ?? ''}`
    case 'pump':
      return spec ?? 'bonus'
    case 'untap':
      return 'se dégage'
    case 'self_damage':
      return `inflige ${n} dégâts à son contrôleur`
    case 'imprint':
      return 'exile une carte non-artefact et non-terrain de sa main (ses couleurs sont celles du mana produit)'
    case 'pact':
      return `paie ${spec ?? ''} ou perd la partie`
    case 'mana':
      return `ajoute ${'{C}'.repeat(amount ?? 0) || 'rien'}`
    case 'lose_life_treasure':
      return `perd ${n} PV et crée un Trésor`
    case 'draw_opponent_may':
      return `pioche ${n} ; l'adversaire ciblé peut piocher une carte`
    case 'treasure_exile_top':
      return 'crée un Trésor et exile la carte du dessus de la bibliothèque de ce joueur ; tu peux la lancer ce tour'
    case 'two_players_draw':
      return `deux joueurs ciblés piochent ${n}`
    case 'tutor_give_control':
      return 'cherche une carte et la met en main ; un adversaire prend le contrôle de cette carte (à ton tour seulement)'
    default:
      return `${effect}${amount ? ` ${amount}` : ''}`
  }
}

export function abilityText(a: AbilityView): string {
  const cost = [a.manaCost, a.tap ? '{T}' : '', a.sacrifice ? 'sacrifier' : '', a.sacrificeCreature ? 'sacrifier une créature' : '', a.discard ? `défausser ${a.discard}` : '']
    .filter(Boolean)
    .join(', ')
  const what =
    a.isManaAbility && a.spec === 'oppland'
      ? "un mana d'une couleur que produit un terrain adverse"
      : a.isManaAbility && a.spec === 'luck'
        ? "{C}, ou un mana de n'importe quelle couleur avec un marqueur de chance"
        : a.isManaAbility && a.spec === 'imprint'
          ? "un mana d'une couleur de la carte exilée"
          : a.isManaAbility
            ? (a.options ?? []).map((o) => o.replace(/@(\w+)$/, ' ($1 seulement)')).join(' | ')
            : effectText(a.effect, a.amount, a.spec)
  return `${cost} : ${what}${a.sorcerySpeed ? ' (rituel)' : ''}${a.upkeepOnly ? ' (à ton entretien)' : ''}`
}

const triggerEvents: Record<string, string> = {
  etb: 'Quand elle arrive en jeu',
  dies: 'Quand elle meurt',
  upkeep: 'Au début de ton entretien',
  attacks: 'Quand elle attaque',
  end_step: 'Au début de ta fin de tour',
  cast: 'Quand tu lances un sort',
  opponent_cast: 'Quand un adversaire lance un sort',
  discard: 'Quand tu défausses une carte',
  draw_step: 'Au début de ton étape de pioche',
  next_upkeep: 'Au début de ton prochain entretien',
  next_main: 'Au début de ta prochaine phase principale',
  any_cast: 'Quand un joueur lance un sort',
  opponent_draw: 'Quand un adversaire pioche une carte',
  combat_damage_player: 'Quand elle inflige des dégâts de combat à un joueur',
}

const triggerFilters: Record<string, string> = {
  tapped: ', si elle est engagée',
  second_spell: ' (son deuxième du tour)',
  second_draw: ' (sa deuxième du tour)',
  off_turn_once: ' hors de son tour (une fois par tour)',
}

export function triggerText(t: TriggerView): string {
  const when = t.event === 'cast' && t.filter === 'instant_sorcery' ? 'Quand tu lances un éphémère ou un rituel' : (triggerEvents[t.event] ?? t.event)
  return `${when}${triggerFilters[t.filter ?? ''] ?? ''} : ${effectText(t.effect, t.amount, t.spec)}`
}

/** Jeton créé pour le contrôleur du sort contré (Swan Song : « son contrôleur crée un jeton Bird 2/2 volant »). */
function counterTokenText(e: { effect: string; token?: string; tokenKeywords?: string[] }): string {
  if (e.effect !== 'counter' || !e.token) return ''
  const [name, stats] = e.token.split(':')
  const flying = e.tokenKeywords?.includes('flying') ? ' volant' : ''
  return ` ; son contrôleur crée un jeton ${name} ${stats}${flying}`
}

/** Précisions d'un contresort (Daze, Mental Misstep, Force of Negation, Memory Lapse, Mana Drain). */
function counterOptionsText(e: { effect: string; payCost?: string; manaValue?: number; counterTo?: string; drainMana?: boolean }): string {
  if (e.effect !== 'counter') return ''
  return [
    e.manaValue !== undefined ? ` de valeur de mana ${e.manaValue}` : '',
    e.payCost ? ` sauf si son contrôleur paie ${e.payCost}` : '',
    e.counterTo === 'exile' ? ' (exilé)' : e.counterTo === 'library_top' ? ' (remis sur le dessus de la bibliothèque)' : '',
    e.drainMana ? ' ; à ta prochaine phase principale, ajoute autant de {C} que sa valeur de mana' : '',
  ].join('')
}

/** Effet d'un sort (dégâts, contre, pioche...), tel que le moteur le décrit. */
/** Flashback / Dash : « Flashback {2}{R} (depuis le cimetière, puis exilée) ». */
export function altCastText(card: PublicCard): string[] {
  const casts = card.altCasts ?? {}
  return [...(casts.flashback ? [`Flashback ${casts.flashback} (depuis le cimetière, puis exilée)`] : []), ...(casts.dash ? [`Dash ${casts.dash} (célérité, revient en main à la fin du tour)`] : [])]
}

export function spellText(card: PublicCard): string | null {
  const effects = card.effects ?? (card.effect ? [{ effect: card.effect, amount: card.amount, adds: card.adds }] : [])
  if (!effects.length) return null
  return effects
    .map((e) =>
      e.effect === 'mana'
        ? `ajoute ${Array.isArray(e.adds) ? e.adds.join('') : (e.adds ?? '')}`
        : e.effect === 'pact'
          ? `à ton prochain entretien, paie ${'payCost' in e ? e.payCost : ''} ou perds la partie`
          : effectText(e.effect, e.amount, 'target' in e ? e.target : undefined) +
            ('powerLife' in e && e.powerLife ? ' (son contrôleur gagne autant de PV que sa force)' : '') +
            counterOptionsText(e) +
            counterTokenText(e),
    )
    .join(', puis ')
}
