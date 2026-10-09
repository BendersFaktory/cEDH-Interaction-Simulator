# Roadmap

## 0. Valider le squelette (premier jour)

- [ ] Intégrer dans une app Symfony (`docs/SYMFONY_SETUP.md`), créer les migrations.
- [ ] Lancer `vendor/bin/phpunit` avec le vrai PHPUnit (les tests n'ont tourné qu'avec un mini-runner).
- [ ] `cd frontend && npm install && npm run typecheck && npm test` ; corriger les erreurs de compilation éventuelles (le front n'a jamais été compilé).
- [ ] Essayer un fork complet de bout en bout dans l'UI.
- [ ] Ajouter des tests Symfony (`WebTestCase`) pour : création, fork, décision illégale (422), décision sans attente (409).

## 1. Règles du jeu

- [ ] **Phases complètes** : untap, upkeep, 1ère main, combat (déclaration attaquants/bloqueurs, dégâts), 2e main, fin, cleanup. Étendre `Phase` + `TurnManager`.
- [ ] **Combat à 4 joueurs** (priorité n°1) : phases de combat, déclarer attaquants (vers un joueur précis) et bloqueurs = nouvelles décisions pour `human` ; force/endurance, summoning sickness, `CombatResolver`. **Alimente les dégâts de commandant.**
- [ ] **Mana** : couleurs, pool, capacités de mana (ne passent pas par la pile), coûts hybrides/X.
- [ ] **Déclencheurs** : file d'attente dans `GameState`, ordre APNAP, ciblage, `TriggerManager`.
- [ ] **Capacités activées** (action légale + pile).
- [ ] **Actions basées sur l'état** : créatures létales, légendes, jetons...
- [x] Format **Commander à 4** (tranché) : 40 PV, zone de commandement, taxe de commandant, retour en zone de commandement, seuil de 21 dégâts de commandant (SBA).
- [ ] Commander : **dégâts de commandant** (via le combat), mulligan, ordre du tour aléatoire, identité de couleurs, decks de 100 cartes.

## 2. Cartes

- [ ] `CardDefinition` + `Ability` à la place des flags `damage`/`counter` de `CardInstance`.
- [ ] Un handler PHP par carte **ou** un DSL JSON/YAML pour les effets courants (dégâts, pioche, jeton, déclencheur d'entrée) + handlers pour les cas particuliers. Ne pas interpréter le texte Oracle automatiquement.
- [ ] `DeckLoader` : import d'une decklist, métadonnées + images via Scryfall, cache local en base (respecter les limites de l'API).
- [ ] Afficher les images dans `CardView`.

## 3. IA

- [ ] Évaluateur de menace (ne pas contrer n'importe quoi).
- [ ] Gestion du mana (garder de quoi contrer, jouer dans le bon ordre).
- [ ] Choix d'attaque/blocage.
- [ ] Plusieurs profils d'agents (`ai:aggro`, `ai:control`...) sélectionnables par joueur.

## 4. Fork & UX

- [ ] **Stops configurables** pour `human:auto` (par phase, par type de sort adverse) au lieu de « dès qu'une action existe ».
- [ ] `TargetPicker` : choisir la cible en cliquant sur le plateau plutôt qu'une action par cible.
- [ ] `BranchTree` : arbre des branches pour naviguer entre scénarios et les comparer.
- [ ] Rendre la main à l'IA depuis une branche (bouton « reprendre la simulation automatique »).
- [ ] Raccourcis clavier pour le replay.

## 5. Technique

- [ ] Checkpoints : un par tour + re-simulation déterministe jusqu'au point voulu (au lieu d'un par priorité).
- [ ] Exécution asynchrone des grosses simulations (Symfony Messenger) + progression.
- [ ] Tests de propriété sur le moteur : invariants (nombre total de cartes constant, pile vide en fin d'étape, déterminisme sur N seeds).
- [ ] CI : phpunit + phpstan + `vue-tsc` + `vitest`.
- [ ] Vérifier que la fixture du front est régénérée en CI (le test de contrat doit casser si le format d'événements dérive).
