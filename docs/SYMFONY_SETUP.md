# Symfony : état réel de l'intégration

`backend/` est désormais une **vraie app Symfony 7.4** (kernel, config, migrations, tests d'API), vérifiée le 2026-10-09. Le moteur `src/Engine/` reste du PHP pur, exclu de l'autowiring.

## Démarrage

### 1. MySQL (prérequis)

La persistance utilise **MySQL 9.3** (installeur Oracle, `/usr/local/mysql`). Serveur démarré (Réglages Système → MySQL), puis, une seule fois, avec `/usr/local/mysql/bin/mysql -u root -p` :

```sql
CREATE DATABASE mtg_sim      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE mtg_sim_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mtgsim'@'localhost' IDENTIFIED BY 'MOT_DE_PASSE';
GRANT ALL PRIVILEGES ON mtg_sim.*      TO 'mtgsim'@'localhost';
GRANT ALL PRIVILEGES ON mtg_sim_test.* TO 'mtgsim'@'localhost';
```

Le vrai mot de passe va dans des fichiers **non versionnés** (le `.env` versionné contient `CHANGEME`). Choisir un mot de passe sans `@ : / # %` (il est dans une URL) :

- `backend/.env.local` : `DATABASE_URL="mysql://mtgsim:MOT_DE_PASSE@127.0.0.1:3306/mtg_sim?serverVersion=9.3.0&charset=utf8mb4"`
- `backend/.env.test.local` : même URL avec la base `mtg_sim_test` (Symfony ne charge pas `.env.local` en environnement `test`).

### 2. Application

```bash
cd backend
composer install
php bin/console doctrine:migrations:migrate --no-interaction   # crée les tables dans mtg_sim
php -S 127.0.0.1:8000 -t public                                 # ou: symfony serve -d --port=8000
```

Le front (`cd frontend && npm run dev`) relaie `/api` vers le port 8000 (voir `frontend/vite.config.ts`).

## Vérifications

```bash
vendor/bin/phpunit                          # 28 tests : suites "engine" (18) et "api" (10)
vendor/bin/phpunit --testsuite api          # API seule (contrôleur -> service -> moteur -> Doctrine/MySQL)
php bin/console lint:container
php bin/console doctrine:schema:validate
php bin/console debug:router                # 7 routes sous /api/simulations
```

Les tests d'API (`tests/Api/SimulationApiTest.php`) utilisent la base `mtg_sim_test` (`.env.test` + `.env.test.local`), dont le schéma est recréé à chaque test : **MySQL doit tourner** pour les lancer.

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

## Mesures (seed 42, 4 IA)

| | SQLite (avant) | MySQL 9.3 (maintenant) |
|---|---|---|
| `POST /api/simulations` (4935 événements, 897 checkpoints) | ~0,55 s | **~1,9 s** |
| Taille d'une partie | ~19 Mo | **~28 Mo** (les checkpoints dominent : ~32 Ko chacun) |
| Suite PHPUnit complète (28 tests) | ~6 s | **~17 s** |

Un seul `flush()` en fin de `run()` : volontairement simple, sans problème à cette taille (`max_allowed_packet` = 64 Mo, une ligne `checkpoint` ≈ 32 Ko). À optimiser (insertions par lots, moins de checkpoints) si le volume grossit.

**Colonnes `JSON` natives de MySQL** : MySQL normalise l'ordre des clés d'un objet. Une valeur relue depuis la base peut donc avoir ses clés dans un autre ordre que celle renvoyée directement par le moteur (ex. `POST` vs `GET /pending`). C'est sans conséquence (l'ordre des clés d'un objet JSON n'a pas de sens, les listes gardent leur ordre, et le front lit par nom) mais les tests doivent comparer avec `assertEquals`, pas `assertSame`. `GameState::fromArray()` n'en dépend pas : le test « fork sans changement = même fin de partie » le confirme.

## Pièges rencontrés lors de l'intégration

- **Dossier synchronisé par iCloud (`~/Documents`)** : c'était la cause de tous les blocages. Installer `vendor/` y générait des copies de conflit (`Request 2.php`, `Kernel 3.php`…), et iCloud a même dupliqué des références dans `.git` (`refs/heads/dev 2`), ce qui cassait `git fetch`. Composer ne rendait jamais la main après « Generating autoload files ». Le dépôt a donc été déplacé hors d'iCloud, dans `~/GitHub/`. Ne pas le remettre sous `~/Documents` ou `~/Desktop`. (Le contournement `vendor.nosync` n'est plus utilisé.)
- **Symfony Flex** : il avait l'air de bloquer, mais c'était iCloud. Hors iCloud il fonctionne et il est installé. Il épingle la famille Symfony (`extra.symfony.require: 7.4.*`), ce qui évite de retomber sur un mélange 7.4 / 8.x (`symfony/var-exporter` 8 est incompatible avec Doctrine). La config existait déjà : ses recettes ont ajouté `cache.yaml`, `routing.yaml`, `config/routes/`, `preload.php`, `symfony.lock`, `bin/phpunit`, des `.gitignore` de dossiers, et un bloc dans `.env`. Nettoyage fait après coup : bloc `.env` dupliqué retiré (il remettait un `DATABASE_URL` Postgres), `compose.yaml` / `compose.override.yaml` / `.env.dev` supprimés, script `assets:install` retiré. `DEFAULT_URI` (requis par `routing.yaml`) est dans `.env`.
- Après chaque `composer require` / `update` : relire le diff de `.env`, supprimer `compose*.yaml` ajouté par une recette, et vérifier `composer show | grep ' 8\.'` (vide).
- `make:migration` n'est pas installé : utiliser `doctrine:migrations:diff`. Les migrations sont écrites pour MySQL (SQL propre à MySQL) ; ne pas les rejouer sur une autre base.
- `config/reference.php` est généré automatiquement par Symfony et change selon l'environnement : le versionner tel quel.
- Le `memory_limit` du serveur doit rester confortable (le test d'API le fixe à 512 Mo).
