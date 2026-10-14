<script setup lang="ts">
import { computed } from 'vue'
import { hasType, typesOf, type BoardCard } from '../types/engine'

const props = defineProps<{ card: BoardCard; compact?: boolean }>()

/** Symboles d'un coût '{X}{2}{R/G}' => ['X', '2', 'R/G']. */
const symbols = computed(() => (props.card.manaCost ? [...props.card.manaCost.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]) : []))
const symbolClass = (s: string) => (s.includes('/') ? 'hybrid' : /^[WUBRGC]$/.test(s) ? `m-${s}` : 'm-n')
const abilityText = (a: NonNullable<BoardCard['abilities']>[number]): string => {
  const cost = [
    a.manaCost,
    a.tap ? '{T}' : '',
    a.sacrifice ? 'sacrifier' : '',
    a.sacrificeCreature ? 'sacrifier une créature' : '',
    a.discard ? `défausser ${a.discard}` : '',
  ].filter(Boolean).join(', ')
  const what = a.isManaAbility
    ? (a.options ?? []).map((o) => o.replace(/@(\w+)$/, ' ($1 seulement)')).join(' | ')
    : a.effect === 'draw' ? `piocher ${a.amount}`
    : a.effect === 'token' ? `${a.amount} jeton ${(a.spec ?? '').replace(':', ' ')}`
    : a.effect === 'pump' ? (a.spec ?? '')
    : `${a.effect}${a.amount !== undefined ? ` ${a.amount}` : ''}`
  return `${cost} : ${what}${a.sorcerySpeed ? ' (rituel)' : ''}`
}
const abilitiesTitle = computed(() => (props.card.abilities ?? []).map(abilityText).join(' ; '))
const landColor = computed(() => (hasType(props.card, 'land') && props.card.produces?.length === 1 ? props.card.produces[0] : null))
</script>

<template>
  <div class="card" :class="[typesOf(card), { tapped: card.tapped, [`land-${landColor}`]: landColor, compact, commander: card.commander, token: card.token, attacking: card.attacking !== undefined, blocking: card.blocking !== undefined }]" :title="`${card.name} — coût ${card.cost}${hasType(card, 'creature') ? ` — ${card.power}/${card.toughness}` : ''}${abilitiesTitle ? ` — ${abilitiesTitle}` : ''}`">
    <span class="card-name">{{ card.name }}</span>
    <span v-if="card.token" class="card-token">jeton</span>
    <span v-if="card.type === 'land' && card.produces?.length" class="mana-sym" :class="landColor ? `m-${landColor}` : 'hybrid'">{{ card.produces.join('') }}</span>
    <span v-if="symbols.length" class="card-mana"><span v-for="(s, i) in symbols" :key="i" class="mana-sym" :class="symbolClass(s)">{{ s }}</span></span>
    <span v-else-if="card.cost > 0" class="card-cost">{{ card.cost }}</span>
    <span v-if="hasType(card, 'creature')" class="card-pt">{{ card.power }}/{{ card.toughness }}</span>
    <span v-if="card.attacking !== undefined" class="card-combat">⚔ J{{ card.attacking }}</span>
    <span v-else-if="card.blocking !== undefined" class="card-combat">🛡 {{ card.blocking }}</span>
    <span v-if="!hasType(card, 'land') && card.abilities?.length" class="card-abilities"><span v-for="(a, i) in card.abilities" :key="i" class="ability-chip" :class="{ mana: a.isManaAbility }">{{ a.isManaAbility ? '⚡' : '✦' }}</span></span>
    <!-- TODO: image Scryfall (cache local) -->
  </div>
</template>
