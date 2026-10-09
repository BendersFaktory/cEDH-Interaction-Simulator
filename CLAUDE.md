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

## Commandes

```bash
# Moteur (sans composer)
php backend/bin/smoke.php 42                  # joue une partie Commander complète à 4 IA
php backend/bin/export-fixture.php 42 > frontend/src/engine/__fixtures__/game.json   # à relancer si le format d'événements change

# Backend
cd backend && composer install && vendor/bin/phpunit

# Frontend
cd frontend && npm install && npm test && npm run dev
```

## Ajouter un type d'événement (checklist)

1. `GameState::emit('NomEvenement', [...])` côté moteur (payload en valeurs absolues).
2. Documenter dans `docs/EVENTS.md`.
3. `frontend/src/engine/reducer.ts` (+ `describe.ts` pour le journal).
4. Régénérer la fixture et relancer `npm test` (test de contrat PHP ↔ reducer).

## État de vérification (à jour à la création du squelette)

| Partie | Vérifié ? |
|---|---|
| Moteur PHP : syntaxe + partie complète via `bin/smoke.php` | Oui |
| 18 tests (`PriorityRoundTest` + `CommanderTest`) | Passent avec un mini-runner qui imite PHPUnit ; **jamais lancés avec le vrai PHPUnit** (Packagist inaccessible depuis l'environnement de génération) |
| Reducer front vs état final PHP (fixture) | Oui, via Node (type stripping), mêmes assertions que `reducer.test.ts` |
| Couche Symfony (`Controller/`, `Entity/`, `Service/`) | **Syntaxe PHP uniquement** : jamais exécutée, pas de kernel/config/migrations |
| Front Vue (composants, store) | **Jamais compilé** : `npm install` impossible à la génération → `vue-tsc` et `vitest` à lancer en premier |

## Prochaines étapes

Voir `docs/ROADMAP.md`. Priorité immédiate : intégrer le squelette dans une vraie app Symfony (`docs/SYMFONY_SETUP.md`), lancer `phpunit` / `npm test` / `vue-tsc`, corriger ce qui casse.

## Questions ouvertes (à trancher avec l'utilisateur)

- ~~Format~~ **Tranché : Commander à 4, l'utilisateur joue un des sièges.** Reste à faire côté règles Commander : **dégâts de commandant** (structure et élimination à 21 en place, mais rien ne les alimente : il faut le combat), mulligan, ordre du tour aléatoire, couleurs d'identité.
- **Combat** : prochaine grosse brique (déclarer attaquants/bloqueurs = nouvelles décisions pour `human`, summoning sickness, force/endurance). À lancer quand l'utilisateur le décide.
- **Pool de cartes** : quel commandant et quelle decklist pour l'utilisateur ? (le squelette n'a que 4 cartes factices : Land, Lightning Bolt, Counterspell, Grizzly Bears, + un « Commandant J<n> » générique ; decks de 40 cartes au lieu de 100).
- **Cartes** : handlers PHP par carte, ou petit DSL JSON/YAML pour les effets courants ?
- **Stops de l'auto-pass** : aujourd'hui `human:auto` s'arrête dès qu'il existe une action autre que « passer ». Faut-il des stops configurables par phase ?
