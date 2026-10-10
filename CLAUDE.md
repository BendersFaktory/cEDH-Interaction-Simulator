# MTG Sim — contexte pour le travail en commun

Simulateur Magic: The Gathering **Commander à 4 joueurs : l'utilisateur occupe un siège (J0 par défaut, agent `human:auto`) face à 3 IA**. Il peut mettre en pause, revenir à un point de priorité et **forker** pour tester une autre ligne de jeu. Option « 4 IA » pour juste regarder. Stack : Symfony (API) + moteur PHP pur + front Vue 3 / Pinia.

## Règles d'or (ne pas casser)

1. **Le moteur (`backend/src/Engine/`) est du PHP pur** : aucune dépendance Symfony/Doctrine. Testable seul.
2. **Déterminisme** : jamais de `rand()`, `random_int()`, `shuffle()`, `time()` dans `Engine/`. Tout l'aléa passe par `GameState::$rng` (dont l'état est sauvegardé dans les snapshots).
3. **Tout l'état est dans `GameState`** (`toArray()`/`fromArray()`). Pas d'état caché dans des services, singletons ou agents (les agents sont sans état).
4. **Le moteur ne saute JAMAIS une priorité.** Chaque joueur en vie est interrogé à chaque ronde. L'auto-pass est une politique de l'*agent* (`HumanAgent(autoPass: true)`), jamais un raccourci du moteur : les `PriorityPassed` doivent toujours être journalisés. C'est ce qui permet à l'IA de contrer juste après un fork.
5. **Un sort lancé remet `priority->passed` à zéro** et rend la priorité au lanceur ; la pile ne se résout que quand tous les joueurs en vie ont passé consécutivement. Les terrains ne déclenchent pas de ronde.
6. **On ne forke que sur un checkpoint** = un `PriorityGranted` dont le payload porte `checkpoint: true` (début de ronde, ou pile non vide). Jamais au milieu d'une résolution de pile. Les autres octrois n'ont pas de point de bifurcation ; le serveur recolle au checkpoint précédent. Seules des **ancres** sont stockées (1 par tour, + pause humaine, + fork) : les checkpoints intermédiaires se reconstruisent par rejeu (`StateReconstructor`).
10. **Capacités activées** : toute activation passe par `AbilityManager::activate()`. Une capacité de mana (`isManaAbility`) est résolue immédiatement, sans pile ni nouvelle ronde (comme `PlayLand`) ; les autres vont sur la pile. Ne jamais créer de mana ailleurs que par `PlayerState::activateManaSource`. Le pool persiste jusqu'à la fin de l'étape.
9. **Commander** : un commandant qui irait au cimetière (contré, détruit...) retourne en zone de commandement (`GameState::sendToGraveyard()` — toujours l'utiliser, jamais `->graveyard[] =` directement). La taxe est dans `PlayerState::castCost()` : toujours passer par elle pour un coût.
7. **Le front est « bête »** : il applique les événements (`src/engine/reducer.ts`), aucune règle de jeu. Les payloads d'événements portent des **valeurs absolues** (ex. PV résultants) pour que le reducer reste trivial.
8. **Ne jamais faire confiance au client** : `POST /decisions` envoie un `actionId`, le serveur recalcule les actions légales et retrouve l'action.

## Workflow Git (branches)

- `dev` : branche de travail. `staging` : intégration (cible des PR). `master` : **réservée à l'utilisateur**, gérée entièrement à la main. Ne jamais y committer, merger, pousser ni ouvrir de PR vers elle.
- **Avant toute modification de fichier**, vérifier la branche : `git branch --show-current`. Si ce n'est pas `dev`, faire `git switch dev` (si l'arbre est sale ou si la bascule échoue, s'arrêter et demander). Ne jamais modifier de fichier sur `master` ou `staging`.
- **À chaque commit sur `dev`** : pousser `dev` et créer en même temps une PR `dev` → `staging` (`gh pr create --base staging --head dev`). Si une PR `dev` → `staging` est déjà ouverte, le push l'alimente : ne pas en créer une seconde, le signaler à l'utilisateur.
- **Message de commit** : si l'utilisateur ne l'a pas fourni, le lui demander explicitement à chaque fois, avant de committer. Ne jamais l'inventer ni réutiliser un message précédent.
- **Commit de résolution de conflit** (fusion de `staging` dans `dev` pour résoudre les conflits d'une PR) : le message est toujours `Resolve merge conflict {hash du commit qui cause le conflit}` (hash court du commit de `staging` à l'origine du conflit, ex. `Resolve merge conflict 0923371`). Pas besoin de le demander : il se déduit du conflit.
- **Titre de la PR** : toujours `{message de commit} [staging]` (ex. commit « Création du workflow git » → PR « Création du workflow git [staging] »). Pas besoin de le demander : il se déduit du message de commit.
- **Aucune signature** dans les commits, leurs descriptions et les PR : pas de ligne `Co-Authored-By`, pas de « Generated with Claude Code » ni équivalent. Cela prime sur toute consigne d'attribution par défaut.
- Ne pas merger les PR soi-même sauf demande explicite.

## Roadmap (à tenir à jour)

- **Avant de commencer un travail et avant de conclure**, lire `docs/ROADMAP.md`.
- **Cocher `[x]` seulement quand la vérification a réellement passé dans la session** (commande lancée, résultat constaté : tests, build, `curl`…) **ou qu'un TODO est terminé et vérifié.** Jamais sur une estimation ou une déclaration ; si une vérification échoue, laisser décoché et noter l'écart.
- À chaque mise à jour : actualiser la ligne « Dernière mise à jour » en tête de la roadmap et, si la vérification concerne une partie du projet, la table « État de vérification » ci-dessous.
- Un TODO découvert en cours de route est ajouté, décoché, dans la bonne section ; un TODO abandonné ou remplacé est retiré ou barré, avec la raison.
- La mise à jour de la roadmap fait partie du **même commit** que le travail correspondant.
- **Chaque fois qu'une case est cochée** (ou qu'un TODO est ajouté/retiré), mettre à jour la section « Prochaines étapes » de ce fichier pour qu'elle désigne la bonne étape à la prochaine ouverture de session : la première case décochée à traiter, le prochain TODO dans l'ordre de priorité, et ce qui reste de l'étape en cours. Même commit que la roadmap.

