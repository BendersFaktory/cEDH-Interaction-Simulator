/** Libellés d'affichage des étapes du tour (valeurs de `PhaseChanged.phase`). Aucune règle ici. */
const phaseLabels: Record<string, string> = {
  untap: 'Dégagement',
  upkeep: 'Entretien',
  draw: 'Pioche',
  main1: 'Principale 1',
  begin_combat: 'Début de combat',
  end_combat: 'Fin de combat',
  main2: 'Principale 2',
  end: 'Fin de tour',
  cleanup: 'Nettoyage',
}

export const phaseLabel = (phase: string): string => phaseLabels[phase] ?? phase
