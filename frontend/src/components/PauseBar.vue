<script setup lang="ts">
import { computed, ref } from 'vue'
import { useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()
const takeOver = ref<number[]>([])
const playerIds = computed(() => Object.keys(store.view.players).map(Number))

// Le point de bifurcation est le dernier octroi de priorité <= curseur.
const forkEvent = computed(() => store.events.find((e) => e.seq === store.forkPoint))
</script>

<template>
  <section class="pausebar">
    <h3>Reprendre la main</h3>

    <p v-if="store.forkPoint === null" class="muted">Avance jusqu'à un octroi de priorité pour pouvoir bifurquer.</p>
    <template v-else>
      <p>
        Bifurcation au point <strong>#{{ store.forkPoint }}</strong>
        <span v-if="forkEvent"> — J{{ forkEvent.payload.holder }} a la priorité (pile : {{ forkEvent.payload.stackSize }})</span>
      </p>
      <div class="inline">
        Je prends :
        <label v-for="id in playerIds" :key="id" class="inline">
          <input v-model="takeOver" type="checkbox" :value="id" /> J{{ id }}
        </label>
      </div>
      <button :disabled="!takeOver.length || store.loading" @click="store.forkHere(takeOver)">
        Reprendre la main ici
      </button>
    </template>
  </section>
</template>
