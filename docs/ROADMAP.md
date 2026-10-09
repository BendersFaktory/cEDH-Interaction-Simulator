# Roadmap

> Dernière mise à jour : 2026-10-10 (pioche du tour 1 vérifiée). Ne cocher `[x]` qu'une fois la vérification réellement passée (voir « Roadmap (à tenir à jour) » dans `CLAUDE.md`).

## 0. Valider le squelette (premier jour)

- [x] Intégrer dans une app Symfony (`docs/SYMFONY_SETUP.md`), créer les migrations. *(Symfony 7.4 + Flex, base MySQL 9.3, migrations versionnées, `doctrine:schema:validate` et `lint:container` OK, scénario `curl` création/décision/fork vérifié.)*
- [x] Lancer `vendor/bin/phpunit` avec le vrai PHPUnit (les tests n'ont tourné qu'avec un mini-runner). *(PHPUnit 11.5 : 35 tests, 182 assertions.)*
- [x] `cd frontend && npm install && npm run typecheck && npm test` ; corriger les erreurs de compilation éventuelles (le front n'a jamais été compilé). *(vitest 4 tests, `vue-tsc` et `vite build` OK.)*
- [x] Essayer un fork complet de bout en bout dans l'UI. *(Vérifié le 2026-10-09 dans Chrome (Playwright, `channel: chrome`) contre l'API réelle : partie J0 humaine (terrain + passes), navigation/lecture, partie 4 IA jusqu'à « Victoire de J3 » (4935 événements à l'époque, 9861 depuis les phases complètes), fork depuis #1196 en reprenant J1 (fork #17 : décision possible, journal poursuivi), chargement d'une partie existante, erreur 404 affichée proprement. Aucune erreur console hors le 404 attendu. Script désormais versionné : `npm run recette`.)*
- [x] Parcours versionné : `cd frontend && npm run recette` (Playwright + Chrome, démarre API et Vite si besoin, captures dans `frontend/recette/`, ignoré par git et vidé à chaque exécution). *(Vérifié le 2026-10-09 : tous les scénarios passent, aucun problème inattendu, serveurs arrêtés en fin de script.)*
- [x] Lancer la recette en CI. *(`.github/workflows/recette.yml` : MySQL 9.3 en service, Chrome préinstallé, captures en artefact. Vérifié le 2026-10-09 : job vert sur la PR #3. Deux pièges corrigés en route : le serveur PHP intégré ignore `DATABASE_URL` sans `variables_order=EGPCS`, et le script lisait le total d'événements de la partie précédente avant le chargement de la nouvelle.)*
- [x] Ajouter des tests Symfony (`WebTestCase`) pour : création, fork, décision illégale (422), décision sans attente (409). *(`backend/tests/Api/SimulationApiTest.php`, 11 tests.)*

## 1. Règles du jeu

- [x] **Phases complètes** : untap, upkeep, pioche, main 1, début/fin de combat, main 2, fin, cleanup ; untap et cleanup sans priorité. *(Vérifié le 2026-10-10 : `smoke.php 42` déterministe (9861 événements, 1 terrain/tour, ordre des étapes correct), PHPUnit 45 tests dont `PhasesTest` (6) et `StateReconstructorTest` inchangé, `vue-tsc` + vitest, `npm run recette` sans problème.)*
  - [x] Étapes déclaration d'attaquants / bloqueurs / dégâts (entre `BeginCombat` et `EndCombat`) : faites avec le combat, sautées sans attaquant.
  - [x] Défausse à 7 cartes au cleanup (décision hors priorité du joueur actif, `Discard` / `CardDiscarded`, `HandSizeManager`). *(Vérifié le 2026-10-10 : PHPUnit 63 tests dont `DiscardTest` (7), `smoke.php 42` déterministe, une défausse observée sur les graines 6, 16 et 22 ; `vue-tsc` + vitest 7 tests ; `npm run recette` sans problème. La partie seed 42 ne comporte aucune défausse : le reducer est couvert par un test dédié.)*
  - [ ] Choix de défausse de l'IA plus fin (garder les contresorts, mana) : §3.
  - [ ] Mesurer à nouveau taille en base / latence (`docs/SYMFONY_SETUP.md`) : ~2× plus d'événements et de rondes par partie.
  - [x] Le tour 1 pioche : règle Commander exacte (CR 103.8, multijoueur : personne ne saute la pioche). *(Vérifié le 2026-10-10 : `PhasesTest::testEveryPlayerDrawsOnTheirFirstTurn`, PHPUnit 64 tests, `smoke.php 42` inchangé : 3294 événements.)*
- [x] **Combat à 4 joueurs** : déclarer attaquants (vers un joueur précis) et bloqueurs = décisions séquentielles pour `human` ; force/endurance, mal d'invocation, `CombatManager` + `CombatResolver`, mort des créatures (SBA), commandant mort → zone de commandement. *(Vérifié le 2026-10-10 : `smoke.php 42` déterministe (3294 événements, partie finie au tour 38, 28 morts de créatures), 12 graines : commandants morts, relances avec taxe, 2 éliminations par `commander_damage` ; PHPUnit 56 tests dont `CombatTest` (11) ; `StateReconstructorTest` vert avec pauses humaines pendant déclaration ; `vue-tsc` + vitest 6 tests ; `npm run recette` : J0 déclare une attaque (scénario 8).)*
  - [ ] IA d'attaque / blocage fine (menace, ne pas envoyer le commandant au casse-pipe, blocage multiple) : §3.
  - [ ] Mots-clés (vol, initiative, piétinement, célérité...) et ordre d'assignation des dégâts choisi par l'attaquant.
  - [ ] Stops configurables : `human:auto` s'arrête à chaque déclaration possible (§4).
  - [ ] Mesurer taille en base / latence avec le combat (parties ~3× plus courtes en événements).
- [ ] **Mana** : couleurs, pool, capacités de mana (ne passent pas par la pile), coûts hybrides/X.
- [ ] **Déclencheurs** : file d'attente dans `GameState`, ordre APNAP, ciblage, `TriggerManager`.
- [ ] **Capacités activées** (action légale + pile).
- [ ] **Actions basées sur l'état** : ~~créatures létales~~ (faites avec le combat), légendes, jetons...
- [x] Format **Commander à 4** (tranché) : 40 PV, zone de commandement, taxe de commandant, retour en zone de commandement, seuil de 21 dégâts de commandant (SBA).
- [x] Commander : **dégâts de commandant** (via le combat, vérifié ci-dessus).
- [ ] Commander : mulligan, ordre du tour aléatoire, identité de couleurs, decks de 100 cartes.

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
