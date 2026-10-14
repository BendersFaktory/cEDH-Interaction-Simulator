# MTG Sim — contexte pour le travail en commun

Simulateur Magic: The Gathering **Commander à 4 joueurs : l'utilisateur occupe un siège (J0 par défaut, agent `human:auto`) face à 3 IA**. Il peut mettre en pause, revenir à un point de priorité et **forker** pour tester une autre ligne de jeu. Option « 4 IA » pour juste regarder. Stack : Symfony (API) + moteur PHP pur + front Vue 3 / Pinia.

## Règles d'or (ne pas casser)

1. **Le moteur (`backend/src/Engine/`) est du PHP pur** : aucune dépendance Symfony/Doctrine. Testable seul.
2. **Déterminisme** : jamais de `rand()`, `random_int()`, `shuffle()`, `time()` dans `Engine/`. Tout l'aléa passe par `GameState::$rng` (dont l'état est sauvegardé dans les snapshots).
3. **Tout l'état est dans `GameState`** (`toArray()`/`fromArray()`). Pas d'état caché dans des services, singletons ou agents (les agents sont sans état).
4. **Le moteur ne saute JAMAIS une priorité.** Chaque joueur en vie est interrogé à chaque ronde. L'auto-pass est une politique de l'*agent* (`HumanAgent(autoPass: true)`), jamais un raccourci du moteur : les `PriorityPassed` doivent toujours être journalisés. C'est ce qui permet à l'IA de contrer juste après un fork.
5. **Un sort lancé remet `priority->passed` à zéro** et rend la priorité au lanceur ; la pile ne se résout que quand tous les joueurs en vie ont passé consécutivement. Les terrains ne déclenchent pas de ronde.
6. **On ne forke que sur un checkpoint** = un `PriorityGranted` dont le payload porte `checkpoint: true` (début de ronde, ou pile non vide). Jamais au milieu d'une résolution de pile. Les autres octrois n'ont pas de point de bifurcation ; le serveur recolle au checkpoint précédent. Seules des **ancres** sont stockées (1 par tour, + pause humaine, + fork) : les checkpoints intermédiaires se reconstruisent par rejeu (`StateReconstructor`).
7. **Capacités activées** : toute activation passe par `AbilityManager::activate()`. Une capacité de mana (`isManaAbility`) est résolue immédiatement, sans pile ni nouvelle ronde (comme `PlayLand`) ; les autres vont sur la pile. Ne jamais créer de mana ailleurs que par `PlayerState::activateManaSource`. Le pool persiste jusqu'à la fin de l'étape.
8. **Commander** : un commandant qui irait au cimetière (contré, détruit...) retourne en zone de commandement (`GameState::sendToGraveyard()` — toujours l'utiliser, jamais `->graveyard[] =` directement ; un jeton y cesse d'exister, destination `none`). Un sacrifice passe toujours par `GameState::sacrifice()`. La taxe est dans `PlayerState::castCost()` : toujours passer par elle pour un coût.
9. **Le front est « bête »** : il applique les événements (`src/engine/reducer.ts`), aucune règle de jeu. Les payloads d'événements portent des **valeurs absolues** (ex. PV résultants) pour que le reducer reste trivial.
10. **Ne jamais faire confiance au client** : `POST /decisions` envoie un `actionId`, le serveur recalcule les actions légales et retrouve l'action.

## Workflow Git (branches)

