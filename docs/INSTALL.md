# Installer et lancer MTG Sim

Guide pas à pas pour faire tourner le projet sur sa machine (exemples macOS ; Linux est équivalent avec le gestionnaire de paquets de la distribution).

## 1. Prérequis

| Outil | Version | Remarque |
|---|---|---|
| PHP | ≥ 8.3 (testé en 8.4) | extensions `ctype`, `iconv`, `pdo_mysql` |
| Composer | 2.x | |
| MySQL | 8+ (testé en 9.3) | |
| Node.js / npm | 18+ (testé en 22) | |
| git | | |

macOS avec Homebrew :

```bash
brew install php composer mysql node
```

## 2. Cloner le dépôt

```bash
mkdir -p ~/GitHub && cd ~/GitHub
git clone <url-du-depot> cEDH-Interaction-Simulator
cd cEDH-Interaction-Simulator
```

> **Ne jamais cloner sous `~/Documents` ou `~/Desktop`** si ces dossiers sont synchronisés par iCloud : iCloud y crée des copies de conflit (`Request 2.php`, `dev 2` dans `.git`…) qui corrompent `vendor/` et `.git`, et Composer reste bloqué.

Test immédiat, sans base ni dépendance :

```bash
php backend/bin/smoke.php 42    # joue une partie Commander complète à 4 IA
```

## 3. MySQL

Démarrer le serveur :

- Homebrew : `brew services start mysql`
- Installeur Oracle : Réglages Système → MySQL → démarrer (client dans `/usr/local/mysql/bin/mysql`)

Puis, une seule fois, avec `mysql -u root -p` :

```sql
CREATE DATABASE mtg_sim      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE mtg_sim_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mtgsim'@'localhost' IDENTIFIED BY 'MOT_DE_PASSE';
GRANT ALL PRIVILEGES ON mtg_sim.*      TO 'mtgsim'@'localhost';
GRANT ALL PRIVILEGES ON mtg_sim_test.* TO 'mtgsim'@'localhost';
```

Choisir un mot de passe **sans `@ : / # %`** (il est placé dans une URL).

## 4. Configuration

Le fichier versionné `backend/.env` contient `CHANGEME` à la place du mot de passe : ne pas le modifier. Créer deux fichiers **non versionnés** :

`backend/.env.local`
```
DATABASE_URL="mysql://mtgsim:MOT_DE_PASSE@127.0.0.1:3306/mtg_sim?serverVersion=9.3.0&charset=utf8mb4"
```

`backend/.env.test.local` (Symfony ne charge pas `.env.local` en environnement `test`)
```
DATABASE_URL="mysql://mtgsim:MOT_DE_PASSE@127.0.0.1:3306/mtg_sim_test?serverVersion=9.3.0&charset=utf8mb4"
```

Adapter `serverVersion` à sa version de MySQL (`mysql --version`, ex. `8.4.0`).

## 5. Backend (API)

```bash
cd backend
composer install
php bin/console doctrine:migrations:migrate --no-interaction   # crée les tables dans mtg_sim
php -d memory_limit=512M -S 127.0.0.1:8000 -t public           # laisser tourner
```

Vérification, dans un autre terminal (crée une partie à 4 IA) :

```bash
curl -X POST localhost:8000/api/simulations -H 'Content-Type: application/json' \
  -d '{"seed":42,"controllers":{"0":"ai:heuristic","1":"ai:heuristic","2":"ai:heuristic","3":"ai:heuristic"}}'
```

## 6. Frontend (interface)

Dans un second terminal, le backend tournant toujours :

```bash
cd frontend
npm install
npm run dev     # http://localhost:5173
```

Vite relaie `/api` vers `localhost:8000` : les deux serveurs doivent tourner en même temps.

## 7. Jouer

Ouvrir http://localhost:5173 et créer une partie : par défaut tu joues J0 face à 3 IA ; l'option « 4 IA » permet de regarder. Le moteur s'arrête quand tu as une décision à prendre. Dans le lecteur, tu peux revenir à un point de priorité et **forker** pour jouer une autre ligne.

## 8. Vérifier l'installation

```bash
cd backend  && vendor/bin/phpunit                  # moteur, API, Doctrine (MySQL doit tourner)
cd frontend && npm test && npm run build           # reducer + typage + build
```

Optionnel, parcours complet dans Chrome contre l'API réelle (backend lancé) :

```bash
cd frontend && npx playwright install chromium && npm run recette   # captures dans frontend/recette/
```

## 9. Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| `composer install` ne rend pas la main, fichiers `X 2.php` | dépôt dans un dossier iCloud | déplacer le dépôt dans `~/GitHub/`, supprimer `vendor/`, relancer |
| `Connection refused` | MySQL arrêté | démarrer le serveur (étape 3) |
| `Access denied for user` | `.env.local` absent ou mot de passe erroné / avec caractère spécial | vérifier l'étape 4 |
| Tests d'API en échec, base inconnue | `.env.test.local` absent ou base `mtg_sim_test` non créée | étapes 3 et 4 |
| `Allowed memory size exhausted` | `memory_limit` CLI à 128 Mo | lancer avec `php -d memory_limit=512M` |
| Erreur réseau ou 404 sur `/api` dans le front | backend non lancé sur le port 8000 | étape 5 |
| `DATABASE_URL` Postgres réapparu après un `composer require` | recette Symfony Flex | relire le diff de `backend/.env`, retirer le bloc ajouté et tout `compose*.yaml` |

Pour aller plus loin (routes, mesures, persistance, pièges Symfony) : [SYMFONY_SETUP.md](SYMFONY_SETUP.md).
