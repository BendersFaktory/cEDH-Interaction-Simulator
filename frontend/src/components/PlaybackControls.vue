<script setup lang="ts">
import { useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()
</script>

<template>
  <section class="playback">
    <div class="buttons">
      <button title="Début" @click="store.seek(0)">⏮</button>
      <button title="Pas en arrière" @click="store.stepBack()">◀</button>
      <button v-if="!store.playing" title="Lecture" @click="store.play()">▶</button>
      <button v-else title="Pause" @click="store.pause()">⏸</button>
      <button title="Pas en avant" @click="store.step()">▶|</button>
      <button title="Fin" @click="store.seek(store.lastSeq)">⏭</button>
    </div>

    <input
      class="slider"
      type="range"
      min="0"
      :max="store.lastSeq"
      :value="store.cursor"
      @input="store.seek(Number(($event.target as HTMLInputElement).value))"
    />

    <label class="inline">
      Vitesse
      <select :value="store.speedMs" @change="store.setSpeed(Number(($event.target as HTMLSelectElement).value))">
        <option :value="800">lente</option>
        <option :value="250">normale</option>
        <option :value="60">rapide</option>
        <option :value="10">très rapide</option>
      </select>
    </label>
    <small class="muted">événement {{ store.cursor }} / {{ store.lastSeq }}</small>
  </section>
</template>
