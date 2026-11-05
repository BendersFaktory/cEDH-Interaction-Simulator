import { describe, expect, it } from 'vitest'
import { flattenBranches } from './branches'
import type { BranchNode, BranchTree } from '../types/engine'

const node = (id: number, parentId: number | null, humans: number[] = []): BranchNode => ({
  id,
  parentId,
  forkEventSeq: parentId === null ? null : 10,
  status: 'over',
  eventCount: 100,
  humans,
})

describe('flattenBranches', () => {
  it('renvoie une liste vide sans arbre', () => {
    expect(flattenBranches(null)).toEqual([])
  })

  it('place chaque fils sous son parent, avec sa profondeur', () => {
    const tree: BranchTree = { current: 4, branches: [node(1, null), node(2, 1, [1]), node(3, 1, [2]), node(4, 2, [1])] }
    const rows = flattenBranches(tree).map((r) => [r.node.id, r.depth])
    expect(rows).toEqual([
      [1, 0],
      [2, 1],
      [4, 2],
      [3, 1],
    ])
  })

  it('traite une branche sans parent connu comme une racine', () => {
    const tree: BranchTree = { current: 5, branches: [node(5, 99)] }
    expect(flattenBranches(tree).map((r) => [r.node.id, r.depth])).toEqual([[5, 0]])
  })
})
