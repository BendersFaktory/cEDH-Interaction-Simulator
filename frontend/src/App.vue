<script setup lang="ts">
import { ref } from 'vue'
import ActionPanel from './components/ActionPanel.vue'
import BranchTree from './components/BranchTree.vue'
import EventLog from './components/EventLog.vue'
import GameBoard from './components/GameBoard.vue'
import PauseBar from './components/PauseBar.vue'
import StopsPanel from './components/StopsPanel.vue'
import PlaybackControls from './components/PlaybackControls.vue'
import { AI_PROFILES, useSimulationStore } from './stores/simulation'
import type { DeckInput } from './types/engine'

const store = useSimulationStore()
const seed = ref<number | undefined>(undefined)
const loadId = ref<number | undefined>(undefined)
const playAsJ0 = ref(true)
/** Decklists optionnelles par siège (texte brut) ; un siège vide garde son deck de démo. */
const decklists = ref(['', '', '', ''])
const commanders = ref(['', '', '', ''])
function decks() {
  const out: Record<number, DeckInput> = {}
  decklists.value.forEach((text, i) => {
    if (!text.trim()) return
    const commander = commanders.value[i].trim()
    out[i] = commander ? { decklist: text, commander } : text
  })
  return out
}
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
    <button :disabled="store.loading" @click="store.create(seed, playAsJ0, aiProfiles, decks())">Nouvelle partie Commander</button>
    <label class="inline">Charger # <input v-model.number="loadId" type="number" /></label>
    <button :disabled="!loadId || store.loading" @click="store.load(loadId!)">Ouvrir</button>
    <span v-if="store.simulation" class="muted">
      simulation #{{ store.simulation.id }}
      <template v-if="store.simulation.parentId">(fork de #{{ store.simulation.parentId }} @ {{ store.simulation.forkEventSeq }})</template>
      — {{ store.simulation.status }}
    </span>
    <span v-if="store.waiting" class="muted"
      >Simulation en cours…<template v-if="store.progress"> tour {{ store.progress }}</template></span
    >
    <span v-if="store.error" class="error">{{ store.error }}</span>
  </header>

  <details class="decks" data-testid="decks">
    <summary>Decklists (optionnel) <span class="muted">— vide : deck de démo du siège</span></summary>
    <div class="deck-grid">
      <label v-for="(_, i) in decklists" :key="i" class="deck-seat">
        J{{ i }}
        <input v-model="commanders[i]" :data-testid="`deck-commander-${i}`" placeholder="Commandant (sinon ligne « CMDR: »)" />
        <textarea v-model="decklists[i]" :data-testid="`deck-list-${i}`" rows="4" placeholder="CMDR: Nom du commandant&#10;1 Sol Ring&#10;98 Plains"></textarea>
      </label>
    </div>
  </details>
  <div v-if="store.deckProblems" class="deck-problems" data-testid="deck-problems">
    <template v-for="(list, seat) in store.deckProblems" :key="seat">
      <strong>{{ seat }}</strong>
      <ul>
        <li v-for="(problem, k) in list" :key="k">{{ problem }}</li>
      </ul>
    </template>
  </div>

  <main v-if="store.simulation" class="layout">
    <GameBoard />
    <aside>
      <PlaybackControls />
      <ActionPanel />
      <StopsPanel />
      <PauseBar />
      <BranchTree />
      <EventLog />
    </aside>
  </main>
  <p v-else class="empty muted">Lance une nouvelle partie pour commencer.</p>
</template>
