# AGENTS.md

Instructions pour les agents de code (Claude Code, Codex, Cursor, etc.) travaillant sur ce dépôt. Le contexte détaillé en français est dans [CLAUDE.md](CLAUDE.md) : le lire en premier. Ce fichier en est le résumé opérationnel.

## Projet

Simulateur Magic: The Gathering **Commander à 4 joueurs** : un siège humain (J0, agent `human:auto`) face à 3 IA, avec pause, retour à un point de priorité et fork. Stack : moteur PHP pur (`backend/src/Engine/`), couche Symfony 7.4 (`backend/src/{Controller,Entity,Service}`, testée par `backend/tests/Api/`), front Vue 3 / Pinia (`frontend/`).

## Commandes

```bash
# Moteur (sans composer)
php backend/bin/smoke.php 42        # partie complète à 4 IA
# Backend (28 tests : moteur + API ; nécessite MySQL démarré et backend/.env.local, cf. docs/SYMFONY_SETUP.md)
cd backend && composer install && vendor/bin/phpunit
cd backend && php bin/console doctrine:migrations:migrate --no-interaction && php -S 127.0.0.1:8000 -t public
# Frontend
cd frontend && npm install && npm test && npm run typecheck && npm run build
# Fixture du contrat PHP <-> reducer (à régénérer si le format d'événements change)
php backend/bin/export-fixture.php 42 > frontend/src/engine/__fixtures__/game.json
```

Avant de conclure un changement : `vendor/bin/phpunit`, `npm test`, `npm run typecheck` doivent passer.

## Règles à ne pas casser

1. `Engine/` est du PHP pur : aucune dépendance Symfony/Doctrine.
2. Déterminisme : jamais `rand()`, `random_int()`, `shuffle()`, `time()` dans `Engine/`. L'aléa passe par `GameState::$rng`.
3. Tout l'état est dans `GameState` (`toArray()` / `fromArray()`). Pas d'état caché ; les agents sont sans état.
4. Le moteur ne saute jamais une priorité : chaque joueur en vie est interrogé, et chaque `PriorityPassed` est journalisé. L'auto-pass est une politique de l'agent, pas du moteur.
5. Un sort lancé remet `priority->passed` à zéro et rend la priorité au lanceur ; la pile se résout quand tous les joueurs en vie ont passé consécutivement.
6. On ne fork que sur un checkpoint (`PriorityGranted` avec `checkpoint: true`).
7. Le front est « bête » : il applique les événements (`src/engine/reducer.ts`), aucune règle de jeu. Les payloads portent des valeurs absolues.
8. Ne jamais faire confiance au client : `POST /decisions` envoie un `actionId`, le serveur recalcule les actions légales.
9. Commander : toujours `GameState::sendToGraveyard()` (jamais `->graveyard[] =`) et `PlayerState::castCost()` pour tout coût.

## Ajouter un type d'événement

1. `GameState::emit('NomEvenement', [...])` (valeurs absolues).
2. Documenter dans `docs/EVENTS.md`.
3. Mettre à jour `frontend/src/engine/reducer.ts` et `describe.ts`.
4. Régénérer la fixture, puis `npm test`.

## Conventions

- Code, commentaires et docs en français, comme le reste du dépôt ; imiter le style des fichiers voisins.
- PHP : `declare(strict_types=1)`, classes `final` par défaut, PSR-4 (`App\` -> `backend/src`, `App\Tests\` -> `backend/tests`).
- Ne pas cloner le dépôt sous `~/Documents` ou `~/Desktop` (iCloud corrompt `vendor/` et `.git`). Symfony Flex est installé : relire le diff de `.env` après un `composer require` (cf. CLAUDE.md). La base est MySQL : le mot de passe reste dans `backend/.env.local` / `.env.test.local` (non versionnés).
- Ne pas committer `backend/vendor/`, `node_modules/`, `dist/`. Les lock files (`composer.lock`, `package-lock.json`) sont versionnés.
- Workflow Git : travailler uniquement sur `dev` (vérifier avec `git branch --show-current` avant toute modification), PR `dev` → `staging` à chaque commit, `master` réservée à l'utilisateur. Message de commit à demander s'il n'est pas fourni ; titre de PR toujours `{message de commit} [staging]`. Détail dans la section « Workflow Git » de [CLAUDE.md](CLAUDE.md).

## Où en est le projet

Moteur, couche Symfony (tests d'API, scénario curl), reducer et build front sont vérifiés. Le front n'a pas encore été ouvert dans un navigateur contre l'API réelle. Pas encore de combat : les parties se terminent par pioche vide. Voir `docs/ROADMAP.md`.
