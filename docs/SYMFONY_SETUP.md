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
vendor/bin/phpunit                          # 35 tests : suites "engine" (18), "api" (11) et "doctrine" (6)
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

| | SQLite (au départ) | MySQL, colonnes `JSON` natives | **MySQL, `json_text` + insertions par lots** (actuel) |
|---|---|---|---|
| `POST /api/simulations` (4935 événements, 897 checkpoints) | ~0,55 s | ~1,94 s | **~0,96 s à froid, ~0,68 s à chaud** |
| Création de partie dans le service (`create()`, moteur ~0,09 s inclus) | n.m. | ~1,9 s | **0,6 à 0,8 s** |
| Suite PHPUnit complète | ~6 s (28 tests) | ~17 s (28 tests) | **~9,6 s (35 tests)** |
| Taille d'une partie en base | ~19 Mo | ~28 Mo | **~29 Mo** (checkpoints : 28,5 Mo) |

| Pic mémoire PHP d'une création | n.m. | n.m. | **~100 Mo** (dont 65 Mo pour le moteur et ses 897 snapshots en mémoire) |

Ce qui a fait gagner du temps (mesuré en isolation avant de coder) : les 897 checkpoints (~20 Mo de JSON) coûtaient ~1,2 s en colonne `JSON` contre ~0,6 s en `LONGTEXT` (MySQL validait et convertissait le JSON en binaire pour rien), et les 5 832 `INSERT` unitaires coûtaient ~0,37 s contre ~0,07 s en multi-lignes (un aller-retour réseau par ligne). Le temps d'écriture restant est dominé par le **volume** des checkpoints : le type `text` ne change pas la taille en base. La réduction est venue des checkpoints épars (voir le tableau suivant).

**Mémoire** : en mode debug, le profileur de Doctrine retient les paramètres des requêtes, donc le pic grimpe d'environ 20 Mo à chaque partie créée dans un même processus (script, boucle de tests). Sans debug, il reste stable. Une requête HTTP isolée n'est pas concernée. Garder `memory_limit` confortable (512 Mo pour le serveur de dev et PHPUnit ; le défaut de 128 Mo du CLI ne suffit pas à enchaîner plusieurs créations en debug).

### Checkpoints épars (N = 1 tour, mesuré le 2026-10-09, seed 42, 4 IA)

| | Snapshot par checkpoint (avant) | **Ancres + rejeu** (actuel) |
|---|---|---|
| Lignes `checkpoint` | 897 | **130** |
| Taille des checkpoints | 28,5 Mo | **2,6 Mo** (événements : 0,24 Mo) |
| `POST /api/simulations` | ~0,68 s | **~0,2 s** |
| `fork` (en milieu de partie) | n.m. | **~20 à 25 ms** |
| `GET /state?at=` | n.m. | **~10 à 55 ms** (55 ms à froid) |
| Suite PHPUnit | ~9,6 s (35 tests) | **~9,6 s (39 tests)** |

Un tour d'écart maximum entre l'ancre et la cible : le rejeu reste en quelques ms, N = 1 n'a donc pas besoin d'être augmenté. Réglage : `app.checkpoint_every_n_turns` dans `config/services.yaml`.

## Persistance : `json_text` et insertions par lots

- Les colonnes `Simulation.state`, `Simulation.pending`, `Checkpoint.state` et `GameEventRecord.payload` utilisent le type Doctrine `json_text` (`src/Doctrine/Type/JsonTextType.php`) : du JSON stocké comme `LONGTEXT`, **jamais le type `json` natif de MySQL**. On ne les interroge pas en SQL, et le type natif réordonne les clés des objets et ralentit les écritures. Avec `json_text`, une valeur relue est identique à celle écrite, **clés dans le même ordre** : les tests peuvent comparer avec `assertSame`.
- `SimulationService::run()` écrit les événements et les checkpoints avec `BulkInserter` (INSERT multi-lignes : 500 événements ou 50 checkpoints par requête, hors UnitOfWork) dans une seule transaction (`wrapInTransaction`).
- Après un changement de constructeur de service, le conteneur du mode test se reconstruit tout seul (`APP_DEBUG=1` dans `phpunit.xml.dist`) ; `SHELL_VERBOSITY=-1` limite le journal aux erreurs.

## Pièges rencontrés lors de l'intégration

- **Dossier synchronisé par iCloud (`~/Documents`)** : c'était la cause de tous les blocages. Installer `vendor/` y générait des copies de conflit (`Request 2.php`, `Kernel 3.php`…), et iCloud a même dupliqué des références dans `.git` (`refs/heads/dev 2`), ce qui cassait `git fetch`. Composer ne rendait jamais la main après « Generating autoload files ». Le dépôt a donc été déplacé hors d'iCloud, dans `~/GitHub/`. Ne pas le remettre sous `~/Documents` ou `~/Desktop`. (Le contournement `vendor.nosync` n'est plus utilisé.)
- **Symfony Flex** : il avait l'air de bloquer, mais c'était iCloud. Hors iCloud il fonctionne et il est installé. Il épingle la famille Symfony (`extra.symfony.require: 7.4.*`), ce qui évite de retomber sur un mélange 7.4 / 8.x (`symfony/var-exporter` 8 est incompatible avec Doctrine). La config existait déjà : ses recettes ont ajouté `cache.yaml`, `routing.yaml`, `config/routes/`, `preload.php`, `symfony.lock`, `bin/phpunit`, des `.gitignore` de dossiers, et un bloc dans `.env`. Nettoyage fait après coup : bloc `.env` dupliqué retiré (il remettait un `DATABASE_URL` Postgres), `compose.yaml` / `compose.override.yaml` / `.env.dev` supprimés, script `assets:install` retiré. `DEFAULT_URI` (requis par `routing.yaml`) est dans `.env`.
- Après chaque `composer require` / `update` : relire le diff de `.env`, supprimer `compose*.yaml` ajouté par une recette, et vérifier `composer show | grep ' 8\.'` (vide).
- `make:migration` n'est pas installé : utiliser `doctrine:migrations:diff`. Les migrations sont écrites pour MySQL (SQL propre à MySQL) ; ne pas les rejouer sur une autre base.
- `config/reference.php` est généré automatiquement par Symfony et change selon l'environnement : le versionner tel quel.
- Le `memory_limit` du serveur doit rester confortable (le test d'API le fixe à 512 Mo).
