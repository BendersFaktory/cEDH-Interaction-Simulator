<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import { useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()

/** Vitesses de lecture : multiplicateur sur la cadence normale (250 ms par événement). */
const BASE_MS = 250
const SPEEDS = [0.5, 1, 2, 4]
const speedMs = (factor: number): number => Math.round(BASE_MS / factor)

/** Saisie dans un champ : les raccourcis ne doivent pas la détourner. */
function isTyping(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null
  if (!el || !el.tagName) return false
  if (el.isContentEditable) return true
  const tag = el.tagName
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON'
}

function onKeydown(e: KeyboardEvent): void {
  if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) return
  if (e.key === ' ' || e.code === 'Space') {
    e.preventDefault()
    if (store.playing) store.pause()
    else store.play()
  } else if (e.key === 'ArrowLeft') {
    e.preventDefault()
    store.stepBack()
  } else if (e.key === 'ArrowRight') {
    e.preventDefault()
    store.step()
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <section class="playback">
    <div class="buttons">
      <button title="Début" @click="store.seek(0)">⏮</button>
      <button title="Pas en arrière (←)" @click="store.stepBack()">◀</button>
      <button v-if="!store.playing" title="Lecture (espace)" @click="store.play()">▶</button>
      <button v-else title="Pause (espace)" @click="store.pause()">⏸</button>
      <button title="Pas en avant (→)" @click="store.step()">▶|</button>
      <button title="Fin" @click="store.seek(store.lastSeq)">⏭</button>
    </div>

    <input class="slider" type="range" min="0" :max="store.lastSeq" :value="store.cursor" @input="store.seek(Number(($event.target as HTMLInputElement).value))" />

    <label class="inline">
      Vitesse
      <select :value="store.speedMs" @change="store.setSpeed(Number(($event.target as HTMLSelectElement).value))">
        <option v-for="f in SPEEDS" :key="f" :value="speedMs(f)">{{ f }}x</option>
      </select>
    </label>
    <small class="muted">événement {{ store.cursor }} / {{ store.lastSeq }}</small>
  </section>
</template>
