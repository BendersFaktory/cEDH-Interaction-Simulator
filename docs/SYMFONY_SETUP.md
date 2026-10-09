# Intégrer le squelette dans une vraie app Symfony

`backend/` contient le moteur, la glue Symfony et les tests, mais pas de kernel ni de config. Le plus sûr est de partir d'une app Symfony neuve et d'y copier le code.

> Ces étapes n'ont pas pu être exécutées lors de la génération du squelette (accès à Packagist bloqué). Elles sont standard, mais à valider au premier lancement.

## 1. Créer l'app

```bash
symfony new backend-app --version="7.*"        # ou: composer create-project symfony/skeleton backend-app
cd backend-app
composer require symfony/orm-pack
composer require --dev phpunit/phpunit symfony/test-pack
```

## 2. Copier le code

```bash
cp -r ../backend/src/*   src/
cp -r ../backend/tests/* tests/
cp -r ../backend/bin/smoke.php ../backend/bin/export-fixture.php bin/
```

(Conserve le `Kernel.php` généré par Symfony.)

## 3. Configuration

`config/services.yaml` — le moteur n'est pas un ensemble de services, on l'exclut de l'autowiring :

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
        exclude:
            - '../src/Engine/'
            - '../src/Entity/'
            - '../src/Kernel.php'
```

`config/routes.yaml` :

```yaml
controllers:
    resource: ../src/Controller/
    type: attribute
```

`config/packages/doctrine.yaml` : le mapping par attributs sur `src/Entity` est celui par défaut du recipe.

`.env.local` : `DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"` pour démarrer simplement.

## 4. Base de données

```bash
php bin/console doctrine:database:create
php bin/console make:migration      # requiert symfony/maker-bundle (dev)
php bin/console doctrine:migrations:migrate
```

Tables attendues : `simulation`, `game_event`, `checkpoint`. Sur une grosse partie les colonnes JSON de `checkpoint` sont volumineuses : prévoir Postgres/MySQL si SQLite devient lent.

## 5. Vérifier

```bash
vendor/bin/phpunit                  # 18 tests du moteur
php bin/smoke.php 42                # partie complète en CLI
symfony serve -d --port=8000
# Défaut = Commander à 4, tu es J0 (human:auto). Pour 4 IA : {"controllers":{"0":"ai:heuristic","1":"ai:heuristic","2":"ai:heuristic","3":"ai:heuristic"}}
curl -X POST localhost:8000/api/simulations -H 'Content-Type: application/json' -d '{"seed":42}'
curl localhost:8000/api/simulations/1/events | head -c 400
```

## 6. Premier scénario de fork (à la main)

```bash
# 1. Trouver un point de bifurcation (PriorityGranted avec checkpoint=true) et prendre son seq, ex. 300
curl -s localhost:8000/api/simulations/1/events | jq '[.[] | select(.type=="PriorityGranted" and .payload.checkpoint)][20].seq'

# 2. Forker en prenant le joueur 1
curl -X POST localhost:8000/api/simulations/1/fork -H 'Content-Type: application/json' \
     -d '{"atEvent": 300, "controllers": {"1": "human:auto"}}'

# 3. Lire la décision en attente, puis répondre
curl localhost:8000/api/simulations/2/pending
curl -X POST localhost:8000/api/simulations/2/decisions -H 'Content-Type: application/json' \
     -d '{"actionId": "pass:1"}'
```

## Points d'attention connus

- `SimulationController` s'appuie sur la résolution automatique de `{id}` → entité (`doctrine/doctrine-bundle` + `symfony/doctrine-bridge`, fournis par `orm-pack`).
- Le premier appel `POST /api/simulations` joue **toute** la partie de façon synchrone (≈ 5000 événements, ≈ 900 checkpoints en 4 IA ; en jouant J0, la simulation s'arrête dès ta première décision) : prévoir un timeout confortable ou, plus tard, un traitement asynchrone (Messenger).
- Un `flush()` unique en fin de `run()` : volontairement simple, à optimiser (insertions par lots) si ça devient lent.
