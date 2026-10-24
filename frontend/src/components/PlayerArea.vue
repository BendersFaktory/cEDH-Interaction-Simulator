<script setup lang="ts">
import { computed } from 'vue'
import { hasType, type BoardCard, type PlayerView } from '../types/engine'
import { restrictionLabel } from '../engine/describe'
import CardView from './CardView.vue'

const props = defineProps<{ player: PlayerView; isActive: boolean; hasPriority: boolean; hasPassed: boolean }>()

/** Réserve => liste de pastilles (une par unité), couleurs d'abord. */
const poolSymbols = (pool: Record<string, number> | undefined): string[] => Object.entries(pool ?? {}).flatMap(([s, n]) => Array<string>(Math.max(0, n)).fill(s))

/** Mana restreint => pastilles avec leur restriction. */
const restrictedSymbols = (r: PlayerView['restricted']): Array<{ s: string; kind: string }> => Object.entries(r ?? {}).flatMap(([kind, m]) => poolSymbols(m).map((s) => ({ s, kind })))

/** Champ de bataille en trois rangées : un permanent multi-type va dans la première qui lui correspond. */
const rows = computed(() => {
  const creatures: BoardCard[] = [],
    lands: BoardCard[] = [],
    others: BoardCard[] = []
  for (const c of props.player.battlefield) (hasType(c, 'creature') ? creatures : hasType(c, 'land') ? lands : others).push(c)
  return [
    { key: 'creatures', title: 'Créatures', cards: creatures },
    { key: 'others', title: 'Autres permanents', cards: others },
    { key: 'lands', title: 'Terrains', cards: lands },
  ]
})

/** Dégâts de commandant : clé "propriétaire:idCommandant" => libellé et jauge sur 21. */
const commanderDamage = computed(() => Object.entries(props.player.commanderDamage).map(([k, n]) => ({ k, owner: k.split(':')[0], n, pct: Math.min(100, (n / 21) * 100) })))
</script>

<template>
  <section class="player" :class="{ active: isActive, priority: hasPriority, passed: hasPassed && !hasPriority, lost: player.lost }">
    <header>
      <h3>
        J{{ player.id }} <small>{{ player.controller }}</small>
      </h3>
      <span v-if="isActive" class="badge active">tour</span>
      <span v-if="hasPriority" class="badge prio">priorité</span>
      <span v-else-if="hasPassed" class="badge passed">a passé</span>
      <span v-if="player.lost" class="badge lost">éliminé</span>
      <span class="life" :title="`${player.life} points de vie`">{{ player.life }}<small> PV</small></span>
    </header>

    <div class="player-status">
      <span v-if="poolSymbols(player.pool).length || restrictedSymbols(player.restricted).length" class="mana-pool" title="Réserve de mana">
        <span v-for="(s, i) in poolSymbols(player.pool)" :key="i" class="mana-sym" :class="/^[WUBRGC]$/.test(s) ? `m-${s}` : 'm-n'">{{ s }}</span>
        <span
          v-for="(r, i) in restrictedSymbols(player.restricted)"
          :key="`r${i}`"
          class="mana-sym restricted"
          :class="/^[WUBRGC]$/.test(r.s) ? `m-${r.s}` : 'm-n'"
          :title="`Mana restreint : ${restrictionLabel(r.kind)}`"
          >{{ r.s }}<sup>*</sup></span
        >
      </span>
      <span v-for="d in commanderDamage" :key="d.k" class="cmd-damage" :class="{ lethal: d.n >= 15 }" :title="`Dégâts du commandant de J${d.owner} : ${d.n}/21`">
        <span class="cmd-damage-label">⚔ J{{ d.owner }} {{ d.n }}/21</span>
        <span class="cmd-damage-bar"><span :style="{ width: `${d.pct}%` }" /></span>
      </span>
    </div>

    <div v-for="r in rows" v-show="r.cards.length" :key="r.key" class="zone" :class="`row-${r.key}`">
      <h4>{{ r.title }}</h4>
      <div class="cards">
        <CardView v-for="c in r.cards" :key="c.id" :card="c" compact />
      </div>
    </div>
    <p v-if="!player.battlefield.length" class="muted battlefield-empty">Aucun permanent</p>

    <div class="zone">
      <h4>Main ({{ player.hand.length }})</h4>
      <div class="cards">
        <CardView v-for="c in player.hand" :key="c.id" :card="c" compact />
      </div>
    </div>

    <footer>
      <span class="zone-command">
        Commandement :
        <CardView v-for="c in player.command" :key="c.id" :card="c" compact />
        <em v-if="!player.command.length">vide</em>
      </span>
      <span>Bibliothèque : {{ player.library }}</span>
      <details v-if="player.graveyard.length" class="graveyard">
        <summary>Cimetière : {{ player.graveyard.length }}</summary>
        <div class="cards">
          <CardView v-for="c in player.graveyard" :key="c.id" :card="c" compact />
        </div>
      </details>
      <span v-else>Cimetière : 0</span>
    </footer>
  </section>
</template>

<style scoped>
.mana-sym.restricted {
  outline: 1px dashed currentColor;
  outline-offset: 1px;
  cursor: help;
}
.mana-sym.restricted sup {
  font-size: 8px;
  margin-left: 1px;
}
</style>
