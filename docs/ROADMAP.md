# Roadmap

> Dernière mise à jour : 2026-10-14 (triggers : attaque, fin de tour, sort lancé, défausse, ronde de cleanup 514.3a ; cartes de démo Young Pyromancer, Pillard des Cendres, Lanterne du Crépuscule, Cendrier des Regrets ; recette : scénarios 11 et 12 sur les graines 80 et 170). Antérieure : 2026-10-12 (moteur profilé et optimisé : `ManaContext`, solveur de paiement itératif, `ActivatedAbility::mana()` mémoïsée ; seed 16 1,14 → 0,44 s dans `bin/bench.php`, empreintes d'événements identiques ; mana conditionnel terminé : Eldrazi Temple / Eldrazi Mimic, sous-types rattachés à leur type, recette scénario 12 ; TODO cartes multi-types ajouté). Ne cocher `[x]` qu'une fois la vérification réellement passée (voir « Roadmap (à tenir à jour) » dans `CLAUDE.md`).

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
  - [x] Mesurer à nouveau taille en base / latence (`docs/SYMFONY_SETUP.md`). *(Vérifié le 2026-10-10 : `php backend/bin/measure.php` (graines 42, 6, 16) + `curl` ; seed 42 : 3294 événements, 38 ancres, 1,2 Mo en base, `create` ~0,11 s, `fork` ~13 ms, reconstruction ~3 ms, N = 1 conservé. L'hypothèse « ~2× plus d'événements » est fausse : les parties sont plus courtes avec le combat.)*
  - [x] Le tour 1 pioche : règle Commander exacte (CR 103.8, multijoueur : personne ne saute la pioche). *(Vérifié le 2026-10-10 : `PhasesTest::testEveryPlayerDrawsOnTheirFirstTurn`, PHPUnit 64 tests, `smoke.php 42` inchangé : 3294 événements.)*
- [x] **Combat à 4 joueurs** : déclarer attaquants (vers un joueur précis) et bloqueurs = décisions séquentielles pour `human` ; force/endurance, mal d'invocation, `CombatManager` + `CombatResolver`, mort des créatures (SBA), commandant mort → zone de commandement. *(Vérifié le 2026-10-10 : `smoke.php 42` déterministe (3294 événements, partie finie au tour 38, 28 morts de créatures), 12 graines : commandants morts, relances avec taxe, 2 éliminations par `commander_damage` ; PHPUnit 56 tests dont `CombatTest` (11) ; `StateReconstructorTest` vert avec pauses humaines pendant déclaration ; `vue-tsc` + vitest 6 tests ; `npm run recette` : J0 déclare une attaque (scénario 8).)*
  - [ ] Mots-clés (vol, initiative, piétinement, célérité...) et ordre d'assignation des dégâts choisi par l'attaquant.
  - [x] Mesurer taille en base / latence avec le combat. *(Couvert par la même mesure, 2026-10-10 : voir ci-dessus et `docs/SYMFONY_SETUP.md`.)*
- [x] **Mana** : couleurs, pool, capacités de mana (ne passent pas par la pile), coûts hybrides/X. *(Vérifié le 2026-10-10 : PHPUnit 78 tests dont `ManaPaymentTest` (10) et `ManaCastTest` (4), `smoke.php 42` déterministe (3642 événements), audit de 7 graines : 0 paiement incohérent sur 305 sorts (couleurs payées, `tapped` = coût, Fireball/hybrides/contresorts joués) ; `vitest` 10 tests, `vue-tsc` + `vite build`, `npm run recette` sans problème ; mesures refaites (`create` ~0,15 s, reconstruction 5-15 ms, ancres 1,28 Mo). Auto-paiement par `ManaPayment`, terrains de base seulement.)*
  - [x] Mana de capacités non-terrain (rituels, Sol Ring), terrains multicolores/non-basiques, actions de mana manuelles. *(Vérifié le 2026-10-10 : PHPUnit 99 tests dont `AbilityTest` (11) et `ManaPaymentTest` (20) ; `smoke.php 42` déterministe (3805 événements) ; audit de 7 graines : 348 sorts, 0 paiement incohérent (couleurs, montant), 77 capacités activées toutes résolues, 7 rituels résolus, aucun mana manuel de l'IA ; `vitest` 17 tests, `vue-tsc` + `vite build` ; `npm run recette` (scénario 9 : J0 engage une terre à la main, la réserve s'affiche). Capacité de mana = capacité activée avec `isManaAbility`, résolue immédiatement sans pile.)*
  - [x] Choix de paiement par l'humain (quels terrains engager) : activer ses sources à la main, la réserve est dépensée avant tout nouvel engagement. *(Vérifié : `AbilityTest::testManualManaIsSpentBeforeUntappedLands`, recette scénario 9.)*
