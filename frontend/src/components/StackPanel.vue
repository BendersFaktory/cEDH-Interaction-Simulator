<script setup lang="ts">
import type { StackItemView } from '../types/engine'

defineProps<{ stack: StackItemView[] }>()

const abilityLabel = (item: StackItemView): string => {
  if (item.trigger) return `Trigger : ${item.label ?? item.trigger.effect}`
  const a = item.ability
  if (!a) return ''
  return a.effect === 'damage' && a.amount !== undefined ? `Capacité : ${a.amount} dégât${a.amount > 1 ? 's' : ''}` : `Capacité (${a.effect})`
}

const target = (t: string): string => (t.startsWith('player:') ? `J${t.slice(7)}` : `sort ${t.slice(6)}`)
</script>

<template>
  <section class="stack">
    <h3>Pile</h3>
    <p v-if="!stack.length" class="muted">vide</p>
    <ol>
      <!-- Dessus de la pile en premier -->
      <li v-for="item in [...stack].reverse()" :key="item.id" :class="{ 'stack-ability': item.ability || item.trigger }">
        <strong>{{ item.card.name }}</strong>
        <em v-if="item.ability || item.trigger" class="ability-label">{{ abilityLabel(item) }}</em>
        <small>J{{ item.controller }}</small>
        <small v-if="item.targets.length"> → {{ item.targets.map(target).join(', ') }}</small>
      </li>
    </ol>
  </section>
</template>
