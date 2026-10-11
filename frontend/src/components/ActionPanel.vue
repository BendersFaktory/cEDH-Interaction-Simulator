<script setup lang="ts">
import { computed } from 'vue'
import { useSimulationStore } from '../stores/simulation'
import { phaseLabel } from '../engine/phases'

const store = useSimulationStore()

const groups = computed(() => {
  const actions = store.pending?.actions ?? []
  return [
    { title: 'Attaquer', items: actions.filter((a) => a.type === 'attack') },
    { title: 'Bloquer', items: actions.filter((a) => a.type === 'block') },
    { title: 'Terminer la déclaration', items: actions.filter((a) => a.type === 'done') },
    { title: 'Défausser', items: actions.filter((a) => a.type === 'discard') },
    { title: 'Déclencheurs à mettre sur la pile', items: actions.filter((a) => a.type === 'trigger') },
    { title: 'Terrains', items: actions.filter((a) => a.type === 'land') },
    { title: 'Capacités', items: actions.filter((a) => a.type === 'ability') },
    { title: 'Sorts', items: actions.filter((a) => a.type === 'cast') },
    { title: 'Priorité', items: actions.filter((a) => a.type === 'pass') },
  ].filter((g) => g.items.length)
})
</script>

<template>
  <section v-if="store.pending" class="actions">
    <h3>À toi de jouer — J{{ store.pending.player }}</h3>
    <p class="muted">
      Tour {{ store.pending.turn }}, {{ phaseLabel(store.pending.phase) }}{{ store.pending.declaring === 'attackers' ? ' — déclaration des attaquants' : store.pending.declaring === 'blockers' ? ' — déclaration des bloqueurs' : '' }} · pile : {{ store.pending.stack.length }}
    </p>
    <div v-for="g in groups" :key="g.title">
      <h4>{{ g.title }}</h4>
      <!-- TODO: TargetPicker — choisir la cible en cliquant sur le plateau au lieu d'une action par cible -->
      <button v-for="a in g.items" :key="a.id" :class="{ 'ability-btn': a.type === 'ability', 'mana-ability': a.type === 'ability' && a.mana === true }" :disabled="store.loading" @click="store.decide(a.id)">
        {{ a.label }}
      </button>
    </div>
  </section>
</template>
