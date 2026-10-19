<script setup lang="ts">
import { computed } from 'vue'
import type { StackItemView } from '../types/engine'

const props = defineProps<{ stack: StackItemView[] }>()

const topFirst = computed(() => [...props.stack].reverse())

const abilityLabel = (item: StackItemView): string => {
  if (item.trigger) return `Trigger : ${item.label ?? item.trigger.effect}`
  const a = item.ability
  if (!a) return ''
  return a.effect === 'damage' && a.amount !== undefined ? `Capacité : ${a.amount} dégât${a.amount > 1 ? 's' : ''}` : `Capacité (${a.effect})`
}

const target = (t: string): string => (t.startsWith('player:') ? `J${t.slice(7)}` : `sort ${t.slice(6)}`)
</script>

<template>
  <section class="stack" :class="{ 'stack-empty': !stack.length }" aria-live="polite">
    <h3>Pile <span v-if="stack.length" class="badge">{{ stack.length }}</span><span v-else class="muted stack-empty-label">vide</span></h3>
    <ol v-if="stack.length">
      <li v-for="(item, i) in topFirst" :key="item.id" :class="{ 'stack-ability': item.ability || item.trigger, 'stack-top': i === 0 }">
        <span v-if="i === 0" class="badge stack-top-badge">Dessus</span>
        <strong>{{ item.card.name }}</strong>
        <em v-if="item.ability || item.trigger" class="ability-label">{{ abilityLabel(item) }}</em>
        <small>J{{ item.controller }}</small>
        <small v-if="item.targets.length"> → {{ item.targets.map(target).join(', ') }}</small>
      </li>
    </ol>
  </section>
</template>

<style>
.table > .stack {
  position: sticky;
  top: 0;
  z-index: 5;
  width: 100%;
  align-self: start;
  box-shadow: 0 4px 12px rgba(0, 0, 0, .35);
}
.stack h3 { display: flex; align-items: center; gap: 8px; margin: 0; }
.table > .stack.stack-empty { position: static; padding-top: 6px; padding-bottom: 6px; box-shadow: none; }
.stack-empty-label { font-weight: 400; font-size: 13px; }
.stack ol { margin-top: 6px; max-height: 40vh; overflow: auto; }
.stack li { padding: 2px 6px; border-radius: 4px; }
.stack li.stack-top { background: rgba(106, 169, 255, .14); outline: 1px solid var(--accent); }
.stack .stack-top-badge { background: var(--accent); color: #000; margin-right: 6px; }
</style>
