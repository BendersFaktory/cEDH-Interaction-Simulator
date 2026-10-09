<script setup lang="ts">
import type { StackItemView } from '../types/engine'

defineProps<{ stack: StackItemView[] }>()

const target = (t: string): string => (t.startsWith('player:') ? `J${t.slice(7)}` : `sort ${t.slice(6)}`)
</script>

<template>
  <section class="stack">
    <h3>Pile</h3>
    <p v-if="!stack.length" class="muted">vide</p>
    <ol>
      <!-- Dessus de la pile en premier -->
      <li v-for="item in [...stack].reverse()" :key="item.id">
        <strong>{{ item.card.name }}</strong>
        <small>J{{ item.controller }}</small>
        <small v-if="item.targets.length"> → {{ item.targets.map(target).join(', ') }}</small>
      </li>
    </ol>
  </section>
</template>
