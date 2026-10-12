<script setup lang="ts">
import { computed } from 'vue'
import { useSimulationStore } from '../stores/simulation'
import PlayerArea from './PlayerArea.vue'
import StackPanel from './StackPanel.vue'
import { phaseLabel } from '../engine/phases'

const store = useSimulationStore()
const view = computed(() => store.view)
const players = computed(() => Object.values(view.value.players))
</script>

<template>
  <div class="board">
    <div class="board-header">
      <strong>Tour {{ view.turn }}</strong>
      <span>{{ phaseLabel(view.phase) }}</span>
      <span v-if="view.over" class="badge">{{ view.winner === null ? 'Fin de partie' : `Victoire de J${view.winner}` }}</span>
    </div>

    <StackPanel :stack="view.stack" />

    <div class="players">
      <PlayerArea
        v-for="p in players"
        :key="p.id"
        :player="p"
        :is-active="view.activePlayer === p.id"
        :has-priority="view.priority.holder === p.id"
        :has-passed="view.priority.passed.includes(p.id)"
      />
    </div>
  </div>
</template>
