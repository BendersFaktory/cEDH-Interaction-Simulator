# Symfony : état réel de l'intégration

`backend/` est désormais une **vraie app Symfony 7.4** (kernel, config, migrations, tests d'API), vérifiée le 2026-10-09. Le moteur `src/Engine/` reste du PHP pur, exclu de l'autowiring.

## Démarrage

```bash
cd backend
composer install
php bin/console doctrine:migrations:migrate --no-interaction   # crée var/data.db (SQLite)
php -S 127.0.0.1:8000 -t public                                 # ou: symfony serve -d --port=8000
```

Le front (`cd frontend && npm run dev`) relaie `/api` vers le port 8000 (voir `frontend/vite.config.ts`).

## Vérifications

```bash
vendor/bin/phpunit                          # 28 tests : suites "engine" (18) et "api" (10)
vendor/bin/phpunit --testsuite api          # API seule (contrôleur -> service -> moteur -> Doctrine/SQLite)
php bin/console lint:container
php bin/console doctrine:schema:validate
php bin/console debug:router                # 7 routes sous /api/simulations
```

Les tests d'API (`tests/Api/SimulationApiTest.php`) utilisent `var/test.db` (`.env.test`), recréée à chaque test.

## Routes

| Méthode | Chemin | Rôle |
|---|---|---|
| POST | `/api/simulations` | Crée et joue une partie (corps : `seed`, `controllers`) |
| GET | `/api/simulations/{id}` | Vue de la simulation |
| GET | `/api/simulations/{id}/events?after=N` | Journal (ancêtres jusqu'à la bifurcation + propres événements) |
| GET | `/api/simulations/{id}/state?at=N` | État du checkpoint le plus proche (<= N) |
| GET | `/api/simulations/{id}/pending` | Décision en attente (`actions`) |
| POST | `/api/simulations/{id}/fork` | Bifurque (corps : `atEvent`, `controllers`) |
| POST | `/api/simulations/{id}/decisions` | Répond à la décision en attente (`actionId`) |

Défaut = Commander à 4, J0 en `human:auto`. Pour 4 IA : `{"controllers":{"0":"ai:heuristic","1":"ai:heuristic","2":"ai:heuristic","3":"ai:heuristic"}}`.
Erreurs : 409 (état incompatible : aucune décision attendue, aucun checkpoint), 422 (action, agent ou joueur inconnu), 404 (simulation absente), 400 (JSON invalide).

## Scénario manuel

```bash
curl -X POST localhost:8000/api/simulations -H 'Content-Type: application/json' -d '{"seed":42,"controllers":{"0":"ai:heuristic","1":"ai:heuristic","2":"ai:heuristic","3":"ai:heuristic"}}'
curl -s localhost:8000/api/simulations/1/events | jq '[.[] | select(.type=="PriorityGranted" and .payload.checkpoint)][20].seq'   # ex. 152
curl -X POST localhost:8000/api/simulations/1/fork -H 'Content-Type: application/json' -d '{"atEvent":152,"controllers":{"1":"human:auto"}}'
curl localhost:8000/api/simulations/2/pending
curl -X POST localhost:8000/api/simulations/2/decisions -H 'Content-Type: application/json' -d '{"actionId":"pass:1"}'
```

## Mesures (seed 42, 4 IA, SQLite)

- `POST /api/simulations` : **~0,55 s** pour 4935 événements et 897 checkpoints.
- `var/data.db` : **~19 Mo** pour une seule partie complète (les checkpoints dominent). SQLite suffit en développement ; prévoir Postgres/MySQL et la réduction des checkpoints (re-simuler depuis le dernier) avant d'accumuler beaucoup de parties.
- Un seul `flush()` en fin de `run()` : volontairement simple, sans problème de mémoire à cette taille.

## Pièges rencontrés lors de l'intégration

- **Dossier synchronisé par iCloud (`~/Documents`)** : l'installation de `vendor/` y génère des copies de conflit (`Request 2.php`, `Kernel 3.php`…) et fait bloquer Composer (« Generating autoload files » qui ne rend jamais la main). Contournement : `vendor` est un lien symbolique vers **`vendor.nosync/`**, que iCloud ignore :

  ```bash
  cd backend && rm -rf vendor && mkdir vendor.nosync && ln -s vendor.nosync vendor && composer install
  ```

  Même précaution pour `node_modules` si des doublons apparaissent. Idéalement, cloner le dépôt hors d'un dossier iCloud.
- **Symfony Flex bloque** (téléchargement parallèle des recettes depuis GitHub qui ne se termine pas). Le projet n'utilise donc **pas Flex** : la config (`config/`, `bin/console`, `public/index.php`, `src/Kernel.php`, `.env`) est versionnée à la main. Ne pas ajouter `symfony/flex`.
- **Sans Flex, la famille Symfony n'est pas épinglée** : `composer.json` fixe explicitement tous les paquets `symfony/*` en `^7.4`. Sans cela, Composer en résout une partie en 8.x et `lint:container` échoue (`symfony/var-exporter` 8 incompatible avec Doctrine). Vérifier avec `composer show | grep ' 8\.'` après chaque mise à jour.
- `doctrine:database:create` n'est pas supporté pour SQLite (le fichier est créé par la migration) ; `make:migration` n'est pas installé : utiliser `doctrine:migrations:diff`.
- Le `memory_limit` du serveur doit rester confortable (le test d'API le fixe à 512 Mo).
