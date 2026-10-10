<script setup lang="ts">
import type { BoardCard } from '../types/engine'

defineProps<{ card: BoardCard; compact?: boolean }>()
</script>

<template>
  <div class="card" :class="[card.kind, { tapped: card.tapped, compact, commander: card.commander, attacking: card.attacking !== undefined, blocking: card.blocking !== undefined }]" :title="`${card.name} — coût ${card.cost}${card.kind === 'creature' ? ` — ${card.power}/${card.toughness}` : ''}`">
    <span class="card-name">{{ card.name }}</span>
    <span v-if="card.cost > 0" class="card-cost">{{ card.cost }}</span>
    <span v-if="card.kind === 'creature'" class="card-pt">{{ card.power }}/{{ card.toughness }}</span>
    <span v-if="card.attacking !== undefined" class="card-combat">⚔ J{{ card.attacking }}</span>
    <span v-else-if="card.blocking !== undefined" class="card-combat">🛡 {{ card.blocking }}</span>
    <!-- TODO: image Scryfall (cache local) -->
  </div>
</template>
