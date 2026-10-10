<script setup lang="ts">
import { computed } from 'vue'
import type { BoardCard } from '../types/engine'

const props = defineProps<{ card: BoardCard; compact?: boolean }>()

/** Symboles d'un coût '{X}{2}{R/G}' => ['X', '2', 'R/G']. */
const symbols = computed(() => (props.card.manaCost ? [...props.card.manaCost.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]) : []))
const symbolClass = (s: string) => (s.includes('/') ? 'hybrid' : /^[WUBRGC]$/.test(s) ? `m-${s}` : 'm-n')
const landColor = computed(() => (props.card.kind === 'land' && props.card.produces?.length === 1 ? props.card.produces[0] : null))
</script>

<template>
  <div class="card" :class="[card.kind, { tapped: card.tapped, [`land-${landColor}`]: landColor, compact, commander: card.commander, attacking: card.attacking !== undefined, blocking: card.blocking !== undefined }]" :title="`${card.name} — coût ${card.cost}${card.kind === 'creature' ? ` — ${card.power}/${card.toughness}` : ''}`">
    <span class="card-name">{{ card.name }}</span>
    <span v-if="card.kind === 'land' && card.produces?.length" class="mana-sym" :class="landColor ? `m-${landColor}` : 'hybrid'">{{ card.produces.join('') }}</span>
    <span v-if="symbols.length" class="card-mana"><span v-for="(s, i) in symbols" :key="i" class="mana-sym" :class="symbolClass(s)">{{ s }}</span></span>
    <span v-else-if="card.cost > 0" class="card-cost">{{ card.cost }}</span>
    <span v-if="card.kind === 'creature'" class="card-pt">{{ card.power }}/{{ card.toughness }}</span>
    <span v-if="card.attacking !== undefined" class="card-combat">⚔ J{{ card.attacking }}</span>
    <span v-else-if="card.blocking !== undefined" class="card-combat">🛡 {{ card.blocking }}</span>
    <!-- TODO: image Scryfall (cache local) -->
  </div>
</template>
