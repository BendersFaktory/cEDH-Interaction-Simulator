<script setup lang="ts">
import { computed, ref } from 'vue'
import { AI_PROFILES, useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()
const takeOver = ref<number[]>([])
const playerIds = computed(() => Object.keys(store.view.players).map(Number))
/** Profil choisi par siège pour rendre la main à l'IA (défaut : celui de la création, sinon « Équilibrée »). */
const handBackProfile = ref<Record<number, string>>({})
const profileOf = (seat: number) => handBackProfile.value[seat] ?? store.seatProfiles[seat] ?? 'ai:heuristic'

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
        <label v-for="id in playerIds" :key="id" class="inline"> <input v-model="takeOver" type="checkbox" :value="id" /> J{{ id }} </label>
      </div>
      <button :disabled="!takeOver.length || store.loading" @click="store.forkHere(takeOver)">Reprendre la main ici</button>

      <div v-if="store.humanSeats.length" class="handback">
        <h4>Rendre la main à l'IA</h4>
        <p v-for="seat in store.humanSeats" :key="seat" class="inline">
          <label class="inline">
            J{{ seat }} :
            <select :value="profileOf(seat)" :data-testid="`handback-profile-${seat}`" @change="handBackProfile[seat] = ($event.target as HTMLSelectElement).value">
              <option v-for="p in AI_PROFILES" :key="p.key" :value="p.key">{{ p.label }}</option>
            </select>
          </label>
          <button :disabled="store.loading" :data-testid="`handback-${seat}`" @click="store.handBackToAi(seat, profileOf(seat))">Rendre la main à l'IA</button>
        </p>
      </div>
    </template>
  </section>
</template>
