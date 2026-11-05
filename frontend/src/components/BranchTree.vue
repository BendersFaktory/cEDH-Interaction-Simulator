<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { flattenBranches } from '../engine/branches'
import { useSimulationStore } from '../stores/simulation'
import type { BranchNode } from '../types/engine'

const store = useSimulationStore()
const rows = computed(() => flattenBranches(store.branchTree))
const byId = computed(() => new Map((store.branchTree?.branches ?? []).map((b) => [b.id, b])))
/** Branches cochées pour comparaison : deux au plus (la plus ancienne sort quand on en coche une troisième). */
const compare = ref<number[]>([])
const pair = computed<[BranchNode, BranchNode] | null>(() => {
  if (compare.value.length !== 2) return null
  const [a, b] = compare.value.map((id) => byId.value.get(id))
  return a && b ? [a, b] : null
})

// L'arbre change à chaque bifurcation et à chaque décision (le nombre d'événements grandit) : on le relit.
watch(
  () => [store.simulation?.id, store.simulation?.eventCount],
  () => store.refreshBranches(),
  { immediate: true },
)

function toggle(id: number): void {
  compare.value = compare.value.includes(id) ? compare.value.filter((x) => x !== id) : [...compare.value, id].slice(-2)
}

const humans = (node: BranchNode) => (node.humans.length ? node.humans.map((id) => `J${id}`).join(' ') : 'aucun humain')
const gap = (a: BranchNode, b: BranchNode) => {
  const d = b.eventCount - a.eventCount
  return d === 0 ? 'même longueur' : `${d > 0 ? '+' : ''}${d} événement(s)`
}
</script>

<template>
  <section v-if="store.branchTree" class="branches">
    <h3>Branches</h3>
    <p class="muted">{{ rows.length }} branche(s) dans l'arbre. Coche deux branches pour les comparer.</p>
    <ul class="branch-list">
      <li v-for="{ node, depth } in rows" :key="node.id" :class="{ current: node.id === store.branchTree.current }" :style="{ paddingLeft: `${depth * 16}px` }" :data-testid="`branch-${node.id}`">
        <input type="checkbox" :checked="compare.includes(node.id)" :data-testid="`branch-compare-${node.id}`" @change="toggle(node.id)" />
        <strong>#{{ node.id }}</strong>
        <span class="muted">{{ node.parentId === null ? 'racine' : `fork à #${node.forkEventSeq}` }}</span>
        <span>{{ humans(node) }}</span>
        <span class="muted">{{ node.status }}</span>
        <button :disabled="node.id === store.branchTree.current || store.loading" @click="store.load(node.id, true)">Ouvrir</button>
      </li>
    </ul>

    <table v-if="pair" class="compare" data-testid="branch-compare-table">
      <thead>
        <tr>
          <th></th>
          <th>#{{ pair[0].id }}</th>
          <th>#{{ pair[1].id }}</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <th>Statut</th>
          <td>{{ pair[0].status }}</td>
          <td>{{ pair[1].status }}</td>
        </tr>
        <tr>
          <th>Bifurcation</th>
          <td>{{ pair[0].parentId === null ? 'racine' : `#${pair[0].forkEventSeq}` }}</td>
          <td>{{ pair[1].parentId === null ? 'racine' : `#${pair[1].forkEventSeq}` }}</td>
        </tr>
        <tr>
          <th>Humains</th>
          <td>{{ humans(pair[0]) }}</td>
          <td>{{ humans(pair[1]) }}</td>
        </tr>
        <tr>
          <th>Événements</th>
          <td>{{ pair[0].eventCount }}</td>
          <td>
            {{ pair[1].eventCount }} <span class="muted">({{ gap(pair[0], pair[1]) }})</span>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.branch-list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.branch-list li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  padding-block: 0.2rem;
}
.branch-list li.current {
  font-weight: bold;
}
.compare {
  margin-top: 0.5rem;
  border-collapse: collapse;
}
.compare th,
.compare td {
  padding: 0.2rem 0.6rem;
  text-align: left;
}
</style>
