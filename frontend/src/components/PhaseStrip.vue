<script setup lang="ts">
import { computed } from 'vue'
import { frise } from '../engine/navigation'
import { useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()
const steps = computed(() => frise(store.events, store.checkpointSeqs, store.view.turn))
/** Étape courante : la dernière phase dont l'événement est <= curseur. */
const current = computed(() => steps.value.filter((s) => s.seq <= store.cursor).at(-1)?.seq ?? null)
</script>

<template>
  <nav v-if="steps.length" class="frise" aria-label="Frise du tour">
    <div v-for="s in steps" :key="s.seq" class="frise-step" :class="{ current: s.seq === current }">
      <button :title="`Phase « ${s.label} » (#${s.seq})`" @click="store.seek(s.seq)">{{ s.label }}</button>
      <span class="frise-dots">
        <button
          v-for="c in s.checkpoints"
          :key="c"
          class="frise-dot"
          :class="{ fork: c === store.forkPoint }"
          :title="`Point de bifurcation #${c}`"
          :aria-label="`Point de bifurcation #${c}`"
          @click="store.seek(c)"
        />
      </span>
    </div>
  </nav>
</template>

<style scoped>
.frise {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 0.8rem;
  align-items: flex-end;
  margin: 0.3rem 0;
}
.frise-step {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
.frise-step.current button:first-child {
  font-weight: bold;
  text-decoration: underline;
}
.frise-dots {
  display: flex;
  gap: 3px;
  margin-top: 2px;
}
.frise-dot {
  width: 8px;
  height: 8px;
  padding: 0;
  border-radius: 50%;
  border: 1px solid var(--line);
  background: transparent;
}
.frise-dot.fork {
  background: #f5c542;
}
</style>
