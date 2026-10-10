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
| `SpellCast` | non | `player`, `card`, `stackId`, `targets`, `tapped: [id]` (un Treasure engagé y figure, son `PermanentSacrificed` suit), `cost` (valeur de mana payée, taxe et X inclus), `manaCost` (coût canonique, taxe incluse, ex. `{X}{R}`), `x` (valeur de {X}, 0 sinon), `paid` (mana produit par les capacités de mana activées pour payer, ex. `{"R":1,"C":2}`), `fromPool` (mana pris dans la réserve existante, même forme), `pool` (réserve restante après paiement, valeurs absolues), `fromCommand` (bool) | Main **ou zone de commandement** → pile, paie (réserve d'abord, puis capacités de mana des permanents non engagés ; `tapped` = permanents engagés), remet `passed` à zéro. Le surplus de mana reste dans la réserve |
| `ManaAbilityActivated` | **oui** (son `PermanentSacrificed` éventuel ne l'est pas) | `player`, `card` (public, source), `abilityIndex`, `ability` (sérialisée), `option` (alternative produite), `mana` (produit, ex. `{"C":2}`), `tapped: [id]` (la source si elle s'engage, puis les sources engagées pour payer son coût de mana, ex. Signet), `pool` (réserve après, absolue) | Capacité de mana activée à la main (jamais sur la pile, résolue sur-le-champ, la priorité reste au joueur, aucune passe remise à zéro) : paie son coût éventuel, engage la source, ajoute à la réserve |
| `AbilityActivated` | non | `player`, `stackId`, `card` (public, source), `abilityIndex`, `ability`, `targets`, `tapped: [id]` | Capacité activée non-mana → pile (la source reste sur le champ de bataille), remet `passed` à zéro |
| `AbilityResolved` | non | `stackId`, `controller`, `card` (public, source), `abilityIndex` | Retire l'élément de pile de la capacité (ses effets suivent : `DamageDealt`, `ManaAdded`...) |
| `ManaAdded` | non | `player`, `mana` (ajouté), `pool` (réserve après, absolue), `source` (`stackId` du rituel) | Un sort `effect 'mana'` (rituel) se résout et ajoute à la réserve |
| `ManaPoolEmptied` | non | `player`, `lost` (mana perdu), `pool` (`{}`) | Fin d'étape (règle 500.4) : réserve non vide vidée |
| `SpellResolved` | non | `stackId`, `card`, `controller`, `destination` (`battlefield`\|`graveyard`\|`command`) | Pile → destination |
| `SpellCountered` | non | `stackId`, `card`, `controller`, `by` (stackId du contresort), `destination` (`graveyard`\|`command`) | Pile → cimetière, ou zone de commandement si c'est un commandant |
| `SpellFizzled` | non | `target` | Aucun (cible devenue illégale) |
| `TokenCreated` | non | `player`, `card` (public, `token: true`) | Ajoute le jeton au champ de bataille du joueur (ex. Treasure créé par Strike It Rich) |
| `PermanentSacrificed` | non | `player`, `card`, `destination` (`graveyard`\|`command`\|`none`) | Champ de bataille → destination ; `none` = jeton, il cesse d'exister. Émis juste après l'événement dont il paie le coût (`SpellCast`, `ManaAbilityActivated`, `AbilityActivated`) |
| `CardDiscarded` | non | `player`, `card`, `destination` (`graveyard`\|`command`), `hand` (taille de main résultante) | Main → cimetière (défausse à 7 au cleanup, joueur actif) |
| `AttackerDeclared` | non | `player`, `card` (id), `defender` (joueur attaqué), `tapped` (true) | La créature attaque `defender` et s'engage |
| `BlockerDeclared` | non | `player`, `blocker` (id), `attacker` (id) | La créature bloque l'attaquant |
| `DeclarationFinished` | **oui** | `player`, `step` (`attackers`\|`blockers`) | Aucun (le déclarant a terminé ; émis seulement s'il avait encore un choix) |
| `DamageDealt` | non | `source`, `target`, `amount`, `life` (PV résultants), `combat` (bool, présent pour le combat), `commanderDamage: {key, total}` (si l'attaquant est un commandant) | PV du joueur ciblé ; `commanderDamage[key] = total` |
| `CreatureDamaged` | non | `card`, `amount`, `damage` (marqués au total) | Aucun (les dégâts marqués sont effacés au cleanup) |
| `CreatureDied` | non | `player`, `card`, `destination` (`graveyard`\|`command`\|`none` pour un jeton) | Champ de bataille → cimetière, ou zone de commandement pour un commandant |
| `CombatEnded` | non | – | Efface `attacking` / `blocking` des permanents (émis s'il y a eu des attaquants) |
| `PlayerLost` | non | `player`, `reason` (`life`\|`decked`\|`commander_damage`) | Joueur éliminé |
| `GameOver` | non | `winner` (id ou `null`), `reason` (`last_player_standing`\|`turn_limit`) | Fin de partie |

## Déclarations de combat

`AttackerDeclared` / `BlockerDeclared` ne sont **pas** des priorités : le déclarant occupe `priority.holder` de la `DecisionRequest` (champ `declaring` = `attackers` | `blockers`) et est réinterrogé après chaque choix jusqu'à l'action `done`. Les étapes `declare_attackers`, `declare_blockers` et `combat_damage` n'existent que s'il y a des attaquants ; chacune ouvre ensuite une ronde de priorité complète.

## Défausse au cleanup

Comme les déclarations de combat, la défausse n'est pas une priorité : le joueur actif dont la main dépasse 7 cartes occupe `priority.holder` de la `DecisionRequest` (phase `cleanup`, actions de type `discard`, une par nom de carte) et est réinterrogé jusqu'à 7 cartes. Aucun état n'est ajouté à `GameState`.

## Cartes publiques (`card`)

`toPublic()` d'une carte. `token: true` n'est présent que pour un jeton (clé omise sinon : au-delà de 16 clés par carte, PHP double la mémoire des tableaux d'état). Les capacités sérialisées portent `sacrifice` (bool) quand leur coût sacrifie la source.

## Ajouter un événement

Voir la checklist dans `CLAUDE.md` (moteur → ce fichier → reducer/describe → régénérer la fixture → `npm test`).

## Bruit

Une ronde de priorité à 4 joueurs produit au moins 4 `PriorityPassed` par action. Ils sont marqués `verbose: true`, **gardés dans le journal** (replay et fork en ont besoin) et masqués par défaut dans `EventLog`.
