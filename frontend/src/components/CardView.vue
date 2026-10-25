<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { hasType, typesOf, type BoardCard } from '../types/engine'
import { frameOf, keywordsText, manaSymbols, symbolClass, typeLine } from '../engine/cardText'
import { useSimulationStore } from '../stores/simulation'

const props = defineProps<{ card: BoardCard; compact?: boolean; targetable?: boolean }>()
const emit = defineEmits<{ pick: [] }>()
const store = useSimulationStore()

const symbols = computed(() => manaSymbols(props.card.manaCost))
const keywords = computed(() => keywordsText(props.card))
const landColor = computed(() => (hasType(props.card, 'land') && props.card.produces?.length === 1 ? props.card.produces[0] : null))
const art = computed(() => (props.card.token ? null : store.cardImage(props.card.name)))
const artFailed = ref(false)
watch(art, () => (artFailed.value = false))
const playable = computed(() => store.playableCardIds.has(props.card.id))
</script>

<template>
  <div
    class="card"
    :class="[
      typesOf(card),
      `frame-${frameOf(card)}`,
      {
        tapped: card.tapped,
        [`land-${landColor}`]: landColor,
        compact,
        commander: card.commander,
        token: card.token,
        attacking: card.attacking !== undefined,
        blocking: card.blocking !== undefined,
        playable,
        targetable: props.targetable,
      },
    ]"
    @mouseenter="store.hoveredCard = card"
    @mouseleave="store.hoveredCard = null"
    @click="emit('pick')"
  >
    <img v-if="art && !artFailed && !compact" class="card-art" :src="art" alt="" loading="lazy" @error="artFailed = true" />
    <span class="card-name">{{ card.name }}</span>
    <span v-if="card.token" class="card-token">jeton</span>
    <span v-if="card.type === 'land' && card.produces?.length" class="mana-sym" :class="landColor ? `m-${landColor}` : 'hybrid'">{{ card.produces.join('') }}</span>
    <span v-if="symbols.length" class="card-mana"
      ><span v-for="(s, i) in symbols" :key="i" class="mana-sym" :class="symbolClass(s)">{{ s }}</span></span
    >
    <span v-else-if="card.cost > 0" class="card-cost">{{ card.cost }}</span>
    <span v-if="hasType(card, 'creature')" class="card-pt">{{ card.power }}/{{ card.toughness }}</span>
    <span v-if="card.attacking !== undefined" class="card-combat">⚔ J{{ card.attacking }}</span>
    <span v-else-if="card.blocking !== undefined" class="card-combat">🛡 {{ card.blocking }}</span>
    <span v-if="!hasType(card, 'land') && card.abilities?.length" class="card-abilities"
      ><span v-for="(a, i) in card.abilities" :key="i" class="ability-chip" :class="{ mana: a.isManaAbility }">{{ a.isManaAbility ? '⚡' : '✦' }}</span></span
    >
    <span v-if="!hasType(card, 'land')" class="card-type">{{ typeLine(card) }}</span>
    <span v-if="keywords" class="card-keywords">{{ keywords }}</span>
  </div>
</template>
