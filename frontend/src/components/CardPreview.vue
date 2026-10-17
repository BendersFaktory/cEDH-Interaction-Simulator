<script setup lang="ts">
import { computed, ref } from 'vue'
import { hasType } from '../types/engine'
import { abilityText, frameOf, keywordsText, manaSymbols, spellText, symbolClass, triggerText, typeLine } from '../engine/cardText'
import { useSimulationStore } from '../stores/simulation'

const store = useSimulationStore()
const card = computed(() => store.hoveredCard)
const art = computed(() => (card.value && !card.value.token ? store.cardImage(card.value.name, 'normal') : null))
const failed = ref<string | null>(null)
/** Lignes de texte de la carte : mots-clés, effet du sort, capacités, triggers. */
const lines = computed(() => {
  const c = card.value
  if (!c) return []
  return [
    keywordsText(c),
    hasType(c, 'land') ? '' : (spellText(c) ?? ''),
    ...(c.abilities ?? []).map(abilityText),
    ...(c.triggers ?? []).map(triggerText),
  ].filter(Boolean)
})
</script>

<template>
  <aside v-if="card" class="card-preview" :class="`frame-${frameOf(card)}`" aria-live="polite">
    <img v-if="art && failed !== art" class="preview-art" :src="art" :alt="card.name" loading="lazy" @error="failed = art" />
    <header>
      <strong>{{ card.name }}</strong>
      <span class="card-mana"><span v-for="(s, i) in manaSymbols(card.manaCost)" :key="i" class="mana-sym" :class="symbolClass(s)">{{ s }}</span></span>
    </header>
    <div class="preview-type">{{ typeLine(card) }}{{ card.token ? ' (jeton)' : '' }}</div>
    <ul class="preview-text">
      <li v-for="(l, i) in lines" :key="i">{{ l }}</li>
      <li v-if="!lines.length" class="muted">—</li>
    </ul>
    <footer v-if="hasType(card, 'creature')" class="preview-pt">{{ card.power }}/{{ card.toughness }}</footer>
  </aside>
</template>
