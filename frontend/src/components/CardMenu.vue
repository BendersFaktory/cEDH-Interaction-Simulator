<script setup lang="ts">
import { useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()
</script>

<template>
  <div v-if="store.selectedCardId && store.cardActions.length" class="card-menu" data-testid="card-menu">
    <h4>Actions de la carte</h4>
    <button v-for="a in store.cardActions" :key="a.id" :disabled="store.loading" @click="store.decide(a.id)">{{ a.label }}</button>
    <button :disabled="store.loading" @click="store.selectCard(store.selectedCardId!)">Fermer</button>
  </div>
</template>

<style scoped>
.card-menu {
  margin-top: 0.4rem;
  padding: 0.4rem;
  border: 1px solid var(--accent);
  border-radius: 6px;
}
</style>
