# Roadmap

> Dernière mise à jour : 2026-10-09 (checkpoints épars vérifiés). Ne cocher `[x]` qu'une fois la vérification réellement passée (voir « Roadmap (à tenir à jour) » dans `CLAUDE.md`).

## 0. Valider le squelette (premier jour)

- [x] Intégrer dans une app Symfony (`docs/SYMFONY_SETUP.md`), créer les migrations. *(Symfony 7.4 + Flex, base MySQL 9.3, migrations versionnées, `doctrine:schema:validate` et `lint:container` OK, scénario `curl` création/décision/fork vérifié.)*
- [x] Lancer `vendor/bin/phpunit` avec le vrai PHPUnit (les tests n'ont tourné qu'avec un mini-runner). *(PHPUnit 11.5 : 35 tests, 182 assertions.)*
- [x] `cd frontend && npm install && npm run typecheck && npm test` ; corriger les erreurs de compilation éventuelles (le front n'a jamais été compilé). *(vitest 4 tests, `vue-tsc` et `vite build` OK.)*
- [x] Essayer un fork complet de bout en bout dans l'UI. *(Vérifié le 2026-10-09 dans Chrome (Playwright, `channel: chrome`) contre l'API réelle : partie J0 humaine (terrain + passes), navigation/lecture, partie 4 IA jusqu'à « Victoire de J3 » (4935 événements), fork depuis #1196 en reprenant J1 (fork #17 : décision possible, journal poursuivi), chargement d'une partie existante, erreur 404 affichée proprement. Aucune erreur console hors le 404 attendu. Script désormais versionné : `npm run recette`.)*
- [x] Parcours versionné : `cd frontend && npm run recette` (Playwright + Chrome, démarre API et Vite si besoin, captures dans `frontend/recette/`, ignoré par git et vidé à chaque exécution). *(Vérifié le 2026-10-09 : tous les scénarios passent, aucun problème inattendu, serveurs arrêtés en fin de script.)*
- [x] Lancer la recette en CI. *(`.github/workflows/recette.yml` : MySQL 9.3 en service, Chrome préinstallé, captures en artefact. Vérifié le 2026-10-09 : job vert sur la PR #3. Deux pièges corrigés en route : le serveur PHP intégré ignore `DATABASE_URL` sans `variables_order=EGPCS`, et le script lisait le total d'événements de la partie précédente avant le chargement de la nouvelle.)*
- [x] Ajouter des tests Symfony (`WebTestCase`) pour : création, fork, décision illégale (422), décision sans attente (409). *(`backend/tests/Api/SimulationApiTest.php`, 11 tests.)*

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

- [x] Colonnes `json_text` (LONGTEXT) + insertions par lots (`BulkInserter`) : création d'une partie 4 IA de ~1,9 s à ~0,7 s (MySQL).
- [x] **Checkpoints épars + re-simulation déterministe** *(vérifié le 2026-10-09 : 39 tests PHPUnit verts, `schema:validate` OK, `npm run recette` sans problème ; 897 → 130 lignes, 28,5 → 2,6 Mo, `POST` ~0,68 → ~0,2 s, `fork` ~20-25 ms, mesures dans `docs/SYMFONY_SETUP.md`)* :
  - [x] Listener applicatif `SparseCheckpoints` : ancre au changement de tour (paramètre `app.checkpoint_every_n_turns`, N = 1) ; le moteur et les flags `checkpoint` des événements restent inchangés.
  - [x] Ancre forcée à chaque pause humaine + colonne `Checkpoint.decision` (`actionId`) renseignée par `SimulationService::decide()`.
  - [x] Ancre au point de bifurcation d'un fork (état avec `controllers` surchargés).
  - [x] `StateReconstructor::at($sim, $seq)` : ancre ≤ seq, `fromArray`, `advance` avec arrêt (`ReplayStop`) ; remontée chez l'ancêtre. *Écart avec le plan initial : un `seq` hors checkpoint renvoie le checkpoint précédent (comportement historique) au lieu d'un 409 ; 409 seulement s'il n'y a aucun checkpoint avant.*
  - [x] `fork()` et `stateAt()` branchés sur le reconstructeur ; plus d'écriture d'un snapshot par priorité.
  - [x] **Test d'acceptation décisif** (`tests/Service/StateReconstructorTest.php`) : pour chaque checkpoint d'une partie 4 IA (seed 42), d'une partie avec 80 décisions humaines et d'un fork de fork, l'état reconstruit est **identique** (JSON de `toArray()`, RNG compris) au snapshot dense (`InMemoryCheckpoints` en référence).
  - [x] Tests d'API existants toujours verts ; fork depuis une branche déjà forkée couvert par le test d'acceptation.
  - [x] Mesures : taille en base, durée de `POST`, latence `fork` / `state` ; N = 1 conservé (reconstruction ≤ un tour, quelques ms).
  - [x] Documenté dans `docs/ARCHITECTURE.md` et `docs/SYMFONY_SETUP.md` ; TODO de `Checkpoint.php` supprimé.
  - *Risques restants* : une évolution du format de `GameState` invalide les ancres anciennes (versionner l'état) ; en dev, recréer les parties créées avant cette migration.
- [ ] Exécution asynchrone des grosses simulations (Symfony Messenger) + progression.
- [ ] Tests de propriété sur le moteur : invariants (nombre total de cartes constant, pile vide en fin d'étape, déterminisme sur N seeds).
- [ ] CI : phpunit + phpstan + `vue-tsc` + `vitest`.
- [ ] Vérifier que la fixture du front est régénérée en CI (le test de contrat doit casser si le format d'événements dérive).

### Acquis d'environnement (faits et vérifiés le 2026-10-09)

- [x] Dépôt hors iCloud (`~/GitHub/cEDH-Interaction-Simulator`) : iCloud corrompait `vendor/` et `.git` sous `~/Documents`.
- [x] Symfony Flex installé (famille Symfony épinglée en `7.4.*`) ; recettes nettoyées.
- [x] Base MySQL 9.3 à la place de SQLite (mot de passe dans `.env.local` / `.env.test.local`, non versionnés).
- [x] Workflow Git `dev` → `staging` (PR à chaque commit), `master` réservée à l'utilisateur.
