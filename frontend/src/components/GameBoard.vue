<script setup lang="ts">
import { computed } from 'vue'
import { useSimulationStore } from '../stores/simulation'
import PlayerArea from './PlayerArea.vue'
import StackPanel from './StackPanel.vue'
import CardPreview from './CardPreview.vue'
import PhaseStrip from './PhaseStrip.vue'
import { phaseLabel } from '../engine/phases'

const store = useSimulationStore()
const view = computed(() => store.view)

/** Places autour de la table, dans l'ordre du tour (sens horaire) à partir du siège du bas. */
const SEATS = ['bottom', 'left', 'top', 'right'] as const

/** Premier siège humain en bas (J0 en mode 4 IA), les suivants dans l'ordre du tour. */
const seated = computed(() => {
  const ids = Object.keys(view.value.players)
    .map(Number)
    .sort((a, b) => a - b)
  const start = Math.max(0, ids.indexOf(store.humanSeats[0] ?? ids[0]))
  return ids.map((_, i) => ({ seat: SEATS[i] ?? 'top', player: view.value.players[ids[(start + i) % ids.length]] }))
})
</script>

<template>
  <div class="board">
    <PhaseStrip />
    <div class="board-header">
      <strong>Tour {{ view.turn }}</strong>
      <span>{{ phaseLabel(view.phase) }}</span>
      <span v-if="view.over" class="badge">{{ view.winner === null ? 'Fin de partie' : `Victoire de J${view.winner}` }}</span>
    </div>

    <div class="table">
      <StackPanel class="seat-center" :stack="view.stack" />
      <PlayerArea
        v-for="s in seated"
        :key="s.player.id"
        :class="`seat-${s.seat}`"
        :player="s.player"
        :is-active="view.activePlayer === s.player.id"
        :has-priority="view.priority.holder === s.player.id"
        :has-passed="view.priority.passed.includes(s.player.id)"
      />
    </div>
    <CardPreview />
  </div>
</template>
