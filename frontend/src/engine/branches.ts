import type { BranchNode, BranchTree } from '../types/engine'

/** Une ligne de l'arbre : la branche et sa profondeur (0 = racine). */
export interface BranchRow {
  node: BranchNode
  depth: number
}

/**
 * Aplatit l'arbre en lignes, parents avant fils, dans l'ordre de l'API (racine d'abord, fils par id).
 * Une branche dont le parent n'est pas dans la liste est traitée comme une racine.
 */
export function flattenBranches(tree: BranchTree | null): BranchRow[] {
  if (!tree) return []
  const ids = new Set(tree.branches.map((b) => b.id))
  const children = new Map<number, BranchNode[]>()
  const roots: BranchNode[] = []
  for (const node of tree.branches) {
    if (node.parentId !== null && ids.has(node.parentId)) {
      children.set(node.parentId, [...(children.get(node.parentId) ?? []), node])
    } else {
      roots.push(node)
    }
  }
  const rows: BranchRow[] = []
  const visit = (node: BranchNode, depth: number) => {
    rows.push({ node, depth })
    for (const child of children.get(node.id) ?? []) visit(child, depth + 1)
  }
  roots.forEach((root) => visit(root, 0))
  return rows
}
