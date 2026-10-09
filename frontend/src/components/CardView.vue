<script setup lang="ts">
import type { BoardCard } from '../types/engine'

defineProps<{ card: BoardCard; compact?: boolean }>()
</script>

<template>
  <div class="card" :class="[card.kind, { tapped: card.tapped, compact, commander: card.commander }]" :title="`${card.name} — coût ${card.cost}`">
    <span class="card-name">{{ card.name }}</span>
    <span v-if="card.cost > 0" class="card-cost">{{ card.cost }}</span>
    <!-- TODO: image Scryfall (cache local) -->
  </div>
</template>
