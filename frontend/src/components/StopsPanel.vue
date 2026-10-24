<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { phaseLabel } from '../engine/phases'
import { useSimulationStore } from '../stores/simulation'
import { STOP_STEPS, type StopsConfig } from '../types/engine'

const store = useSimulationStore()
const player = ref<number | null>(null)
const form = reactive<StopsConfig>({ steps: [], onOpponentSpell: false, onDeclareAttackers: false, onDeclareBlockers: false })

// Pré-coche selon l'état serveur ; sans config, le défaut équivaut à tout cocher (StopConfig::all() côté moteur).
function fill(): void {
  const s = player.value === null ? null : store.stopsOf(player.value)
  form.steps = s ? [...s.steps] : [...STOP_STEPS]
  form.onOpponentSpell = s?.onOpponentSpell ?? true
  form.onDeclareAttackers = s?.onDeclareAttackers ?? true
  form.onDeclareBlockers = s?.onDeclareBlockers ?? true
}

watch(
  () => store.humanSeats,
  (seats) => {
    if (player.value === null || !seats.includes(player.value)) player.value = seats[0] ?? null
  },
  { immediate: true },
)
watch([player, () => store.simulation], fill, { immediate: true })

const apply = () => player.value !== null && store.setStops(player.value, { ...form, steps: [...form.steps] })
const reset = () => player.value !== null && store.setStops(player.value, null)
</script>

<template>
  <section v-if="store.humanSeats.length && player !== null" class="stops">
    <h3>Stops</h3>
    <label v-if="store.humanSeats.length > 1" class="inline">
      Joueur
      <select v-model.number="player">
        <option v-for="id in store.humanSeats" :key="id" :value="id">J{{ id }}</option>
      </select>
    </label>
    <p class="muted">S'arrêter à ces étapes (seulement si une action est possible) :</p>
    <label v-for="step in STOP_STEPS" :key="step" class="inline"> <input v-model="form.steps" type="checkbox" :value="step" /> {{ phaseLabel(step) }} </label>
    <p class="muted">Options :</p>
    <label class="inline"><input v-model="form.onOpponentSpell" type="checkbox" /> Sort, capacité ou trigger adverse sur la pile</label>
    <label class="inline"><input v-model="form.onDeclareAttackers" type="checkbox" /> Déclaration des attaquants</label>
    <label class="inline"><input v-model="form.onDeclareBlockers" type="checkbox" /> Déclaration des bloqueurs</label>
    <div class="inline">
      <button :disabled="store.loading" @click="apply">Appliquer</button>
      <button :disabled="store.loading" @click="reset">Défaut</button>
    </div>
  </section>
</template>
