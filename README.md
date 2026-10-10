# MTG Sim

Simulateur **Magic: The Gathering — Commander à 4 joueurs** : tu occupes un siège (J0 par défaut) face à 3 IA. À tout moment tu peux mettre en pause, revenir à un point de priorité, **bifurquer** (fork), jouer autrement et voir comment les IA réagissent. Tu peux aussi lancer 4 IA et regarder.

## Fonctionnalités

- **Règles Commander** : 40 PV, commandant en zone de commandement, taxe de commandant, retour du commandant en zone de commandement, élimination à 21 dégâts de commandant.
- **Tour complet** : toutes les phases et étapes, pile et priorité (contresorts compris), combat à 4 joueurs, défausse en fin de tour, élimination et fin de partie.
- **Mana** : couleurs, hybrides, coûts X, sources non-terrain, paiement automatique ou manuel ; **capacités activées** (capacités de mana résolues immédiatement, les autres sur la pile).
- **Ton siège** (`human:auto`) : le moteur te demande une décision dès que tu as une action utile et passe pour toi sinon (les passes restent journalisées).
- **IA heuristique** pour les autres sièges : terrains, sorts, contresorts, attaques et blocages.
- **Fork déterministe** : même graine → même partie ; reprendre depuis n'importe quel point de priorité rejoue exactement la même suite.
- **API REST** (création, journal, état, fork, décisions) et **interface Vue** (lecteur de replay, panneau d'actions, fork).

L'avancement détaillé et les travaux prévus sont dans [docs/ROADMAP.md](docs/ROADMAP.md).

## Démarrage rapide

Prérequis : PHP ≥ 8.3, Composer, MySQL 8+, Node 18+.

```bash
php backend/bin/smoke.php 42    # joue une partie complète à 4 IA, sans base ni dépendance
```

Installation complète (base, API, interface) : **[docs/INSTALL.md](docs/INSTALL.md)**.

## Comment ça marche

- Le moteur joue la partie côté serveur et produit un **journal d'événements** (`CardDrawn`, `SpellCast`, `PriorityGranted`, ...).
- Le front **rejoue** ces événements (lecture, pause, pas à pas, retour arrière) via un reducer, sans aucune règle de jeu.
- Chaque début de ronde de priorité (et chaque octroi quand la pile n'est pas vide) est un **checkpoint**. Seules des **ancres** sont stockées (une par tour, plus les pauses et les forks) ; les autres checkpoints se reconstruisent par rejeu déterministe (RNG compris).
- **Fork** = on restaure un checkpoint, on remplace l'agent d'un ou plusieurs joueurs par `human:auto`, et la simulation repart : le moteur suspend quand c'est à toi de décider, les IA restantes jouent normalement et peuvent **contrer tes sorts**, car la ronde de priorité n'est jamais court-circuitée.

## Stack

- **Moteur** : PHP pur (`backend/src/Engine/`), sans dépendance au framework, testable seul.
- **API** : Symfony 7.4 + Doctrine, persistance MySQL.
- **Front** : Vue 3 + Pinia + Vite (TypeScript), tests Vitest, recette navigateur Playwright.

```
cEDH-Interaction-Simulator/
├── backend/    Moteur PHP pur (src/Engine) + app Symfony (Controller, Entity, Service) + tests
├── frontend/   Vue 3 + Pinia + Vite : lecteur de replay, fork, panneau d'actions ; e2e/ : recette Playwright
├── docs/       Installation, architecture, catalogue d'événements, intégration Symfony, roadmap
└── CLAUDE.md   Règles d'or et contexte pour travailler avec Claude
```

## Documentation

| Document | Contenu |
|---|---|
| [docs/INSTALL.md](docs/INSTALL.md) | Installer et lancer le projet chez soi, dépannage |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Moteur, priorité, checkpoints, fork |
| [docs/EVENTS.md](docs/EVENTS.md) | Catalogue des événements |
| [docs/SYMFONY_SETUP.md](docs/SYMFONY_SETUP.md) | Détails de l'app Symfony : routes, mesures, persistance, pièges |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Avancement et travaux prévus |
| [CLAUDE.md](CLAUDE.md) | Règles d'or du projet |

## Contribuer

- Branche de travail `dev`, PR vers `staging` ; `master` est gérée par le mainteneur.
- Avant une PR : `cd backend && vendor/bin/phpunit`, `cd frontend && npm test && npm run build`.
- Nouveau type d'événement : suivre la checklist de [CLAUDE.md](CLAUDE.md).
