<script setup lang="ts">
import { computed } from 'vue'
import { useSimulationStore } from '../stores/simulation'
import { phaseLabel } from '../engine/phases'

const store = useSimulationStore()

const groups = computed(() => {
  const actions = store.pending?.actions ?? []
  return [
    { title: 'Terrains', items: actions.filter((a) => a.type === 'land') },
    { title: 'Sorts', items: actions.filter((a) => a.type === 'cast') },
    { title: 'Priorité', items: actions.filter((a) => a.type === 'pass') },
  ].filter((g) => g.items.length)
})
</script>

<template>
  <section v-if="store.pending" class="actions">
    <h3>À toi de jouer — J{{ store.pending.player }}</h3>
    <p class="muted">
      Tour {{ store.pending.turn }}, {{ phaseLabel(store.pending.phase) }} · pile : {{ store.pending.stack.length }}
    </p>
    <div v-for="g in groups" :key="g.title">
      <h4>{{ g.title }}</h4>
      <!-- TODO: TargetPicker — choisir la cible en cliquant sur le plateau au lieu d'une action par cible -->
      <button v-for="a in g.items" :key="a.id" :disabled="store.loading" @click="store.decide(a.id)">
        {{ a.label }}
      </button>
    </div>
  </section>
</template>
