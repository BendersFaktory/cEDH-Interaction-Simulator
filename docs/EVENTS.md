# Catalogue d'événements

Chaque événement : `{ seq, type, payload, verbose }`. `seq` est croissant et unique le long d'une branche. Les payloads utilisent des **valeurs absolues** (PV résultants, etc.). Les cartes sont sérialisées en `PublicCard` : `{ id, name, kind, cost, effect, amount, commander, power, toughness }`. Les cibles sont des chaînes : `player:<id>` ou `stack:<stackId>`.

| Type | Verbeux | Payload | Effet sur l'état affiché |
|---|---|---|---|
| `GameStarted` | non | `seed`, `format` (`commander`), `players: [{id, life, library, controller, command: [PublicCard]}]` | Crée les joueurs et leur zone de commandement |
| `CardDrawn` | non | `player`, `card`, `library` (taille restante) | Carte en main, bibliothèque = `library` |
| `PhaseChanged` | non | `turn`, `phase` (`untap`\|`upkeep`\|`draw`\|`main1`\|`begin_combat`\|`declare_attackers`\|`declare_blockers`\|`combat_damage`\|`end_combat`\|`main2`\|`end`\|`cleanup`), `activePlayer` | Met à jour tour/phase, efface la priorité. `untap` et `cleanup` n'ouvrent aucune ronde de priorité |
| `PermanentsUntapped` | non | `player`, `cards: [id]` | Dégage les permanents listés |
| `PriorityGranted` | **oui** | `holder`, `passed: [id]`, `stackSize`, `checkpoint` (bool) | Détenteur de la priorité. **Point de bifurcation uniquement si `checkpoint` = true** (début de ronde ou pile non vide) |
| `PriorityPassed` | **oui** | `player` | Marque le joueur comme ayant passé |
| `LandPlayed` | non | `player`, `card` | Main → champ de bataille |
| `SpellCast` | non | `player`, `card`, `stackId`, `targets`, `tapped: [id]`, `cost` (taxe incluse), `fromCommand` (bool) | Main **ou zone de commandement** → pile, engage les terrains, remet `passed` à zéro |
| `SpellResolved` | non | `stackId`, `card`, `controller`, `destination` (`battlefield`\|`graveyard`\|`command`) | Pile → destination |
| `SpellCountered` | non | `stackId`, `card`, `controller`, `by` (stackId du contresort), `destination` (`graveyard`\|`command`) | Pile → cimetière, ou zone de commandement si c'est un commandant |
| `SpellFizzled` | non | `target` | Aucun (cible devenue illégale) |
| `AttackerDeclared` | non | `player`, `card` (id), `defender` (joueur attaqué), `tapped` (true) | La créature attaque `defender` et s'engage |
| `BlockerDeclared` | non | `player`, `blocker` (id), `attacker` (id) | La créature bloque l'attaquant |
| `DeclarationFinished` | **oui** | `player`, `step` (`attackers`\|`blockers`) | Aucun (le déclarant a terminé ; émis seulement s'il avait encore un choix) |
| `DamageDealt` | non | `source`, `target`, `amount`, `life` (PV résultants), `combat` (bool, présent pour le combat), `commanderDamage: {key, total}` (si l'attaquant est un commandant) | PV du joueur ciblé ; `commanderDamage[key] = total` |
| `CreatureDamaged` | non | `card`, `amount`, `damage` (marqués au total) | Aucun (les dégâts marqués sont effacés au cleanup) |
| `CreatureDied` | non | `player`, `card`, `destination` (`graveyard`\|`command`) | Champ de bataille → cimetière, ou zone de commandement pour un commandant |
| `CombatEnded` | non | – | Efface `attacking` / `blocking` des permanents (émis s'il y a eu des attaquants) |
| `PlayerLost` | non | `player`, `reason` (`life`\|`decked`\|`commander_damage`) | Joueur éliminé |
| `GameOver` | non | `winner` (id ou `null`), `reason` (`last_player_standing`\|`turn_limit`) | Fin de partie |

## Déclarations de combat

`AttackerDeclared` / `BlockerDeclared` ne sont **pas** des priorités : le déclarant occupe `priority.holder` de la `DecisionRequest` (champ `declaring` = `attackers` | `blockers`) et est réinterrogé après chaque choix jusqu'à l'action `done`. Les étapes `declare_attackers`, `declare_blockers` et `combat_damage` n'existent que s'il y a des attaquants ; chacune ouvre ensuite une ronde de priorité complète.

## Ajouter un événement

Voir la checklist dans `CLAUDE.md` (moteur → ce fichier → reducer/describe → régénérer la fixture → `npm test`).

## Bruit

Une ronde de priorité à 4 joueurs produit au moins 4 `PriorityPassed` par action. Ils sont marqués `verbose: true`, **gardés dans le journal** (replay et fork en ont besoin) et masqués par défaut dans `EventLog`.
