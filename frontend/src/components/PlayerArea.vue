<script setup lang="ts">
import type { PlayerView } from '../types/engine'
import CardView from './CardView.vue'

defineProps<{ player: PlayerView; isActive: boolean; hasPriority: boolean; hasPassed: boolean }>()
</script>

<template>
  <section class="player" :class="{ active: isActive, priority: hasPriority, lost: player.lost }">
    <header>
      <h3>J{{ player.id }} <small>{{ player.controller }}</small></h3>
      <span class="life">{{ player.life }} PV</span>
      <span v-if="isActive" class="badge">tour</span>
      <span v-if="hasPriority" class="badge prio">priorité</span>
      <span v-else-if="hasPassed" class="badge passed">a passé</span>
      <span v-if="player.lost" class="badge lost">éliminé</span>
    </header>

    <div v-if="Object.keys(player.commanderDamage).length" class="zone">
      <h4>Dégâts de commandant reçus</h4>
      <small v-for="(n, k) in player.commanderDamage" :key="k">{{ k }} : {{ n }}/21 </small>
    </div>

    <div class="zone">
      <h4>Zone de commandement</h4>
      <div class="cards">
        <CardView v-for="c in player.command" :key="c.id" :card="c" compact />
        <em v-if="!player.command.length">vide</em>
      </div>
    </div>

    <div class="zone">
      <h4>Champ de bataille</h4>
      <div class="cards">
        <CardView v-for="c in player.battlefield" :key="c.id" :card="c" compact />
        <em v-if="!player.battlefield.length">vide</em>
      </div>
    </div>

    <div class="zone">
      <h4>Main ({{ player.hand.length }})</h4>
      <div class="cards">
        <CardView v-for="c in player.hand" :key="c.id" :card="c" compact />
      </div>
    </div>

    <footer>
      <span>Bibliothèque : {{ player.library }}</span>
      <span>Cimetière : {{ player.graveyard.length }}</span>
    </footer>
  </section>
</template>
