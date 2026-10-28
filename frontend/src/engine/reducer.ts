import type { BoardCard, GameEvent, GameView, PlayerView, PublicCard } from '../types/engine'

/**
 * Le front est « bête » : il ne connaît AUCUNE règle, il applique les événements du moteur
 * un par un sur un état local. Les payloads portent des valeurs absolues (PV résultants...).
 * Le test de contrat reducer.test.ts compare ce reducer à l'état final calculé par PHP.
 */

export function initialView(): GameView {
  return {
    turn: 1,
    phase: 'draw',
    activePlayer: null,
    priority: { holder: null, passed: [] },
    players: {},
    stack: [],
    over: false,
    winner: null,
    lastSeq: 0,
  }
}

const board = (card: PublicCard): BoardCard => ({ ...card, tapped: false })

/** Zone d'arrivée ; `null` pour 'none' (un jeton qui quitte le champ de bataille cesse d'exister). */
function zoneOf(pl: PlayerView, destination: string): BoardCard[] | null {
  switch (destination) {
    case 'battlefield':
      return pl.battlefield
    case 'command':
      return pl.command
    case 'exile':
      return pl.exile
    case 'none':
    case 'replaced': // effet de remplacement en attente : la carte arrive avec `ReplacementApplied`
      return null
    default:
      return pl.graveyard
  }
}

function removeCard(cards: BoardCard[], id: string): void {
  const i = cards.findIndex((c) => c.id === id)
  if (i >= 0) cards.splice(i, 1)
}

/** Partie restreinte de la réserve (valeur absolue ; absente du payload = vide). */
function restrictedOf(r: Record<string, Record<string, number>> | undefined): Record<string, Record<string, number>> {
  return Object.fromEntries(Object.entries(r ?? {}).map(([k, m]) => [k, { ...m }]))
}

function tapAll(pl: PlayerView, ids: string[] | undefined): void {
  for (const id of ids ?? []) {
    const c = pl.battlefield.find((x) => x.id === id)
    if (c) c.tapped = true
  }
}

