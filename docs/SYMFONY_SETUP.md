# Symfony : état réel de l'intégration

> Installation pas à pas : voir [INSTALL.md](INSTALL.md). Ce document détaille l'app Symfony (routes, mesures, persistance, pièges).

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

### Phases complètes + combat + défausse (mesuré le 2026-10-10, 4 IA, MySQL 9.3, N = 1)

Commande : `APP_DEBUG=0 php -d memory_limit=512M backend/bin/measure.php [seed...]` (médiane de 5 ; les parties créées sont supprimées ensuite).

| | Avant (1re table, étapes réduites) | **Après** (seed 42) | seed 6 | seed 16 |
|---|---|---|---|---|
| Événements (dont verbeux) | 4935 | **3294** (2447) | 3057 (2322) | 4318 (3316) |
| Ancres (lignes `checkpoint`) | 130 | **38** (1 par tour) | 33 | 48 |
| Taille : ancres / événements | 2,6 Mo / 0,24 Mo | **1,08 Mo / 0,16 Mo** | 0,94 / 0,15 Mo | 1,36 / 0,20 Mo |
| `create()` (service, médiane) | ~0,2 s | **~0,11 s** | ~0,10 s | ~0,13 s |
| `POST /api/simulations` (HTTP, 1re / suivantes) | ~0,2 s | **0,34 s / ~0,11 s** | | |
| `fork` au milieu | ~20-25 ms | **~13 ms** (HTTP : ~28 ms) | ~14 ms | ~6 ms |
| `state@` (reconstruction, pire écart entre ancres) | ~10-55 ms | **~3 ms** (à froid 17 ms au 1er appel du processus) ; HTTP : 45 ms à froid, ~9 ms ensuite | 3 ms | 3 ms |
| Plus grand écart entre ancres | n.m. | **128 événements** | 127 | 138 |
| Pic mémoire PHP (`APP_DEBUG=0`) | ~100 Mo | **~38 Mo** | 38 Mo | 42 Mo |

Lecture : l'hypothèse « ~2× plus d'événements » ne tient plus. Le combat termine les parties au tour ~33-48 au lieu de ~100+, ce qui compense les 9 étapes par tour : une partie a **moins** d'événements qu'avant les phases complètes (les ~2/3 sont des événements verbeux, 1 ancre par tour). Un tour fait au plus ~140 événements, la reconstruction reste à quelques ms : **N = 1 est conservé**. Limite : seules des parties « 4 IA » ont été mesurées ; la taille des ancres croîtra avec les vraies decks (100 cartes) et le mana/les triggers, à remesurer à ce moment-là.

#### Après le mana (couleurs, auto-paiement ; mesuré le 2026-10-10, seed 42)

3642 événements, 38 ancres, 1,28 Mo d'ancres + 0,18 Mo d'événements ; `create` ~0,15 s (+35 % : le solveur de paiement est appelé pour chaque sort envisagé à chaque priorité) ; `fork` ~30 ms ; reconstruction ~5-15 ms (pire écart de 133 événements : ~14 ms) ; pic mémoire ~25 Mo. N = 1 reste adapté.

#### Après les capacités activées et le mana avancé (solveur exact, réserve persistante, duals, Sol Ring, rituels ; mesuré le 2026-10-10, graines 42 / 6 / 16)

Seed 42 : 3805 événements (dont 2945 verbeux), 40 ancres, 1,56 Mo d'ancres + 0,20 Mo d'événements ; `create` ~0,21 s (graines 6 et 16 : ~0,25 et ~0,30 s pour 4048 et 4740 événements ; +40 % vs le mana de base : le solveur est maintenant une recherche exacte, appelée pour chaque sort envisagé) ; `fork` 8-22 ms ; reconstruction 1-17 ms (pire écart : 125-139 événements) ; pic mémoire 29-36 Mo. N = 1 reste adapté. Les activations de mana manuelles d'un humain ajoutent une ancre par pause (~35 Ko chacune).

#### Après la fin des capacités activées (créatures à mana, Signets, Treasure, coûts riches ; mesuré le 2026-10-10, graines 42 / 6 / 16)

| | seed 42 | seed 6 | seed 16 |
|---|---|---|---|
| Événements (dont verbeux) / ancres | 4751 (3585) / 49 | 4087 (3121) / 43 | 4204 (3232) / 47 |
| Taille : ancres / événements | 2,08 / 0,28 Mo | 1,83 / 0,23 Mo | 2,00 / 0,23 Mo |
| `create()` (service, médiane) | ~0,45 s | ~0,37 s | ~0,36 s |
| `fork` au milieu | ~34 ms | ~50 ms | ~41 ms |
| `state@` (pire écart entre ancres, 137-148 événements) | ~27 ms | ~25 ms | ~22 ms |
| Pic mémoire PHP (`APP_DEBUG=0`) | 34 Mo | 36 Mo | 36 Mo |

Lecture : `create` double environ par rapport à la mesure précédente (seed 42 : 0,21 → 0,45 s). Deux causes, mesurées séparément : les parties sont plus longues (jetons bloqueurs : 49 tours au lieu de 40 pour la seed 42, +25 % d'événements, ancres de 1,56 à 2,08 Mo), et le moteur seul coûte 30 à 45 % de plus par événement (seed 42, sans base : 36 → 53 µs par événement ; 133 → 252 ms pour la partie). Chaque priorité évalue maintenant plus de capacités (choix de coût, plans de paiement avec convertisseurs). Reconstruction et fork restent sous 50 ms : **N = 1 est conservé**. Piste si `create` devient gênant : profiler `LegalActionGenerator` / `AbilityManager::canActivate` (un plan de paiement par capacité à coût de mana à chaque priorité).

#### Après les triggers (Elvish Visionary, Perilous Myr, Idole de l'Érudit ; mesuré le 2026-10-11, graines 42 / 6 / 16)

| | seed 42 | seed 6 | seed 16 |
|---|---|---|---|
| Événements (dont verbeux) / ancres | 4429 (3385) / 41 | 4234 (3199) / 41 | 5410 (4220) / 54 |
| Taille : ancres / événements | 1,77 / 0,28 Mo | 1,78 / 0,26 Mo | 2,34 / 0,31 Mo |
| `create()` (service, médiane) | ~0,42 s | ~0,47 s | ~0,59 s |
| `fork` au milieu | ~51 ms | ~25 ms | ~36 ms |
| `state@` (pire écart entre ancres, 147-176 événements) | ~40 ms | ~51 ms | ~61 ms |
| Pic mémoire PHP (`APP_DEBUG=0`) | 31 Mo | 34 Mo | 41 Mo |

Lecture : le coût par événement de `create` ne bouge pas (seed 42 : ~96 µs par événement en base, contre ~95 avant) ; les écarts entre graines viennent de la longueur des parties (seed 16 : 54 tours). La reconstruction au pire écart monte à 40-61 ms (≤ 27 ms avant) : les écarts entre ancres sont plus longs (jusqu'à 176 événements) et les tours plus chargés (triggers, pioches). Toujours sous 100 ms : **N = 1 est conservé**. La file de triggers ajoute 3 clés à l'état (vides hors trigger) et une clé `triggers` aux seules cartes qui en ont.

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
