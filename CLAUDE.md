# MTG Sim — contexte pour le travail en commun

Simulateur Magic: The Gathering **Commander à 4 joueurs : l'utilisateur occupe un siège (J0 par défaut, agent `human:auto`) face à 3 IA**. Il peut mettre en pause, revenir à un point de priorité et **forker** pour tester une autre ligne de jeu. Option « 4 IA » pour juste regarder. Stack : Symfony (API) + moteur PHP pur + front Vue 3 / Pinia.

## Règles d'or (ne pas casser)

1. **Le moteur (`backend/src/Engine/`) est du PHP pur** : aucune dépendance Symfony/Doctrine. Testable seul.
2. **Déterminisme** : jamais de `rand()`, `random_int()`, `shuffle()`, `time()` dans `Engine/`. Tout l'aléa passe par `GameState::$rng` (dont l'état est sauvegardé dans les snapshots).
3. **Tout l'état est dans `GameState`** (`toArray()`/`fromArray()`). Pas d'état caché dans des services, singletons ou agents (les agents sont sans état).
4. **Le moteur ne saute JAMAIS une priorité.** Chaque joueur en vie est interrogé à chaque ronde. L'auto-pass est une politique de l'*agent* (`HumanAgent(autoPass: true)`), jamais un raccourci du moteur : les `PriorityPassed` doivent toujours être journalisés. C'est ce qui permet à l'IA de contrer juste après un fork.
5. **Un sort lancé remet `priority->passed` à zéro** et rend la priorité au lanceur ; la pile ne se résout que quand tous les joueurs en vie ont passé consécutivement. Les terrains ne déclenchent pas de ronde.
6. **On ne forke que sur un checkpoint** = un `PriorityGranted` dont le payload porte `checkpoint: true` (début de ronde, ou pile non vide). Jamais au milieu d'une résolution de pile. Les autres octrois n'ont pas de snapshot (volume divisé par ~4) ; le serveur recolle au checkpoint précédent.
9. **Commander** : un commandant qui irait au cimetière (contré, détruit...) retourne en zone de commandement (`GameState::sendToGraveyard()` — toujours l'utiliser, jamais `->graveyard[] =` directement). La taxe est dans `PlayerState::castCost()` : toujours passer par elle pour un coût.
7. **Le front est « bête »** : il applique les événements (`src/engine/reducer.ts`), aucune règle de jeu. Les payloads d'événements portent des **valeurs absolues** (ex. PV résultants) pour que le reducer reste trivial.
8. **Ne jamais faire confiance au client** : `POST /decisions` envoie un `actionId`, le serveur recalcule les actions légales et retrouve l'action.

## Workflow Git (branches)

- `dev` : branche de travail. `staging` : intégration (cible des PR). `master` : **réservée à l'utilisateur**, gérée entièrement à la main. Ne jamais y committer, merger, pousser ni ouvrir de PR vers elle.
- **Avant toute modification de fichier**, vérifier la branche : `git branch --show-current`. Si ce n'est pas `dev`, faire `git switch dev` (si l'arbre est sale ou si la bascule échoue, s'arrêter et demander). Ne jamais modifier de fichier sur `master` ou `staging`.
- **À chaque commit sur `dev`** : pousser `dev` et créer en même temps une PR `dev` → `staging` (`gh pr create --base staging --head dev`). Si une PR `dev` → `staging` est déjà ouverte, le push l'alimente : ne pas en créer une seconde, le signaler à l'utilisateur.
- **Message de commit** : si l'utilisateur ne l'a pas fourni, le lui demander explicitement à chaque fois, avant de committer. Ne jamais l'inventer ni réutiliser un message précédent.
- **Titre de la PR** : toujours `{message de commit} [staging]` (ex. commit « Création du workflow git » → PR « Création du workflow git [staging] »). Pas besoin de le demander : il se déduit du message de commit.
- Ne pas merger les PR soi-même sauf demande explicite.

## Pièges d'environnement (voir `docs/SYMFONY_SETUP.md`)

- Le dépôt vit sous `~/Documents` (iCloud) : `backend/vendor` est un **lien symbolique vers `vendor.nosync/`**, sans quoi iCloud crée des copies `* 2.php` et Composer se bloque. Ne pas remplacer ce lien par un vrai dossier.
- **Pas de `symfony/flex`** (il bloque au téléchargement des recettes) : config Symfony écrite à la main, et paquets `symfony/*` épinglés en `^7.4` dans `composer.json` (vérifier `composer show | grep ' 8\.'` après une mise à jour).
- Composer peut ne pas rendre la main sur ce poste : le lancer en arrière-plan avec un journal plutôt qu'en direct.

## Commandes

```bash
# Moteur (sans composer)
php backend/bin/smoke.php 42                  # joue une partie Commander complète à 4 IA
php backend/bin/export-fixture.php 42 > frontend/src/engine/__fixtures__/game.json   # à relancer si le format d'événements change

# Backend (28 tests : moteur + API)
cd backend && composer install && vendor/bin/phpunit
php backend/bin/console doctrine:migrations:migrate --no-interaction   # crée backend/var/data.db
cd backend && php -S 127.0.0.1:8000 -t public                           # API pour le front (npm run dev)

# Frontend
cd frontend && npm install && npm test && npm run dev
```

## Ajouter un type d'événement (checklist)

1. `GameState::emit('NomEvenement', [...])` côté moteur (payload en valeurs absolues).
2. Documenter dans `docs/EVENTS.md`.
3. `frontend/src/engine/reducer.ts` (+ `describe.ts` pour le journal).
4. Régénérer la fixture et relancer `npm test` (test de contrat PHP ↔ reducer).

## État de vérification (dernière vérification : 2026-10-09)

| Partie | Vérifié ? |
|---|---|
| Moteur PHP : partie complète via `bin/smoke.php` | Oui |
| Tests PHP : 18 tests / 70 assertions (`PriorityRoundTest` + `CommanderTest`) | **Passent avec le vrai PHPUnit 11.5** (PHP 8.4) |
| Audit de simulation (7 graines, 4 IA) | Oui : déterministe, 1 terrain/tour, mana payé = terrains engagés, PV cohérents, ordre des tours correct, aucun auto-Éclair |
| Siège humain `human:auto` | Oui : s'arrête sur décision, reprend, partie menée au bout (passer toujours : 32 décisions ; tout jouer : 40) |
| Reducer front vs état final PHP (fixture) | Oui : `vitest` (4 tests) passe |
| Front Vue : `vue-tsc --noEmit` et `vite build` | **Oui, passent** (composants compilés, jamais affichés dans un navigateur) |
| Couche Symfony (`Controller/`, `Entity/`, `Service/`) | **Oui** : app Symfony 7.4 réelle (kernel, config, migration SQLite). `lint:container` et `doctrine:schema:validate` OK, 7 routes, 10 tests d'API (`tests/Api/`) passent, scénario `curl` complet (création, décision, fork) vérifié. Partie 4 IA : ~0,55 s, ~19 Mo en base |
| Front branché sur l'API réelle dans un navigateur | **Non** : jamais ouvert dans un navigateur |

Limites de comportement connues : sans combat, personne ne meurt de dégâts, donc tous les joueurs meurent de pioche vers le tour 131 et **J3 gagne toujours** (artefact de l'ordre de pioche). Le commandant n'est lancé qu'une fois par joueur (taxe et retour en zone de commandement jamais exercés en partie). Phases réduites à pioche / principale / fin.

## Prochaines étapes

Voir `docs/ROADMAP.md`. Priorité immédiate : tester le front dans un navigateur contre l'API réelle (créer, décider, forker), puis le combat pour donner des enjeux aux parties.

## Questions ouvertes (à trancher avec l'utilisateur)

- ~~Format~~ **Tranché : Commander à 4, l'utilisateur joue un des sièges.** Reste à faire côté règles Commander : **dégâts de commandant** (structure et élimination à 21 en place, mais rien ne les alimente : il faut le combat), mulligan, ordre du tour aléatoire, couleurs d'identité.
- **Combat** : prochaine grosse brique (déclarer attaquants/bloqueurs = nouvelles décisions pour `human`, summoning sickness, force/endurance). À lancer quand l'utilisateur le décide.
- **Pool de cartes** : quel commandant et quelle decklist pour l'utilisateur ? (le squelette n'a que 4 cartes factices : Land, Lightning Bolt, Counterspell, Grizzly Bears, + un « Commandant J<n> » générique ; decks de 40 cartes au lieu de 100).
- **Cartes** : handlers PHP par carte, ou petit DSL JSON/YAML pour les effets courants ?
- **Stops de l'auto-pass** : aujourd'hui `human:auto` s'arrête dès qu'il existe une action autre que « passer ». Faut-il des stops configurables par phase ?