/** Applique UN événement en mutant `v` (rapide pour rejouer des milliers d'événements). */
export function applyEvent(v: GameView, e: GameEvent): GameView {
  const p = e.payload
  v.lastSeq = e.seq

  switch (e.type) {
    case 'GameStarted':
      v.activePlayer = (p.firstPlayer as number | undefined) ?? v.activePlayer
      for (const pl of p.players as Array<{
        id: number
        life: number
        library: number
        controller: string
        command?: PublicCard[]
        pool?: Record<string, number>
        restricted?: Record<string, Record<string, number>>
      }>) {
        v.players[pl.id] = {
          id: pl.id,
          life: pl.life,
          library: pl.library,
          hand: [],
          battlefield: [],
          graveyard: [],
          exile: [],
          command: (pl.command ?? []).map(board),
          lost: false,
          controller: pl.controller,
          commanderDamage: {},
          pool: { ...(pl.pool ?? {}) },
          restricted: restrictedOf(pl.restricted),
        } satisfies PlayerView
      }
      break

    case 'CardDrawn': {
      const pl = v.players[p.player]
      pl.hand.push(board(p.card))
      pl.library = p.library
      break
    }

    case 'PhaseChanged':
      v.turn = p.turn
      v.phase = p.phase
      v.activePlayer = p.activePlayer
      v.priority = { holder: null, passed: [] }
      break

    case 'PermanentsUntapped':
      for (const id of p.cards as string[]) {
        const c = v.players[p.player].battlefield.find((x) => x.id === id)
        if (c) c.tapped = false
      }
      break

    case 'PriorityGranted':
      v.priority = { holder: p.holder, passed: [...p.passed] }
      break

    case 'PriorityPassed':
      if (!v.priority.passed.includes(p.player)) v.priority.passed.push(p.player)
      break

    case 'LandPlayed': {
      const pl = v.players[p.player]
      // Opposition Agent : terrain joué depuis l'exil de son propriétaire.
      removeCard(p.fromExile ? v.players[p.owner].exile : pl.hand, p.card.id)
      pl.battlefield.push({ ...board(p.card), tapped: p.tapped === true }) // shockland non payée : arrive engagée
      break
    }

    case 'LandFetched': {
      const pl = v.players[p.player]
      pl.library = p.library
      pl.battlefield.push({ ...board(p.card), tapped: p.tapped === true })
      break
    }

    case 'Scried':
      // Le rangement ne change que la taille de la bibliothèque côté public (le dessus n'est visible que de son contrôleur).
      v.players[p.player].library = p.library
      break

    case 'CardTutored': {
      const pl = v.players[p.player]
      // Tuteur vers la main, ou sur le dessus de la bibliothèque (`to` = 'top', Vampiric Tutor) : seule la taille compte côté bibliothèque.
      if (p.to !== 'top') pl.hand.push(board(p.card))
      pl.library = p.library
      break
    }

    case 'LibraryShuffled':
      v.players[p.player].library = p.library
      break

    case 'LifePaid':
      v.players[p.player].life = p.life
      break

    case 'SpellCast': {
      const pl = v.players[p.player]
      // Un commandant est lancé depuis la zone de commandement, une carte exilée (Ragavan) depuis l'exil de son propriétaire, le reste depuis la main.
      // Flashback (Strike It Rich) : depuis le cimetière.
      removeCard(p.fromCommand ? pl.command : p.fromExile ? v.players[p.owner].exile : p.altCast === 'flashback' || p.altCast === 'escape' ? pl.graveyard : pl.hand, p.card.id)
      for (const id of p.tapped as string[]) {
        const land = pl.battlefield.find((c) => c.id === id)
        if (land) land.tapped = true
      }
      v.stack.push({ id: p.stackId, card: p.card, controller: p.player, targets: p.targets })
      if (p.pool) {
        pl.pool = { ...p.pool }
        pl.restricted = restrictedOf(p.restricted)
      }
      v.priority.passed = []
      break
    }

    case 'ManaAbilityActivated': {
      const pl = v.players[p.player]
      tapAll(pl, p.tapped)
      pl.pool = { ...p.pool }
      pl.restricted = restrictedOf(p.restricted)
      break
    }

    case 'AbilityActivated': {
      tapAll(v.players[p.player], p.tapped)
      v.stack.push({ id: p.stackId, card: p.card, controller: p.player, targets: p.targets ?? [], ability: p.ability })
      v.priority.passed = []
      break
    }

    case 'AbilityTriggered':
      v.stack.push({ id: p.stackId, card: p.card, controller: p.player, targets: p.targets ?? [], trigger: p.trigger, label: p.label })
      v.priority.passed = []
      break

    case 'AbilityResolved':
      v.stack = v.stack.filter((i) => i.id !== p.stackId)
      break

    case 'ManaAdded':
      v.players[p.player].pool = { ...p.pool }
      v.players[p.player].restricted = restrictedOf(p.restricted)
      break

    case 'ManaPoolEmptied':
      v.players[p.player].pool = {}
      v.players[p.player].restricted = {}
      break

    case 'SpellResolved': {
      v.stack = v.stack.filter((i) => i.id !== p.stackId)
      // Une copie cesse d'exister (`none`) ; un sort volé (Commandeer) va chez son propriétaire (`owner`).
      zoneOf(v.players[p.owner ?? p.controller], p.destination)?.push(board(p.card))
      break
    }

    case 'ReplacementApplied': {
      // Mox Diamond : le permanent sorti de la pile arrive chez son contrôleur, ou va au cimetière de son propriétaire. La
      // défausse du terrain a son propre `CardDiscarded`.
      const to = p.destination === 'battlefield' ? p.player : (p.owner ?? p.player)
      zoneOf(v.players[to], p.destination)?.push(board(p.card))
      break
    }

    case 'SpellCopied':
      // Storm, Chain of Vapor : la copie rejoint la pile (elle n'est pas lancée).
      v.stack.push({ id: p.stackId, card: p.card, controller: p.player, targets: p.targets })
      v.priority.passed = []
      break

    case 'TargetChanged': {
      const item = v.stack.find((i) => i.id === p.stackId)
      if (item) item.targets = [...p.targets]
      break
    }

    case 'SpellControlChanged': {
      const item = v.stack.find((i) => i.id === p.stackId)
      if (item) item.controller = p.controller
      break
    }

    case 'SpellExiled':
      v.stack = v.stack.filter((i) => i.id !== p.stackId)
      zoneOf(v.players[p.owner ?? p.controller], p.destination)?.push(board(p.card))
      break

    case 'CardsMilled': {
      const pl = v.players[p.player]
      pl.library = p.library
      for (const c of p.cards as PublicCard[]) pl.graveyard.push(board(c))
      break
    }

    case 'SpellCountered': {
      v.stack = v.stack.filter((i) => i.id !== p.stackId)
      // Cimetière, exil (Force of Negation), zone de commandement, ou dessus de la bibliothèque (Memory Lapse : seule la taille est publique).
      if (p.destination === 'library') v.players[p.controller].library = p.library
      else zoneOf(v.players[p.owner ?? p.controller], p.destination)?.push(board(p.card))
      break
    }

    case 'CostPaid': {
      // Paiement à la résolution (Daze, Mana Leak, Pact of Negation) : sources engagées, réserve absolue.
      const pl = v.players[p.player]
      tapAll(pl, p.tapped)
      pl.pool = { ...p.pool }
      pl.restricted = restrictedOf(p.restricted)
      break
    }

    case 'PermanentReturnedToHand': {
      // Coût alternatif de Daze / Gush : l'Île revient en main (dégagée) ; un jeton cesse d'exister.
      const pl = v.players[p.player]
      removeCard(pl.battlefield, p.card.id)
      if (p.destination === 'hand') v.players[p.owner ?? p.player].hand.push(board(p.card))
      break
    }

    case 'DamageDealt': {
      const id = Number(String(p.target).replace('player:', ''))
      v.players[id].life = p.life
      if (p.commanderDamage) v.players[id].commanderDamage[p.commanderDamage.key] = p.commanderDamage.total
      break
    }

    case 'LifeGained':
    case 'LifeLost': {
      v.players[p.player].life = p.life
      break
    }

    case 'CardDiscarded': {
      const pl = v.players[p.player]
      removeCard(pl.hand, p.card.id)
      zoneOf(pl, p.destination)?.push(board(p.card))
      break
    }

    case 'MulliganTaken': {
      // La main retourne dans la bibliothèque ; les 7 CardDrawn suivants la refont.
      const pl = v.players[p.player]
      pl.hand = []
      pl.library = p.library
      break
    }

    case 'CardPutOnBottom':
    case 'CardPutOnTop': {
      // Main → fond (mulligan) ou dessus (Brainstorm) de la bibliothèque : seule la taille compte côté bibliothèque.
      const pl = v.players[p.player]
      removeCard(pl.hand, p.card.id)
      pl.library = p.library
      break
    }

    // 'HandKept' : aucun effet sur l'état affiché (le nombre de cartes au fond est dans le journal).

    case 'AttackerDeclared': {
      const c = v.players[p.player].battlefield.find((x) => x.id === p.card)
      if (c) {
        c.tapped = p.tapped
        c.attacking = p.defender
      }
      break
    }

    case 'BlockerDeclared': {
      const c = v.players[p.player].battlefield.find((x) => x.id === p.blocker)
      if (c) c.blocking = p.attacker
      break
    }

    case 'PermanentControlChanged': {
      // Wishclaw Talisman : le permanent passe d'un champ de bataille à l'autre (même zone, même état).
      removeCard(v.players[p.from].battlefield, p.card.id)
      v.players[p.player].battlefield.push({ ...board(p.card), tapped: p.tapped === true })
      break
    }

    case 'PermanentDestroyed': // même effet sur l'état affiché : champ de bataille -> cimetière
    case 'CreatureDied': {
      // `owner` : un permanent contrôlé par un autre joueur va dans la zone de son propriétaire.
      removeCard(v.players[p.player].battlefield, p.card.id)
      zoneOf(v.players[p.owner ?? p.player], p.destination)?.push(board(p.card))
      break
    }

    case 'AbilitiesGranted': {
      // Rain of Filth : les terrains reçoivent la capacité (ajoutée après celles de la carte) jusqu'à la fin du tour.
      const pl = v.players[p.player]
      for (const id of p.cards as string[]) {
        const c = pl.battlefield.find((x) => x.id === id)
        if (c) c.abilities = [...(c.abilities ?? []), p.ability]
      }
      break
    }

    case 'AbilitiesExpired': {
      const c = v.players[p.player].battlefield.find((x) => x.id === p.card)
      if (c?.abilities) c.abilities = c.abilities.slice(0, c.abilities.length - p.count)
      break
    }

    case 'CardExiled': {
      // Champ de bataille (ou main, coût alternatif de Force of Will) → exil (`none` : un jeton cesse d'exister).
      const pl = v.players[p.player]
      if (p.from === 'library')
        pl.library = p.library // Ragavan, Demonic Consultation, Necropotence : carte du dessus exilée
      else removeCard(p.from === 'hand' ? pl.hand : p.from === 'graveyard' ? pl.graveyard : pl.battlefield, p.card.id) // cimetière : escape, Necropotence
      zoneOf(v.players[p.owner ?? p.player], p.destination)?.push(board(p.card))
      break
    }

    case 'CardPutIntoHand': {
      // Mise en main sans pioche (Ad Nauseam, Tainted Pact, Demonic Consultation, Necropotence, Beseech the Mirror).
      const pl = v.players[p.player]
      if (p.from === 'library') pl.library = p.library
      else removeCard(pl.exile, p.card.id)
      pl.hand.push(board(p.card))
      break
    }

    case 'CardImprinted': {
      // Le permanent retient les couleurs de la carte exilée (la carte elle-même est partie par `CardExiled`, `from: hand`).
      const c = v.players[p.player].battlefield.find((x) => x.id === p.card)
      if (c) c.imprint = [...p.colors]
      break
    }

    case 'CommanderReturned': {
      // SBA (CR 903.9a) : le commandant quitte le cimetière ou l'exil pour la zone de commandement.
      const pl = v.players[p.player]
      removeCard(p.from === 'exile' ? pl.exile : pl.graveyard, p.card.id)
      pl.command.push(board(p.card))
      break
    }

    case 'CountersAdded': {
      // Valeurs absolues : total de la sorte, et force / endurance résultantes d'une créature (Amass).
      const c = v.players[p.player].battlefield.find((x) => x.id === p.card)
      if (c) {
        c.counters = { ...c.counters, [p.kind]: p.count }
        if (p.power !== undefined) {
          c.power = p.power
          c.toughness = p.toughness
        }
      }
      break
    }

    case 'CountersRemoved': {
      // Valeur absolue : marqueurs restants de la sorte (Wishclaw Talisman), clé retirée à 0.
      const c = v.players[p.player].battlefield.find((x) => x.id === p.card)
      if (c) {
        const counters = { ...c.counters }
        if (p.count > 0) counters[p.kind] = p.count
        else delete counters[p.kind]
        c.counters = counters
      }
      break
    }

    case 'PregameCardPlaced': {
      // Gemstone Caverns : de la main au champ de bataille avant le premier tour (son marqueur et la carte exilée suivent).
      const pl = v.players[p.player]
      removeCard(pl.hand, p.card.id)
      pl.battlefield.push(board(p.card))
      break
    }

    case 'CreatureBoosted': {
      // Valeurs absolues : force/endurance actuelles (bonus du tour compris, ou remises à la base au cleanup).
      const c = v.players[p.player].battlefield.find((x) => x.id === p.card)
      if (c) {
        c.power = p.power
        c.toughness = p.toughness
      }
      break
    }

    case 'TokenCreated':
      // Gift (Fish engagé), Mobilize (Warrior engagés et attaquants).
      v.players[p.player].battlefield.push({ ...board(p.card), tapped: p.tapped === true, ...(p.attacking !== undefined ? { attacking: p.attacking } : {}) })
      break

    case 'PermanentSacrificed': {
      removeCard(v.players[p.player].battlefield, p.card.id)
      zoneOf(v.players[p.owner ?? p.player], p.destination)?.push(board(p.card))
      break
    }

    case 'LegendRuleApplied': {
      // CR 704.5j : les légendes non gardées quittent le champ de bataille (cimetière, ou rien pour un jeton).
      const pl = v.players[p.player]
      for (const r of p.removed) {
        removeCard(pl.battlefield, r.card.id)
        zoneOf(v.players[r.owner ?? p.player], r.destination)?.push(board(r.card))
      }
      break
    }

    case 'CombatEnded':
      for (const pl of Object.values(v.players)) {
        for (const c of pl.battlefield) {
          delete c.attacking
          delete c.blocking
        }
      }
      break

    case 'PlayerLost':
      v.players[p.player].lost = true
      break

    case 'PlayerLeftGame': {
      // CR 800.4a : permanents (les siens, même contrôlés par un autre joueur) et éléments de pile du joueur éliminé quittent la partie.
      for (const pl of Object.values(v.players)) pl.battlefield = pl.battlefield.filter((c) => !p.battlefield.includes(c.id))
      v.stack = v.stack.filter((i) => !p.stack.includes(i.id))
      break
    }

    case 'GameOver':
      v.over = true
      v.winner = p.winner
      break

    // 'CreatureDamaged', 'DeclarationFinished', 'SpellFizzled', 'CostNotPaid', 'DelayedTriggerCreated', 'NoncreatureLockApplied', 'CastLockApplied', 'AttacksLocked', 'FlashGranted', 'ExtraTurnAdded', 'ExtraTurnStarted', 'StepSkipped' et autres : pas d'effet sur l'état affiché.
  }

  return v
}

/** Rejoue les événements jusqu'à `upToSeq` inclus. */
export function replay(events: readonly GameEvent[], upToSeq = Infinity): GameView {
  const view = initialView()
  for (const e of events) {
    if (e.seq > upToSeq) break
    applyEvent(view, e)
  }
  return view
}