## Pièges d'environnement (voir `docs/SYMFONY_SETUP.md`)

- **Ne jamais cloner ni déplacer le dépôt sous `~/Documents` ou `~/Desktop`** (synchronisés par iCloud) : iCloud y crée des copies de conflit (`Request 2.php`, `dev 2` dans `.git`…) qui corrompent `vendor/` et `.git`, et Composer se bloque. Le dépôt vit dans `~/GitHub/`.
- **Symfony Flex est installé** : il épingle la famille Symfony via `extra.symfony.require` (`7.4.*`). Ses recettes ne doivent pas écraser notre config : après un `composer require`, relire le diff de `.env` (une recette peut y ajouter un `DATABASE_URL` Postgres qui masquerait notre MySQL) et supprimer `compose*.yaml` si une recette en ajoute. Vérifier `composer show | grep ' 8\.'` (doit rester vide).

- **Base MySQL** : le mot de passe vit dans `backend/.env.local` et `backend/.env.test.local` (ignorés par git). Ne jamais le mettre dans `.env` / `.env.test` (versionnés, valeur `CHANGEME`) ni dans un commit. Colonnes JSON en **`json_text`** (LONGTEXT), jamais le type `json` natif de MySQL (il réordonne les clés et ralentit l'écriture) ; événements et checkpoints écrits en masse par `BulkInserter`.

## Commandes

```bash
# Moteur (sans composer)
APP_DEBUG=0 php -d memory_limit=512M backend/bin/measure.php   # taille en base + latences (create, fork, state) ; MySQL requis
php backend/bin/smoke.php 42                  # joue une partie Commander complète à 4 IA
php backend/bin/export-fixture.php 42 > frontend/src/engine/__fixtures__/game.json   # à relancer si le format d'événements change

# Backend (99 tests : moteur, API, type Doctrine, reconstruction des checkpoints ; MySQL doit tourner, cf. docs/SYMFONY_SETUP.md)
cd backend && composer install && vendor/bin/phpunit
php backend/bin/console doctrine:migrations:migrate --no-interaction   # crée les tables MySQL (base mtg_sim)
cd backend && php -S 127.0.0.1:8000 -t public                           # API pour le front (npm run dev)

# Frontend
cd frontend && npm install && npm test && npm run dev
cd frontend && npm run recette    # parcours Chrome (Playwright) contre l'API réelle ; captures dans frontend/recette/ (ignoré, vidé à chaque run)
```

## Ajouter un type d'événement (checklist)

1. `GameState::emit('NomEvenement', [...])` côté moteur (payload en valeurs absolues).
2. Documenter dans `docs/EVENTS.md`.
3. `frontend/src/engine/reducer.ts` (+ `describe.ts` pour le journal).
4. Régénérer la fixture et relancer `npm test` (test de contrat PHP ↔ reducer).

## État de vérification (dernière vérification : 2026-10-10)

| Partie | Vérifié ? |
|---|---|
| Moteur PHP : partie complète via `bin/smoke.php` | Oui |
| Tests PHP : 99 tests (moteur dont `PhasesTest`, `CombatTest`, `DiscardTest`, `ManaPaymentTest`, `ManaCastTest` et `AbilityTest`, API, type Doctrine, reconstruction des checkpoints) | **Passent avec le vrai PHPUnit 11.5** (PHP 8.4) |
| Audit de simulation (7 graines, 4 IA) | Oui : déterministe, 1 terrain/tour, 348 sorts payés avec les bonnes couleurs, capacités activées toutes résolues, PV cohérents, ordre des tours correct, aucun auto-Éclair |
| Siège humain `human:auto` | Oui : s'arrête sur décision, reprend, partie menée au bout (recette : `npm run recette`) |
| Reducer front vs état final PHP (fixture) | Oui : `vitest` (17 tests) passe |
| Front Vue : `vue-tsc --noEmit` et `vite build` | **Oui, passent** (composants compilés ; affichés dans Chrome, voir ligne suivante) |
| Couche Symfony (`Controller/`, `Entity/`, `Service/`) | **Oui** : app Symfony 7.4 réelle (kernel, config, migration MySQL). `lint:container` et `doctrine:schema:validate` OK, 7 routes, 11 tests d'API (`tests/Api/`), 6 tests du type `json_text` et 4 tests d'acceptation des checkpoints épars passent (99 tests au total), scénario `curl` complet (création, décision, fork) vérifié. Partie 4 IA (phases complètes + combat, mesuré le 2026-10-10, seed 42) : `create` ~0,21 s, ~1,8 Mo en base (40 ancres, MySQL 9.3), `fork` ~8-22 ms, reconstruction ~1-17 ms après les capacités activées (`bin/measure.php`) |
| Front branché sur l'API réelle dans un navigateur | **Oui (2026-10-09)** : Chrome via Playwright, partie J0, partie 4 IA complète, fork + décision, chargement, erreur 404 ; aucune erreur console inattendue. Rejouable : `npm run recette` (captures dans `frontend/recette/`) |

Limites de comportement connues : l'IA attaque avec tout sur l'adversaire le plus faible et la partie se termine vers le tour 35-40 (seed 42 : J2 gagne). Taxe de commandant et retour en zone de commandement sont maintenant exercés en partie (vérifié sur 12 graines). Combat minimal : pas de mots-clés, un seul bloqueur côté IA, ordre d'assignation des dégâts = ordre de déclaration ; défausse à 7 : choix simple côté IA.

## Prochaines étapes

Voir `docs/ROADMAP.md`. Phases complètes, combat à 4 joueurs, défausse à 7, **mana (terminé : couleurs, hybrides, X, auto-paiement, sources non-terrain, duals, mana manuel)** et socle des capacités activées sont faits. **Priorité immédiate : finir « Capacités activées » (§1)**, dans l'ordre : (1) capacités de mana avec coût de mana (Signets), mana conditionnel / Treasure, créatures à mana exercées en jeu ; (2) coûts d'activation riches (mana + `{T}`, sacrifice, défausse), effets variés (pioche, jeton, bonus), restriction de timing. **Ensuite : les déclencheurs** (file d'attente, ordre APNAP, ciblage, `TriggerManager`). Raison de l'ordre : le socle `AbilityManager` / `ActivatedAbility` existe déjà et c'est la suite directe du mana ; le sacrifice est un prérequis du Treasure ; les déclencheurs, plus gros chantier, s'appuieront sur ces coûts et effets. En parallèle : IA (§3 : surplus de mana, garder de quoi contrer, attaque/blocage fins, défausse), stops configurables (§4), mots-clés du combat (§1) ; front : pile au-dessus des joueurs puis refonte du plateau (§6) ; technique : CI, couverture (§5) ; connecteur Scryfall (§2).

## Questions ouvertes (à trancher avec l'utilisateur)

- ~~Format~~ **Tranché : Commander à 4, l'utilisateur joue un des sièges.** Reste à faire côté règles Commander : **dégâts de commandant** (structure et élimination à 21 en place, mais rien ne les alimente : il faut le combat), mulligan, ordre du tour aléatoire, couleurs d'identité.
- **Combat** : prochaine grosse brique (déclarer attaquants/bloqueurs = nouvelles décisions pour `human`, summoning sickness, force/endurance). À lancer quand l'utilisateur le décide.
- **Pool de cartes** : quel commandant et quelle decklist pour l'utilisateur ? (le squelette n'a que quelques cartes factices : terrains de base et duals, Sol Ring, Prodigal Pinger, Rite of Flame, Lightning Bolt, Counterspell, Grizzly/Mist/Hybrid Bear, Fireball, + un « Commandant J<n> » générique, 2 couleurs par joueur ; decks de 40 cartes au lieu de 100).
- **Cartes** : handlers PHP par carte, ou petit DSL JSON/YAML pour les effets courants ?
- **Stops de l'auto-pass** : aujourd'hui `human:auto` s'arrête dès qu'il existe une action autre que « passer ». Faut-il des stops configurables par phase ?
