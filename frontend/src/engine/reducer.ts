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

function zoneOf(pl: PlayerView, destination: string): BoardCard[] {
  switch (destination) {
    case 'battlefield':
      return pl.battlefield
    case 'command':
      return pl.command
    default:
      return pl.graveyard
  }
}

function removeCard(cards: BoardCard[], id: string): void {
  const i = cards.findIndex((c) => c.id === id)
  if (i >= 0) cards.splice(i, 1)
}

/** Applique UN événement en mutant `v` (rapide pour rejouer des milliers d'événements). */
export function applyEvent(v: GameView, e: GameEvent): GameView {
  const p = e.payload
  v.lastSeq = e.seq

  switch (e.type) {
    case 'GameStarted':
      for (const pl of p.players as Array<{ id: number; life: number; library: number; controller: string; command?: PublicCard[] }>) {
        v.players[pl.id] = {
          id: pl.id,
          life: pl.life,
          library: pl.library,
          hand: [],
          battlefield: [],
          graveyard: [],
          command: (pl.command ?? []).map(board),
          lost: false,
          controller: pl.controller,
          commanderDamage: {},
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
      removeCard(pl.hand, p.card.id)
      pl.battlefield.push(board(p.card))
      break
    }

    case 'SpellCast': {
      const pl = v.players[p.player]
      // Un commandant est lancé depuis la zone de commandement, le reste depuis la main.
      removeCard(p.fromCommand ? pl.command : pl.hand, p.card.id)
      for (const id of p.tapped as string[]) {
        const land = pl.battlefield.find((c) => c.id === id)
        if (land) land.tapped = true
      }
      v.stack.push({ id: p.stackId, card: p.card, controller: p.player, targets: p.targets })
      v.priority.passed = []
      break
    }

    case 'SpellResolved': {
      v.stack = v.stack.filter((i) => i.id !== p.stackId)
      zoneOf(v.players[p.controller], p.destination).push(board(p.card))
      break
    }

    case 'SpellCountered': {
      v.stack = v.stack.filter((i) => i.id !== p.stackId)
      // Un commandant contré retourne en zone de commandement (règle de remplacement).
      zoneOf(v.players[p.controller], p.destination).push(board(p.card))
      break
    }

    case 'DamageDealt': {
      const id = Number(String(p.target).replace('player:', ''))
      v.players[id].life = p.life
      if (p.commanderDamage) v.players[id].commanderDamage[p.commanderDamage.key] = p.commanderDamage.total
      break
    }

    case 'CardDiscarded': {
      const pl = v.players[p.player]
      removeCard(pl.hand, p.card.id)
      zoneOf(pl, p.destination).push(board(p.card))
      break
    }

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

    case 'CreatureDied': {
      const pl = v.players[p.player]
      removeCard(pl.battlefield, p.card.id)
      zoneOf(pl, p.destination).push(board(p.card))
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

    case 'GameOver':
      v.over = true
      v.winner = p.winner
      break

    // 'CreatureDamaged', 'DeclarationFinished', 'SpellFizzled' et autres : pas d'effet sur l'état affiché.
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
