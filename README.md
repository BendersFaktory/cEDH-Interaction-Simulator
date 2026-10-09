# MTG Sim

Simulateur **Magic: The Gathering — Commander à 4 joueurs** : tu occupes un siège (J0 par défaut) face à 3 IA. À tout moment tu peux mettre en pause, revenir à un point de priorité, **bifurquer** (fork), jouer autrement et voir comment les IA réagissent. Tu peux aussi lancer 4 IA et regarder.

```
mtg-sim/
├── backend/    Moteur PHP pur (src/Engine) + glue Symfony (Controller, Entity, Service) + tests
├── frontend/   Vue 3 + Pinia + Vite : lecteur de replay, fork, panneau d'actions
├── docs/       Architecture, catalogue d'événements, roadmap, intégration Symfony
└── CLAUDE.md   Règles d'or et contexte pour reprendre le travail avec Claude
```

## Démarrage rapide

**1. Voir le moteur tourner (aucune dépendance, juste PHP ≥ 8.3)**

```bash
php backend/bin/smoke.php 42
```

**2. Lancer les tests du moteur**

```bash
cd backend && composer install && vendor/bin/phpunit
```

**3. Brancher Symfony** : le dossier `backend/` contient `src/` et `tests/` mais **pas** un kernel Symfony complet. Suis `docs/SYMFONY_SETUP.md` (≈ 10 min).

**4. Front**

```bash
cd frontend && npm install && npm test && npm run dev   # http://localhost:5173, /api proxifié vers :8000
```

## Comment ça marche, en bref

- Le moteur joue la partie côté serveur et produit un **journal d'événements** (`CardDrawn`, `SpellCast`, `PriorityGranted`, ...).
- Le front **rejoue** ces événements (lecture, pause, pas à pas, retour arrière) via un reducer.
- Au début de chaque ronde de priorité, et à chaque octroi quand la pile n'est pas vide, le moteur enregistre un **checkpoint** (snapshot complet, RNG compris).
- **Fork** = on restaure un checkpoint, on remplace l'agent d'un ou plusieurs joueurs par `human:auto`, et la simulation repart : le moteur suspend quand c'est à toi de décider, les IA restantes jouent normalement et peuvent **contrer tes sorts**, car la ronde de priorité n'est jamais court-circuitée.

Détails : `docs/ARCHITECTURE.md`.

## Ce que le squelette fait déjà

- **Commander à 4** : 40 PV, commandant en zone de commandement, **taxe de commandant** (+2 par lancement), retour du commandant en zone de commandement s'il est contré, limite de 21 dégâts de commandant (la règle est en place, mais rien ne l'alimente tant que le combat n'existe pas).
- Tour complet : pioche, terrain, sorts instantanés / créatures, pile, priorité, contresorts, dégâts, élimination, fin de partie.
- **Ton siège** : `human:auto` te demande une décision dès que tu as une action utile, et passe tout seul sinon (les passes restent journalisées).
- IA heuristique (contre le sort adverse au sommet de la pile, joue des terrains, lance des sorts).
- Snapshots, fork, reprise déterministe (testée : même seed → mêmes événements ; reprise depuis un snapshot → mêmes événements que la partie d'origine).
- API REST complète (création, événements, état, fork, décisions) et UI de base.

## Ce qu'il ne fait pas encore

**Combat** (donc aucun dégât de commandant pour l'instant : les parties se terminent par pioche vide ou par éclairs), capacités déclenchées/activées, mana coloré, vraies cartes (Scryfall), `TargetPicker` et `BranchTree` côté UI. Les decks de démo sont des mini-Commander (1 commandant + 39 cartes). Voir `docs/ROADMAP.md`.