- [x] **Triggers** : file d'attente dans `GameState`, ordre APNAP, ciblage, `TriggerManager`. *(Vérifié le 2026-10-11 : voir les sous-cases ; PHPUnit 131 tests, `smoke.php 42` déterministe, audit de 7 graines, vitest 22 tests, `npm run recette` scénario 11, mesures.)*
  - [x] Modèle et file d'attente : `TriggeredAbility` (`etb` / `dies` / `upkeep`, effets `damage` / `draw` / `token`), `CardInstance::triggers` (omis s'il est vide), `GameState::$pendingTriggers` sérialisée (`PendingTrigger` : source en information de dernière connaissance), `GameState::enterBattlefield()` seul chemin d'entrée en jeu (sort, terrain, jeton), mort par SBA et par sacrifice (jeton compris), entretien du joueur actif. *(Vérifié le 2026-10-11 : PHPUnit 124 tests dont `TriggerQueueTest` (8) ; `smoke.php 42` identique avant/après.)*
  - [x] Mise sur la pile et résolution : `TriggerManager` (APNAP, triggers d'un joueur éliminé retirés), action `PutTrigger` (ordre et cible ; décision hors priorité, détenteur gardé dans `triggerResumeHolder` ; aucune décision s'il n'y a qu'un choix, deux triggers identiques n'en font qu'un), passes remises à zéro, `AbilityTriggered` / `AbilityResolved` (`trigger: true`), effets partagés avec les capacités activées, agents (IA : adversaire le plus faible ; `ai:pass` : premier choix ; `human` : s'arrête). *(Vérifié le 2026-10-11 : PHPUnit 131 tests dont `TriggerTest` (7 : entrée en jeu, cible choisie par l'humain avec reprise depuis un état sérialisé, APNAP, ordre choisi, sacrifice comme coût, Counterspell, joueur éliminé) et `StateReconstructorTest` vert ; `smoke.php 42` identique.)*
  - [x] Triggers pendant le cleanup (règle 514.3a : une ronde de priorité s'ouvre) et autres événements (attaque, fin de tour, sorts lancés, défausse). *(Vérifié le 2026-10-14 : PHPUnit 158 tests OK (`CleanupRoundTest` : ronde de cleanup réelle sur la graine 122 en partie IA, reprise après `DecisionRequired` identique à un run continu ; `TriggerTest` : attaque, fin de tour, sort lancé, défausse) ; audit de 7 graines : sha1 identiques en double exécution, aucun trigger en attente à un checkpoint, 2 rondes de cleanup (graine 122) ; `smoke.php 42` identique en double exécution ; `vitest` 28 tests, `vue-tsc` et `vite build` OK ; `npm run recette` code de sortie 0. Les scénarios 11 et 12 de la recette ont été déplacés sur les graines 80 et 170 : les mains de départ ont changé avec les decks.)*
  - [ ] Pile non vide à la fin de partie : sur la seed 42, la partie se termine avec le trigger d'entretien de Idole de l'Érudit encore sur la pile. À trancher : vider la pile au `GameOver` ou laisser en l'état.
  - [ ] Recette : les scénarios 11 et 12 dépendent de graines précises (mains de départ) et cassent à chaque changement de deck ; les rendre indépendants d'une graine (deck construit ou état posé par l'API).
  - [x] Cartes de démo : Elvish Visionary (entrée : piocher 1, decks verts), Perilous Myr (mort : 2 dégâts à un joueur ciblé) et Idole de l'Érudit (factice, entretien : piocher 1) dans tous les decks ; un Lightning Bolt de moins dans les decks rouges (taille 39 conservée). Correctif au passage : l'objet mis sur la pile (capacité activée) ou en file (trigger) est une **copie** de la carte source ; partager l'objet du champ de bataille faisait diverger un état reconstruit d'un état réel (bonus de Wild Mongrel appliqué après l'activation, révélé par `StateReconstructorTest`). *(Vérifié le 2026-10-11 : PHPUnit 131 tests, `StateReconstructorTest` compris ; `smoke.php 42` déterministe (deux exécutions identiques, 4429 événements, 41 tours, J1 gagne ; test d'API mis à jour) ; audit de 7 graines : 65 triggers posés (Visionary 7, Idole 52, Myr 6), 64 résolus + 1 resté sur la pile à la fin de la partie, aucun trigger en attente à un checkpoint, Visionary : 1 trigger par entrée, Idole seulement à l'entretien de son contrôleur, Myr jamais sur soi, 1 Myr mort avec un joueur éliminé sans trigger, ordre APNAP respecté, effet attendu après chaque résolution.)*
  - [x] Front et docs : `AbilityTriggered` (reducer : élément de pile avec `trigger` et `label`, passes remises à zéro ; journal ; pile ; groupe « Triggers à mettre sur la pile » dans l'`ActionPanel`), `AbilityResolved.trigger`, types TS, `EVENTS.md` et `ARCHITECTURE.md`. *(Vérifié le 2026-10-11 : fixture seed 42 régénérée (11 `AbilityTriggered`), `vitest` 22 tests dont le test de contrat PHP ↔ reducer, `vue-tsc --noEmit` et `vite build`.)*
  - [x] Recette (J0 choisit la cible d'un trigger) et mesures. *(Vérifié le 2026-10-11 : `npm run recette`, scénario 11 (seed 11) : J0 lance Goblin Bombardment et Perilous Myr, sacrifie le Myr vers J1, puis choisit J2 dans « Triggers à mettre sur la pile » ; journal #402 « Trigger de Perilous Myr (J0) : Quand elle meurt : 2 dégâts → J2 », J2 à 38 PV sur la capture ; aucun problème inattendu. `bin/measure.php 42 6 16` : `create` 0,42-0,59 s, fork 25-51 ms, reconstruction ≤ 61 ms, ancres 1,8-2,3 Mo ; N = 1 conservé, détail dans `docs/SYMFONY_SETUP.md`.)*
- [ ] **Cibles invalides à la résolution (CR 608.2b)** : `SpellFizzled` ne doit concerner qu'un sort ou une capacité dont **toutes** les cibles sont devenues invalides (il est alors retiré sans effet). Si une partie seulement des cibles est invalide, le sort ou la capacité se résout et fait le maximum sur les cibles encore légales (les cibles invalides sont ignorées). Aujourd'hui `StackResolver::damage` / `counter` émettent `SpellFizzled` dès que l'unique cible lue est invalide : vérifier toutes les cibles avant la résolution, distinguer « retiré » de « résolu partiellement », et l'appliquer aux sorts, capacités activées et triggers. *Non prioritaire : toutes les cartes actuelles ont une seule cible ; à traiter avec la première carte multi-cibles.*
- [x] **Mana conditionnel** (réserve à restrictions, ex. Eldrazi Temple). `ManaRestriction` (`kind`, `allows($carte payée)`) ; `ManaPool` : mana libre + partie restreinte par restriction (`poolRestricted` dans l'état, `restricted` dans les événements, omis si vide), vidée en fin d'étape ; option de capacité restreinte écrite `{C}{C}@eldrazi`, son mana rangé dans la partie restreinte par `activateManaSource` ; `ManaPayment::plan(..., $for)` : le mana restreint admis paie le coût demandé avant le libre, jamais le coût d'un Signet, option restreinte candidate seulement sans convertisseur. **Sous-types rattachés à leur type** (CR 205.3) : catalogue `Card/Subtypes`, `CardInstance::subtypes` = `type → sous-types` validé à la construction (un artefact Eldrazi ou une créature Aura lèvent une exception), `hasSubtype()`, `isColorless()`. Cartes : Eldrazi Temple (remplace un terrain de base), Eldrazi Mimic (remplace un Hybrid Bear ; copie de force/endurance non modélisée), Treasure sous-typé. *(Vérifié le 2026-10-12 : PHPUnit 147 tests dont `SubtypesTest` et les nouveaux cas de `ManaPaymentTest` / `AbilityTest` ; `smoke.php 42` déterministe (deux exécutions identiques, 2975 événements, 30 tours, J2 gagne ; test d'API mis à jour) ; audit de 7 graines par l'agent d'implémentation : 0 paiement incohérent, 0 mana restreint dépensé pour un sort non admissible, Temple 2 fois en {C}{C} / 55 en {C}, Mimic 14 lancés ; fixture régénérée, vitest 25 tests, `vue-tsc` + `vite build` ; `npm run recette` sans problème inattendu, nouveau scénario 12 (seed 225 : J0 engage Eldrazi Temple pour {C}{C} restreint, deux pastilles marquées dans la réserve, lance Eldrazi Mimic, réserve restreinte vide ensuite) ; scénarios 10 et 11 déplacés sur les graines 18 et 161 (les mains de départ ont changé avec les decks) ; mesures refaites, voir `docs/SYMFONY_SETUP.md`.)*
- [x] **Capacités activées** : socle. `ActivatedAbility` (coût `{T}`/mana, effet `mana` ou `damage`, `isManaAbility`), action `ActivateAbility`, `AbilityManager` (mana : résolution immédiate ; autre : pile, `AbilityActivated`/`AbilityResolved`), contresort qui ne cible que des sorts. *(Vérifié le 2026-10-10 : voir ci-dessus ; Prodigal Pinger activé et résolu via la pile par l'IA.)*
  - [x] Créatures à mana (Llanowar Elves) : mal d'invocation respecté par les actions **et** par l'auto-paiement (`AbilityManager::sourceReady`, partagé avec `ManaPayment`). *(Vérifié le 2026-10-10 : PHPUnit 101 tests dont 2 nouveaux dans `AbilityTest` ; `smoke.php 42` déterministe (deux exécutions identiques, 3805 événements) ; audit de 7 graines : 10 elfes résolues, 17 engagements pour payer, 0 pendant le mal d'invocation, 0 attaque ; `vitest` 17 tests, `vue-tsc` + `vite build`.)*
  - [x] Capacités de mana avec coût de mana (Signets) : paiement manuel (coût payé par les autres sources, jamais par le Signet lui-même) et auto-paiement (« convertisseurs » de `ManaPayment`, au plus 3, sans chaînage Signet → Signet ; utilisés seulement s'ils font strictement mieux). *(Vérifié le 2026-10-10 : PHPUnit 107 tests dont 5 nouveaux dans `ManaPaymentTest` et 1 dans `AbilityTest` ; `smoke.php 42` déterministe (deux exécutions identiques, 3457 événements, test d'API mis à jour) ; audit de 7 graines : 316 sorts, 0 paiement incohérent (mana dépensé = coût + 1 par Signet, couleurs couvertes), 11 Signets résolus, 24 utilisations par l'auto-paiement ; `vitest` 17 tests, `vue-tsc` + `vite build`.)*
  - [x] Treasure : jetons (`CardInstance::token`, `sendToGraveyard` → `none`), sacrifice de la source comme coût (`GameState::sacrifice()`, `PermanentSacrificed`), sort Strike It Rich (`TokenCreated`) ; l'auto-paiement ne sacrifie qu'en dernier recours (critère « sacrifices » avant « engagements »). *(Vérifié le 2026-10-10 : PHPUnit 110 tests dont 3 nouveaux dans `AbilityTest` ; `smoke.php 42` déterministe (deux exécutions identiques, 3655 événements, test d'API mis à jour) ; audit de 7 graines : 351 sorts, 0 paiement incohérent, 17 Treasures créés = 13 sacrifiés + 4 en jeu en fin de partie, 0 jeton dans une autre zone ; `vitest` 19 tests (fixture avec Treasures), `vue-tsc` + `vite build` ; `npm run recette` sans problème inattendu.)*
  - [x] Coûts d'activation riches (mana + `{T}`, sacrifice de la source ou d'une créature, défausse : choix portés par l'action, `costChoices`), effets variés (pioche, jetons créature, bonus jusqu'à la fin du tour remis à zéro au cleanup), restriction de timing (`sorcerySpeed`), terrain à capacité (Kher Keep). Cartes : Mind Stone, Goblin Bombardment, Wild Mongrel, Kher Keep, Tome de l'Archiviste (factice). *(Vérifié le 2026-10-10 : PHPUnit 116 tests dont `ActivatedCostsTest` (6) ; `smoke.php 42` déterministe (deux exécutions identiques, 4751 événements, test d'API mis à jour) ; audit de 7 graines : 383 sorts, 0 paiement incohérent, Kher Keep 42 activations, Tome 35, Bombardment 31 (que des jetons, jamais le commandant), Mind Stone 4, Wild Mongrel 3 (que des terrains défaussés), timing de rituel toujours respecté, tous les bonus remis à zéro ; `vitest` 21 tests, `vue-tsc` + `vite build` ; `npm run recette` sans problème inattendu.)*
  - [x] Recette : scénario où J0 active une capacité à choix de coût. *(Vérifié le 2026-10-10 : `npm run recette`, scénario 10 (seed 7) : J0 lance Goblin Bombardment, l'active vers J1 en sacrifiant Llanowar Elves ; journal #392-#403 contrôlé sur la capture (activation, « J0 sacrifie Llanowar Elves », résolution, J1 à 39 PV) ; aucun problème inattendu.)*
  - [x] Mesures refaites après les capacités activées. *(Vérifié le 2026-10-10 : `bin/measure.php 42 6 16` ; `create` 0,36-0,45 s, fork 34-50 ms, reconstruction ≤ 27 ms, ancres ~2 Mo ; détail et causes dans `docs/SYMFONY_SETUP.md`. N = 1 conservé.)*
- [ ] **Actions basées sur l'état** : ~~créatures létales~~ (faites avec le combat), légendes, jetons...
- [x] Format **Commander à 4** (tranché) : 40 PV, zone de commandement, taxe de commandant, retour en zone de commandement, seuil de 21 dégâts de commandant (SBA).
- [x] Commander : **dégâts de commandant** (via le combat, vérifié ci-dessus).
- [ ] Commander : mulligan, ordre du tour aléatoire, identité de couleurs, decks de 100 cartes.

## 2. Cartes

- [ ] `CardDefinition` + `Ability` à la place des flags `damage`/`counter` de `CardInstance`.
- [ ] Cartes à plusieurs types (créature-artefact, terrain-créature…) : `CardInstance::type` est une chaîne unique ; les sous-types sont déjà rangés par type (`['artifact' => [], 'creature' => ['Golem']]`), il reste à rendre `type` multiple et à adapter les tests de type du moteur.
- [ ] Un handler PHP par carte **ou** un DSL JSON/YAML pour les effets courants (dégâts, pioche, jeton, trigger d'entrée) + handlers pour les cas particuliers. Ne pas interpréter le texte Oracle automatiquement.
- [ ] `DeckLoader` : import d'une decklist, métadonnées + images via Scryfall, cache local en base (respecter les limites de l'API).
  - [ ] **Connecteur Scryfall** : interroger Scryfall à la demande au lieu d'héberger toute la base de cartes.
    - Récupération groupée avant la partie via `POST /cards/collection` (75 cartes max par requête : 4 decks de 100 ≈ 6 requêtes espacées de 100 ms, < 1 s ; moins après déduplication des terrains de base et des cartes communes).
    - Cache en base des seules cartes utilisées (métadonnées + URL d'images, rafraîchi au plus toutes les 24 h) : un processus PHP ne garde rien entre deux requêtes.
    - Respect des limites (~10 req/s, en-têtes `User-Agent` / `Accept` requis) ; fonctionnement dégradé si Scryfall est indisponible.
    - Le moteur reste sans I/O : les données de cartes lui sont injectées avant la partie.
- [ ] Afficher les arts des cartes dans `CardView` via Scryfall : URL d'images fournies par le connecteur, chargement paresseux, cache navigateur, image de repli si absente.

## 3. IA

- [ ] Évaluateur de menace (ne pas contrer n'importe quoi).
- [ ] Gestion du mana (garder de quoi contrer, jouer dans le bon ordre).
  - [ ] IA : ne pas gaspiller le surplus de mana (Sol Ring pour un coût impair : 17 mana perdus sur 348 sorts, 7 graines).
- [ ] Choix d'attaque / blocage fin (menace, ne pas envoyer le commandant au casse-pipe, blocage multiple).
- [ ] Choix de défausse plus fin (garder les contresorts, le mana).
- [ ] Plusieurs profils d'agents (`ai:aggro`, `ai:control`...) sélectionnables par joueur.

## 4. Fork & UX

- [ ] **Stops configurables** pour `human:auto` (par phase, par type de sort adverse) au lieu de « dès qu'une action existe ».
  - [ ] S'arrêter à chaque déclaration d'attaquants / bloqueurs possible.
- [ ] `TargetPicker` : choisir la cible en cliquant sur le plateau plutôt qu'une action par cible.
- [ ] `BranchTree` : arbre des branches pour naviguer entre scénarios et les comparer.
- [ ] Rendre la main à l'IA depuis une branche (bouton « reprendre la simulation automatique »).
- [ ] Raccourcis clavier pour le replay.

## 5. Backend

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
- [x] **Profiler le moteur** *(Vérifié le 2026-10-12 : SPX sur la seed 16 avant optimisation : `ManaPayment::plan` ≈ 75 % du temps (`cover` 412 K appels, `CardInstance::abilities` 171 K appels, `Color::fromSymbol` ~390 ms) ; corrigé par `ManaContext` (plateau scanné une fois par `LegalActionGenerator::for()`), `cover()` itératif avec bornes inférieures plus fortes (même plan choisi), `ActivatedAbility::mana()` mémoïsée, `Color::fromSymbol` en `match`. `php backend/bin/bench.php 1..20 42` : seed 16 1144 → 437 ms (178 → 68 µs par événement), seed 42 150 → 89 ms, seed 6 134 → 88 ms, 21 graines 7,15 → 3,69 s ; **21 empreintes sha1 du flux d'événements identiques** (seeds 1..20 + 42). Nœuds du solveur sur la seed 16 : 411 773 → 85 506. PHPUnit 150 tests OK (3 nouveaux dans `ManaPaymentTest`), `smoke.php 42` inchangé (30 tours, J2 gagne), vitest 25 tests (fixture non régénérée). MySQL (`bin/measure.php`) : `create` seed 42 ~0,22 s, seed 6 ~0,25 s, seed 16 ~0,70 s (était ~1,45 s) ; fork 11-66 ms ; reconstruction ≤ 32 ms. Réserve : `ManaPayment::NODE_LIMIT` (50 000) n'est jamais atteint (max 24 780 nœuds avant, 136 après), mais s'il l'était, le nouveau solveur peut choisir un autre plan : voir « Versionner le format de `GameState` ».)*
- [ ] Tests de propriété sur le moteur : invariants (nombre total de cartes constant, pile vide en fin d'étape, déterminisme sur N seeds).
- [ ] CI backend : phpunit + phpstan.
- [ ] Couverture de code PHPUnit : rapport + seuil minimal en CI.
- [ ] phpstan sur `src/` (niveau à fixer).
- [ ] Versionner le format de `GameState` dans les ancres (une évolution du format invalide les ancres anciennes).
  - *Note (2026-10-12)* : une partie qui atteint `ManaPayment::NODE_LIMIT` (50 000 nœuds) peut rejouer différemment depuis le profilage (solveur à moins de nœuds, plan éventuellement meilleur) ; une ancre issue d'une telle partie n'est donc pas garantie de rejouer à l'identique. À traiter avec ce versionnage.
- [ ] Mémoire des états : `CardInstance::toArray()` est à 16 clés ; une clé de plus double la table PHP (mesuré : pic de `smoke.php 42` de 93 à 156 Mo). `token` et `boost` sont donc omis à leur valeur par défaut. Avant d'ajouter des champs, regrouper les clés rares ou omettre les valeurs par défaut. `smoke.php` ne garde plus les états des checkpoints (il les compte) : la partie seed 42 dure maintenant 30 tours (depuis Eldrazi Temple / Mimic).
- [ ] Montées de version : PHPUnit 12, Symfony 8 quand Doctrine sera compatible (`var-exporter` 8), MySQL.
- [ ] `composer audit` et dépendances à jour.

### Acquis d'environnement (faits et vérifiés le 2026-10-09)

- [x] Dépôt hors iCloud (`~/GitHub/cEDH-Interaction-Simulator`) : iCloud corrompait `vendor/` et `.git` sous `~/Documents`.
- [x] Symfony Flex installé (famille Symfony épinglée en `7.4.*`) ; recettes nettoyées.
- [x] Base MySQL 9.3 à la place de SQLite (mot de passe dans `.env.local` / `.env.test.local`, non versionnés).
- [x] Workflow Git `dev` → `staging` (PR à chaque commit), `master` réservée à l'utilisateur.
- [x] README général (sans tâches restantes) + guide d'installation `docs/INSTALL.md` (2026-10-10 : suivi pas à pas vérifié — migration, `POST` 201 sur :8000, proxy Vite :5173 → 200, 99 tests PHP, 17 tests Vitest, build).

## 6. Frontend

### Technique

- [ ] CI front : `vue-tsc` + `vitest` + `vite build`.
- [ ] Vérifier que la fixture du front est régénérée en CI (le test de contrat doit casser si le format d'événements dérive).
- [ ] Couverture Vitest + tests de composants (Vue Test Utils).
- [ ] ESLint + Prettier.
- [ ] Montées de version : Vite 5, Vitest 2 et Pinia 2 vers les versions actuelles ; `npm audit`.

### Plateau

- [x] **Pile repositionnée au-dessus des joueurs** (entre l'en-tête et la grille dans `GameBoard.vue`, centrée ; collée en haut au défilement quand elle n'est pas vide, une ligne « Pile vide » sinon ; dessus de la pile mis en évidence). *(Vérifié le 2026-10-11 : `vue-tsc --noEmit`, vitest 24 tests, `vite build` ; captures Chrome d'une pile de 3 éléments avant et après défilement ; `npm run recette` sans problème inattendu, capture du scénario 11 contrôlée : la pile vide ne recouvre plus les joueurs.)*
- [ ] 4 sièges disposés autour d'une table, siège humain en bas.
- [ ] Zones distinctes par joueur : terrains / créatures / autres permanents, main, cimetière, exil, zone de commandement.
- [ ] Cartes engagées pivotées.
- [ ] PV, dégâts de commandant et réserve de mana bien visibles ; indicateurs joueur actif / priorité / a passé plus marqués.

### Cartes (affichage)

- [ ] Rendu lisible : symboles de mana, type, force/endurance, cadre coloré.
- [ ] Aperçu agrandi au survol.
- [ ] Mise en évidence des cartes jouables (d'après les actions légales envoyées par le serveur).

### Ergonomie

- [ ] Actions au clic sur la carte (menu) en plus de la liste.
- [ ] Confirmation avant de passer quand la pile n'est pas vide.
- [ ] Frise du tour : phases et checkpoints cliquables.
- [ ] Vitesse de lecture réglable.
- [ ] Journal filtrable (joueur, type) et cliquable pour sauter à l'événement.

### Graphisme

- [ ] Animations (pioche, pile, résolution, dégâts, mort), désactivables.
- [ ] Notifications (ex. « J2 contre ton sort »).
- [ ] Thème clair / sombre.
- [ ] Responsive (petits écrans).
- [ ] Accessibilité : contraste, navigation clavier, libellés.
