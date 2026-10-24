<script setup lang="ts">
import { nextTick, watch } from 'vue'
import { describeEvent } from '../engine/describe'
import { useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()

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
    <ol>
      <li v-for="e in store.visibleEvents" :key="e.seq" :class="{ current: e.seq === store.cursor, future: e.seq > store.cursor, verbose: e.verbose }" @click="store.seek(e.seq)">
        <small>#{{ e.seq }}</small> {{ describeEvent(e) }}
      </li>
    </ol>
  </section>
</template>
