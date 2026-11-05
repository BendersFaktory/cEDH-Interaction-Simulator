<script setup lang="ts">
import { nextTick, watch } from 'vue'
import { computed, ref } from 'vue'
import { describeEvent } from '../engine/describe'
import { filterJournal } from '../engine/navigation'
import { useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()
/** Filtres du journal : joueur (`null` = tous) et type d'événement (`null` = tous). */
const playerFilter = ref<number | null>(null)
const typeFilter = ref<string | null>(null)
const playerIds = computed(() => Object.keys(store.view.players).map(Number))
const types = computed(() => [...new Set(store.events.map((e) => e.type))].sort())
const shown = computed(() => filterJournal(store.visibleEvents, playerFilter.value, typeFilter.value))

// Garde l'événement courant visible quand le curseur avance.
watch(
  () => store.cursor,
  async () => {
    await nextTick()
    document.querySelector('.log li.current')?.scrollIntoView({ block: 'nearest' })
  },
)
</script>

<template>
  <section class="log">
    <h3>Journal</h3>
    <label class="inline"> <input v-model="store.showVerbose" type="checkbox" /> afficher les passes de priorité </label>
    <div class="log-filters">
      <label class="inline">
        Joueur
        <select v-model="playerFilter" data-testid="log-filter-player">
          <option :value="null">tous</option>
          <option v-for="id in playerIds" :key="id" :value="id">J{{ id }}</option>
        </select>
      </label>
      <label class="inline">
        Type
        <select v-model="typeFilter" data-testid="log-filter-type">
          <option :value="null">tous</option>
          <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
        </select>
      </label>
      <small class="muted">{{ shown.length }} / {{ store.visibleEvents.length }}</small>
    </div>
    <ol>
      <li v-for="e in shown" :key="e.seq" :class="{ current: e.seq === store.cursor, future: e.seq > store.cursor, verbose: e.verbose }" @click="store.seek(e.seq)">
        <small>#{{ e.seq }}</small> {{ describeEvent(e) }}
      </li>
    </ol>
  </section>
</template>