- `dev` : branche de travail. `staging` : intégration (cible des PR). `master` : **réservée à l'utilisateur**, gérée entièrement à la main. Ne jamais y committer, merger, pousser ni ouvrir de PR vers elle.
- **Avant toute modification de fichier**, vérifier la branche : `git branch --show-current`. Si ce n'est pas `dev`, faire `git switch dev` (si l'arbre est sale ou si la bascule échoue, s'arrêter et demander). Ne jamais modifier de fichier sur `master` ou `staging`.
- **À chaque commit sur `dev`** : pousser `dev` et créer en même temps une PR `dev` → `staging` (`gh pr create --base staging --head dev`). Si une PR `dev` → `staging` est déjà ouverte, le push l'alimente : ne pas en créer une seconde, le signaler à l'utilisateur.
- **Message de commit** : si l'utilisateur ne l'a pas fourni, le lui demander explicitement à chaque fois, avant de committer. Ne jamais l'inventer ni réutiliser un message précédent.
- **Commit de résolution de conflit** (fusion de `staging` dans `dev` pour résoudre les conflits d'une PR) : le message est toujours `Resolve merge conflict {hash du commit qui cause le conflit}` (hash court du commit de `staging` à l'origine du conflit, ex. `Resolve merge conflict 0923371`). Pas besoin de le demander : il se déduit du conflit.
- **Titre de la PR** : toujours `{message de commit} [staging]` (ex. commit « Création du workflow git » → PR « Création du workflow git [staging] »). Pas besoin de le demander : il se déduit du message de commit.
- **Aucune signature** dans les commits, leurs descriptions et les PR : pas de ligne `Co-Authored-By`, pas de « Generated with Claude Code » ni équivalent. Cela prime sur toute consigne d'attribution par défaut.
- Ne pas merger les PR soi-même sauf demande explicite.
- **Réalignement des branches** : une PR se merge toujours avec **« Create a merge commit »** (pas squash, pas rebase). Ce commit de merge n'existe que sur la cible ; la branche source doit donc le rattraper, sinon la PR suivante entre en conflit. Le workflow `.github/workflows/sync-branches.yml` le fait à chaque push sur `master` puis `staging` : `staging` avance jusqu'à `master`, puis `dev` avance jusqu'à `staging`, uniquement en fast-forward. Il ne réécrit jamais l'historique ; si une branche a divergé, il échoue et le signale. Ne pas forcer de push pour « réaligner » : si le workflow échoue, comparer les branches (`git log --oneline A..B`) et demander à l'utilisateur.
- Procédure manuelle équivalente (si le workflow n'a pas tourné) : `git fetch origin`, puis `git push origin origin/master:refs/heads/staging`, puis `git push origin origin/staging:refs/heads/dev`. Ces pushs sont sans `--force` : git refuse ce qui n'est pas un fast-forward.

## Sous-agents et parallélisation

- **Paralléliser au maximum** : dès qu'une étape se découpe en tâches indépendantes (fichiers ou fonctions distincts, pas de dépendance de résultat), les lancer dans le même tour, en arrière-plan. Ne pas paralléliser ce qui se touche (même fichier, même fonction) : le conflit coûte plus cher que le gain.
- **Modèle le moins cher qui reste fiable pour la tâche**, choisi à chaque plan : `haiku` pour le mécanique (commandes, installation, rédaction de scripts simples, relecture de sorties, mise à jour de documentation) ; `sonnet` pour un refactor ciblé dont le comportement est vérifiable par des tests ; `opus` pour un raisonnement délicat (algorithmes avec contrainte d'optimalité, règles de jeu, revue de sécurité). Ne jamais descendre sous le modèle qui a fait échouer une tâche précédente de même nature.
- **Branche par sous-agent** (`isolation: "worktree"`) : chaque sous-agent travaille sur sa propre branche et son propre worktree, jamais sur `dev` directement.
  - **Partir de `dev`, pas de `master`** : le worktree est créé depuis la branche par défaut du dépôt (`master`). Le sous-agent doit donc, avant tout travail, exécuter `git switch -c <nom-de-branche> dev` (ou `git reset --hard dev` sur une branche créée automatiquement) et vérifier que `git rev-parse --short HEAD` vaut le hash de `dev`. Sinon, il s'arrête et le signale.
  - Le sous-agent ne committe pas, ne pousse pas et n'ouvre pas de PR.
- **Intégration** (faite par la session principale, pas par le sous-agent) : une fois le travail vérifié (tests, empreintes, build), la session fusionne la branche du sous-agent dans `dev`. Un conflit se traite comme un conflit de fusion ordinaire, sans message ni commit de résolution inventé. Le commit final suit les règles de la section « Workflow Git » : message fourni par l'utilisateur, push de `dev`, PR `dev` → `staging`.
- Après intégration, supprimer le worktree et la branche du sous-agent une fois leur contenu repris dans `dev` (`git worktree remove`, `git branch -d`).
- **Compacter le contexte à chaque fermeture de sous-agent** : une fois le rapport d'un sous-agent reçu et intégré, la session principale compacte son contexte (`/compact`), pour que les journaux et sorties des sous-agents ne s'accumulent pas. Le compactage est déclenché par la session (commande `/compact`), pas par le sous-agent.
- **Plan approuvé et sous-agents** : quand un plan approuvé par l'utilisateur prescrit des sous-agents, leur lancement est autorisé ; la validation du plan vaut permission explicite.

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
php backend/bin/bench.php 1..20 42            # vitesse + empreinte sha1 des événements (à comparer avant/après une optimisation)
php backend/bin/audit-ai.php 42,6,16,122,7,99,150   # audit IA : mana perdu, contresorts, combat, sha1
SPX_ENABLED=1 SPX_FP_LIMIT=30 php backend/bin/smoke.php 16 > /dev/null   # profil SPX (rapport sur stderr ; installation dans docs/SYMFONY_SETUP.md)
php backend/bin/export-fixture.php 42 > frontend/src/engine/__fixtures__/game.json   # à relancer si le format d'événements change

# Backend (168 tests : moteur, API, type Doctrine, reconstruction des checkpoints ; MySQL doit tourner, cf. docs/SYMFONY_SETUP.md)
cd backend && composer install && vendor/bin/phpunit
php backend/bin/console doctrine:migrations:migrate --no-interaction   # crée les tables MySQL (base mtg_sim)
cd backend && php -S 127.0.0.1:8000 -t public                           # API pour le front (npm run dev)
cd backend && php bin/console messenger:consume async -vv              # worker : POST /simulations ne fait que mettre la partie en file (statut queued)

# Frontend
cd frontend && npm install && npm test && npm run dev
cd frontend && npm run recette    # parcours Chrome (Playwright) contre l'API réelle, démarre API + workers ; scénarios 8-15 en parallèle (RECETTE_JOBS=4, RECETTE_ONLY=10,15 pour n'en lancer que certains) ; captures dans frontend/recette/ (ignoré, vidé à chaque run)
```

## Ajouter un type d'événement (checklist)

1. `GameState::emit('NomEvenement', [...])` côté moteur (payload en valeurs absolues).
2. Documenter dans `docs/EVENTS.md`.
3. `frontend/src/engine/reducer.ts` (+ `describe.ts` pour le journal).
4. Régénérer la fixture et relancer `npm test` (test de contrat PHP ↔ reducer).

## État de vérification (dernière vérification : 2026-10-14, IA cible d'attaque par menace et profils)

| Partie | Vérifié ? |
| --- | --- |
| Moteur PHP : partie complète via `bin/smoke.php` | Oui |
| Tests PHP : 238 tests (moteur dont `HeuristicAgentTest`, `RunSimulationHandlerTest`, `StopsTest`, `DamageAssignmentTest`, `PhasesTest`, `CombatTest`, `KeywordsTest`, `DiscardTest`, `ManaPaymentTest`, `ManaCastTest`, `AbilityTest`, `ActivatedCostsTest`, `TriggerQueueTest`, `TriggerTest`, `CleanupRoundTest`, `SubtypesTest`, `MultiTypeTest` et `StateVersionTest`, API, type Doctrine, reconstruction des checkpoints) | **Passent avec le vrai PHPUnit 11.5** (PHP 8.4) |
| Audit de simulation (7 graines, 4 IA) | Oui. Audit IA (2026-10-14, cible d'attaque par menace, paiement qui épargne le contresort) : mana perdu 8 → 9, sorts 354 → 406, contresorts lancés/réussis 27/27 → 32/31, attaques 366 → 335, morts 92 → 103, tours 288 → 324 (on ne s'acharne plus sur le plus faible) ; sorties identiques en double exécution ; 28 parties à profils mêlés (`ai:aggro`, `ai:control`) toutes terminées. Audit IA (2026-10-14, `bin/audit-ai.php`, 7 graines 42/6/16/122/7/99/150), avant → après : mana perdu 9 → 8, contresorts lancés/réussis 29/29 → 27/27, sorts 380 → 354, blocages à deux 0 → 5, morts 119 → 92, commandants renvoyés en zone de commandement 24 → 3, tours 294 → 288 ; toutes les parties terminées, sorties identiques en double exécution. Moteur : déterministe, 1 terrain/tour, 383 sorts payés avec les bonnes couleurs (Signets, Treasures, Mind Stone compris), capacités à coûts riches exercées (Bombardment ne sacrifie que des jetons, Mongrel ne défausse que des terrains, timing de rituel respecté, bonus remis à zéro au cleanup), capacités activées toutes résolues, créatures à mana jamais engagées pendant le mal d'invocation, PV cohérents, ordre des tours correct, aucun auto-Éclair. Triggers (2026-10-14, 7 graines 42/6/16/122/7/99/150) : sha1 identiques en double exécution, aucun trigger en attente à un checkpoint, attaque / fin de tour / sort lancé / défausse observés ; ronde de cleanup 514.3a réelle sur la graine 777 depuis le lot C de l'IA (956 depuis les cartes multi-types) (122 avant ; aucune sur 1..300), couverte par `CleanupRoundTest`. Triggers (2026-10-11, 7 graines) : 65 posés, tous résolus sauf 1 resté sur la pile en fin de partie, aucun en attente à un checkpoint, APNAP respecté, Myr jamais sur soi |
| Siège humain `human:auto` | Oui : s'arrête sur décision, reprend, partie menée au bout, capacité à choix de coût activée (recette : `npm run recette`, scénario 10), cible d'un trigger choisie (scénario 11), stops appliqués puis remis au défaut (scénario 13), mana restreint d'Eldrazi Temple engagé à la main puis dépensé pour Eldrazi Mimic (scénario 12), J0 bloque un attaquant de l'IA (scénario 14), J0 répartit les dégâts d'un attaquant bloqué par deux créatures, J1 humain lui aussi (scénario 15) ; les scénarios 10, 11, 12, 14 et 15 sont menés ou leurs graines trouvées par l'API, scénarios 8-15 en parallèle : recette ~81 → 32 s (2026-10-14) |
| Reducer front vs état final PHP (fixture) | Oui : `vitest` (33 tests) passe (la fixture seed 42 contient jetons, sacrifices, bonus, triggers et du mana restreint) |
| Front Vue : `vue-tsc --noEmit` et `vite build` | **Oui, passent** (composants compilés ; affichés dans Chrome, voir ligne suivante) |
| Couche Symfony (`Controller/`, `Entity/`, `Service/`) | **Oui** : app Symfony 7.4 réelle (kernel, config, migration MySQL). `lint:container` et `doctrine:schema:validate` OK, 8 routes (dont `PUT …/stops`, 2026-10-14), `POST` asynchrone par Symfony Messenger (2026-10-14 : 202 `queued` en ~40 ms, worker → `running` → `waiting`/`over`, progression par tour vérifiée au `curl`), 11 tests d'API (`tests/Api/`), 6 tests du type `json_text` et 4 tests d'acceptation des checkpoints épars passent (150 tests au total), scénario `curl` complet (création, décision, fork) vérifié. Partie 4 IA (mesuré le 2026-10-12 après le profilage, graines 42 / 6 / 16) : `create` ~0,22-0,25 s, **~0,70 s sur la seed 16** (59 tours), fork ~11-66 ms, reconstruction ≤ 32 ms, ~1,5-3,0 Mo en base (30-59 ancres, MySQL 9.3) (`bin/measure.php`) |
| Front branché sur l'API réelle dans un navigateur | **Oui (2026-10-09)** : Chrome via Playwright, partie J0, partie 4 IA complète, fork + décision, chargement, erreur 404 ; aucune erreur console inattendue. Rejouable : `npm run recette` (captures dans `frontend/recette/`) |
| Performance moteur (`bin/bench.php`) | **Oui (2026-10-12)** : seed 16 1144 → 437 ms (178 → 68 µs par événement), seed 42 150 → 89 ms, seed 6 134 → 88 ms, 21 graines 7,15 → 3,69 s ; **21 empreintes sha1 identiques** avant/après (seeds 1..20 + 42) ; profil SPX avant optimisation : `ManaPayment::plan` ≈ 75 % du temps |

Limites de comportement connues : l'IA n'attaque pas avec son commandant s'il peut mourir bloqué, garde une créature en défense quand la force adverse atteint ses PV, et attaque avec le reste (sauf créatures à mana et créatures sans force) sur l'adversaire qu'il peut tuer, sinon celui qu'il attaque avec le plus de force sans suicide, sinon le plus menaçant (seed 42 : 4496 événements, J2 gagne). Profils `ai:aggro` (ni réserve de contresort ni garde, cible le plus faible) et `ai:control` (contre dès une menace de 2, garde plus tôt). Taxe de commandant et retour en zone de commandement sont maintenant exercés en partie (vérifié sur 12 graines). Combat : mots-clés faits (vol, portée, célérité, vigilance, défenseur, menace, initiative, double initiative, piétinement, contact mortel, lien de vie), mais répartition des dégâts entre bloqueurs par défaut (létal dans l'ordre de déclaration), même pour l'humain ; l'IA bloque à deux quand la paire tue l'attaquant sans mourir toute entière ; défausse à 7 côté IA : terrains en surplus puis cartes injouables, contresorts gardés (seuils non calibrés) ; l'IA garde de quoi payer son contresort le moins cher.

## Prochaines étapes

Voir `docs/ROADMAP.md`. Phases complètes, combat à 4 joueurs, défausse à 7, **mana (couleurs, hybrides, X, auto-paiement, sources non-terrain, duals, mana manuel, mana conditionnel)**, **capacités activées** et **triggers (§1 : entrée en jeu, mort, entretien ; APNAP, choix d'ordre et de cible)** sont faits. **Mana conditionnel terminé (2026-10-12)** : Eldrazi Temple / Eldrazi Mimic, sous-types rattachés à leur type, recette scénario 12. La pile est au-dessus des joueurs (§6). **Moteur profilé et optimisé (2026-10-12)** : seed 16 1,14 → 0,44 s dans `bin/bench.php`, empreintes d'événements identiques (§5). **Triggers complets (2026-10-14, PR #21)** : attaque, fin de tour, sort lancé, défausse, ronde de cleanup 514.3a. **Cartes multi-types faites (2026-10-14)** ; **pile non vide en fin de partie tranchée (laissée en l'état, documentée)** ; **IA (2026-10-14) : évaluateur de menace pour les contresorts fait, surplus de mana partiel (cause : Counterspell payés par un Signet ; 10 mana perdus sur 7 graines)**, **réserve de mana pour contrer et défausse plus fine faites (2026-10-14, contresorts réussis 19 → 29 sur 7 graines)** ; **CI backend/front faite et verte sur GitHub (`backend.yml`, `frontend.yml`, phpstan niveau 2)** ; **Mots-clés du combat faits (2026-10-14)**, **répartition des dégâts `AssignCombatDamage` par l'humain faite** (reste : cartes blanches/noires ; recettes blocage et répartition faites, sauf portée/menace et piétinement, faute de cartes dans le deck de J0) ; **stops configurables faits (2026-10-14, §4 : moteur, API, panneau, recette 13)**. **Recettes de combat faites (2026-10-14, scénarios 14 et 15)**. **IA faite le 2026-10-14 (§3 : menace énorme, attaque et blocage fins dont le blocage à deux ; audit `bin/audit-ai.php`)**. **IA §3 faite le 2026-10-14 (suite) : cible d'attaque par menace, paiement qui épargne les Îles du contresort (surplus de mana restant : 9 sur 7 graines, plans forcés), profils `ai:aggro` / `ai:control` sélectionnables par siège.** **Priorité suivante : refonte du plateau (§6)**, puis recettes portée/menace et piétinement (§1) quand le deck de J0 aura ces cartes. **Exécution asynchrone (Messenger) et recette accélérée faites (2026-10-14, §5 ; reste possible : `fork` asynchrone si une mesure le justifie ; scénario 10 de la recette piloté par l'API fait).** En parallèle : front : refonte du plateau (§6) ; technique : CI, couverture (§5) ; connecteur Scryfall (§2).

## Questions ouvertes (à trancher avec l'utilisateur)

- ~~Format~~ **Tranché : Commander à 4, l'utilisateur joue un des sièges.** Reste à faire côté règles Commander : **dégâts de commandant** (structure et élimination à 21 en place, mais rien ne les alimente : il faut le combat), mulligan, ordre du tour aléatoire, couleurs d'identité.
- **Combat** : prochaine grosse brique (déclarer attaquants/bloqueurs = nouvelles décisions pour `human`, summoning sickness, force/endurance). À lancer quand l'utilisateur le décide.
- **Pool de cartes** : quel commandant et quelle decklist pour l'utilisateur ? (le squelette n'a que quelques cartes factices : terrains de base et duals, Sol Ring, Prodigal Pinger, Rite of Flame, Lightning Bolt, Counterspell, Grizzly/Mist/Hybrid Bear (retiré des decks rouges, comme un Lightning Bolt et un Rite of Flame), Fireball, Young Pyromancer, + un « Commandant J<n> » générique, 2 couleurs par joueur ; decks de 40 cartes au lieu de 100).
- **Cartes** : handlers PHP par carte, ou petit DSL JSON/YAML pour les effets courants ?
- ~~Stops de l'auto-pass~~ **Tranché : stops configurables par étape (`StopConfig`), défaut inchangé.**
