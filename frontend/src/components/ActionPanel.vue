<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useSimulationStore } from '../stores/simulation'
import { phaseLabel } from '../engine/phases'
import { target } from '../engine/describe'
import type { ActionView } from '../types/engine'

const store = useSimulationStore()
/** Passer alors que la pile n'est pas vide demande une confirmation (deux clics). */
const confirmPass = ref(false)
watch(
  () => store.pending?.eventSeq,
  () => (confirmPass.value = false),
)

function act(a: ActionView): void {
  if (a.type === 'pass' && store.pending?.stack.length && !confirmPass.value) {
    confirmPass.value = true
    return
  }
  confirmPass.value = false
  store.decide(a.id)
}

const groups = computed(() => {
  const actions = store.pending?.actions ?? []
  const listed = [
    { title: 'Main de départ', items: actions.filter((a) => a.type === 'keep' || a.type === 'mulligan') },
    { title: 'Mettre au fond', items: actions.filter((a) => a.type === 'bottom') },
    { title: 'Attaquer', items: actions.filter((a) => a.type === 'attack') },
    { title: 'Bloquer', items: actions.filter((a) => a.type === 'block') },
    { title: 'Répartir les dégâts de combat', items: actions.filter((a) => a.type === 'assign') },
    { title: 'Terminer la déclaration', items: actions.filter((a) => a.type === 'done') },
    { title: 'Règle des légendes : quelle légende garder ?', items: actions.filter((a) => a.type === 'keepLegend') },
    { title: 'Payer pour la résolution ?', items: actions.filter((a) => a.type === 'pay') },
    { title: 'Scry : ranger le dessus de la bibliothèque', items: actions.filter((a) => a.type === 'scry') },
    { title: 'Remettre une carte de ta main sur ta bibliothèque', items: actions.filter((a) => a.type === 'putBack') },
    { title: 'Remettre les cartes du dessus dans l’ordre, ou mélanger', items: actions.filter((a) => a.type === 'arrange') },
    { title: 'Chain of Vapor : sacrifier un terrain pour copier le sort ?', items: actions.filter((a) => a.type === 'chain') },
    { title: 'Défausser', items: actions.filter((a) => a.type === 'discard') },
    { title: 'Triggers à mettre sur la pile', items: actions.filter((a) => a.type === 'trigger') },
    { title: 'Terrains', items: actions.filter((a) => a.type === 'land') },
    { title: 'Capacités', items: actions.filter((a) => a.type === 'ability') },
    { title: 'Sorts', items: actions.filter((a) => a.type === 'cast') },
    { title: 'Priorité', items: actions.filter((a) => a.type === 'pass') },
  ]
  // Un type d'action que le panneau ne connaît pas reste proposé (sinon la partie serait bloquée sur une décision invisible).
  const known = new Set(listed.flatMap((g) => g.items))
  return [...listed, { title: 'Autres choix', items: actions.filter((a) => !known.has(a)) }].filter((g) => g.items.length)
})
</script>

<template>
  <section v-if="store.pending" class="actions">
    <h3>À toi de jouer — J{{ store.pending.player }}</h3>
    <p class="muted">
      Tour {{ store.pending.turn }}, {{ phaseLabel(store.pending.phase)
      }}{{
        store.pending.declaring === 'attackers'
          ? ' — déclaration des attaquants'
          : store.pending.declaring === 'blockers'
            ? ' — déclaration des bloqueurs'
            : store.pending.declaring === 'damage'
              ? ' — répartition des dégâts'
              : ''
      }}
      · pile : {{ store.pending.stack.length }}
    </p>
    <div v-if="store.pickedTargets.length" class="target-pick" data-testid="target-pick">
      <h4>Cible choisie : {{ store.pickedTargets.map(target).join(', ') }}</h4>
      <p class="muted">Clique une autre cible sur le plateau, ou choisis l'action :</p>
      <button v-for="a in store.pickCandidates" :key="a.id" :disabled="store.loading" @click="store.decide(a.id)">{{ a.label }}</button>
      <button :disabled="store.loading" @click="store.cancelTarget()">Annuler</button>
    </div>
    <div v-for="g in groups" :key="g.title">
      <h4>{{ g.title }}</h4>
      <button
        v-for="a in g.items"
        :key="a.id"
        :class="{ 'ability-btn': a.type === 'ability', 'mana-ability': a.type === 'ability' && a.mana === true, confirm: a.type === 'pass' && confirmPass }"
        :disabled="store.loading"
        @click="act(a)"
      >
        {{ a.type === 'pass' && confirmPass ? "Confirmer : passer alors que la pile n'est pas vide" : a.label }}
      </button>
    </div>
  </section>
</template>
