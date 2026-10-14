<script setup lang="ts">
import { ref } from 'vue'
import ActionPanel from './components/ActionPanel.vue'
import EventLog from './components/EventLog.vue'
import GameBoard from './components/GameBoard.vue'
import PauseBar from './components/PauseBar.vue'
import StopsPanel from './components/StopsPanel.vue'
import PlaybackControls from './components/PlaybackControls.vue'
import { useSimulationStore } from './stores/simulation'

const store = useSimulationStore()
const seed = ref<number | undefined>(undefined)
const loadId = ref<number | undefined>(undefined)
const playAsJ0 = ref(true)
const AI_PROFILES = [
  { key: 'ai:heuristic', label: 'Équilibrée' },
  { key: 'ai:aggro', label: 'Aggro' },
  { key: 'ai:control', label: 'Contrôle' },
]
const aiProfiles = ref(['ai:heuristic', 'ai:heuristic', 'ai:heuristic', 'ai:heuristic'])
</script>

<template>
  <header class="topbar">
    <h1>MTG Sim</h1>
    <label class="inline">Seed <input v-model.number="seed" type="number" placeholder="aléatoire" /></label>
    <label class="inline"><input v-model="playAsJ0" type="checkbox" /> Je joue J0</label>
    <template v-for="(_, i) in aiProfiles" :key="i">
      <label v-if="i > 0 || !playAsJ0" class="inline">
        J{{ i }}
        <select v-model="aiProfiles[i]" :data-testid="`ai-profile-${i}`">
          <option v-for="p in AI_PROFILES" :key="p.key" :value="p.key">{{ p.label }}</option>
        </select>
      </label>
    </template>
    <button :disabled="store.loading" @click="store.create(seed, playAsJ0, aiProfiles)">Nouvelle partie Commander</button>
    <label class="inline">Charger # <input v-model.number="loadId" type="number" /></label>
    <button :disabled="!loadId || store.loading" @click="store.load(loadId!)">Ouvrir</button>
    <span v-if="store.simulation" class="muted">
      simulation #{{ store.simulation.id }}
      <template v-if="store.simulation.parentId">(fork de #{{ store.simulation.parentId }} @ {{ store.simulation.forkEventSeq }})</template>
      — {{ store.simulation.status }}
    </span>
    <span v-if="store.waiting" class="muted">Simulation en cours…<template v-if="store.progress"> tour {{ store.progress }}</template></span>
    <span v-if="store.error" class="error">{{ store.error }}</span>
  </header>

  <main v-if="store.simulation" class="layout">
    <GameBoard />
    <aside>
      <PlaybackControls />
      <ActionPanel />
      <StopsPanel />
      <PauseBar />
      <EventLog />
    </aside>
  </main>
  <p v-else class="empty muted">Lance une nouvelle partie pour commencer.</p>
</template>
