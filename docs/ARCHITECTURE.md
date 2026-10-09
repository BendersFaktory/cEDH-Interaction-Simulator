# Architecture

## Vue d'ensemble

```
Vue (reducer, store)  ──HTTP──►  SimulationController
                                       │
                                SimulationService  ──►  Doctrine (Simulation, GameEventRecord, Checkpoint)
                                       │
                                  GameEngine  (PHP pur, src/Engine)
                                  ├── TurnManager        étapes et tours (untap/cleanup sans priorité)
                                  ├── PriorityManager    boucle de priorité
                                  ├── StackResolver      résolution du sommet de pile
                                  ├── StateBasedActions  vérifiées avant chaque octroi de priorité
                                  ├── TriggerManager     (stub) déclencheurs, ordre APNAP
                                  ├── LegalActionGenerator
                                  └── AgentRegistry → AutoPassAgent | HeuristicAgent | HumanAgent
```

## Fonction centrale

```php
GameEngine::advance(GameState $state, ?Action $decision = null): AdvanceResult
```

Fait avancer la partie jusqu'à sa fin **ou** jusqu'à ce qu'un `HumanAgent` doive décider. `AdvanceResult` contient les événements produits pendant l'appel, la `DecisionRequest` en attente (ou `null`) et `over`. Le `GameState` est muté en place.

## La boucle de priorité (`PriorityManager::runRound`)

À chaque tour de boucle :

1. Si la priorité du détenteur n'a pas été « annoncée » : actions basées sur l'état → réparation du détenteur s'il a été éliminé → déclencheurs sur la pile → événement `PriorityGranted` → **checkpoint** si c'est un début de ronde (personne n'a encore passé) ou si la pile n'est pas vide.
2. Les actions légales du détenteur sont calculées ; son agent décide (ou la décision injectée est validée).
3. Selon l'action :
   - **Passer** : ajouté à `passed`. Si tous les joueurs en vie ont passé : pile vide → fin de l'étape ; sinon résolution du sommet, puis nouvelle ronde commençant par le joueur actif. Sinon la priorité passe au joueur suivant.
   - **Lancer un sort** : le sort va sur la pile, `passed` est remis à zéro, le lanceur reçoit à nouveau la priorité.
   - **Jouer un terrain** : action spéciale, pas de pile, pas de nouvelle ronde (le joueur garde la priorité).

`PriorityState::$announced` évite de ré-émettre `PriorityGranted` quand une simulation suspendue est reprise : c'est ce qui rend la reprise identique à une exécution continue.

## Suspension et reprise (le joueur humain)

`HumanAgent::decide()` lève `DecisionRequired` **avant toute mutation** du `GameState`. `GameEngine::advance()` l'attrape et renvoie la `DecisionRequest` (actions légales, pile, priorité). L'état sauvegardé est donc exactement le point de décision. Pour reprendre : `advance($state, $action)` ; la décision est validée contre les actions légales recalculées (`IllegalActionException` sinon) puis consommée au premier octroi de priorité.

Deux agents humains :

- `human` : demande toujours.
- `human:auto` : répond « passer » automatiquement tant qu'aucune action autre que passer n'est légale. La décision est quand même prise et journalisée.

## Snapshots et fork

- `CheckpointListener::onCheckpoint()` est appelé par le moteur juste après chaque `PriorityGranted` marqué `checkpoint`. Le moteur ne change pas, mais `SimulationService` ne persiste qu'une **ancre** (`GameState::toArray()` dans `Checkpoint`) : `SparseCheckpoints` en garde une par tour (paramètre `app.checkpoint_every_n_turns`), plus une à chaque pause humaine (colonne `decision` = `actionId` à rejouer, posée par `decide()`) et une au point de bifurcation d'un fork (état avec `controllers` surchargés).- **Reconstruction** : `StateReconstructor::at($sim, $seq)` prend l'ancre la plus proche ≤ `seq` (en remontant la chaîne des parents), restaure le `GameState`, rejoue `advance` (avec la `decision` de l'ancre) et s'arrête au dernier checkpoint ≤ `seq` (un listener lève `ReplayStop` dès que la cible est dépassée ; `DecisionRequired` clôt aussi le rejeu). Le rejeu ne persiste rien.
- **Fork** : reconstruire le checkpoint ≤ `atEvent`, remplacer des clés dans `controllers` (ex. `[1 => 'human:auto']`), créer une `Simulation` fille (`parent`, `forkEventSeq`) et relancer `advance`.
- **Journal d'une branche** = événements de ses ancêtres jusqu'à `forkEventSeq` + les siens. Le numéro `seq` est unique le long de la chaîne.
- Pourquoi ça marche : état 100 % sérialisable, RNG dans l'état (`Rng`, xorshift32), agents sans état.

Volume actuel : ~130 ancres (2,6 Mo) pour une partie 4 IA, contre 897 snapshots (28,5 Mo) en stockage dense. Les octrois sans `checkpoint` n'ont pas de point de bifurcation ; si on demande un instant sans checkpoint, la reconstruction recolle au précédent (le moteur est déterministe, la reprise est identique). Une évolution du format de `GameState` invalide les ancres anciennes (en dev : recréer les parties).

## Front

Le store Pinia garde `events[]` et `cursor`. L'état affiché est **toujours dérivé** : `replay(events, cursor)`. Lecture, pause, pas à pas, retour arrière et saut depuis le journal sont tous le même mécanisme. Le point de bifurcation proposé est le dernier `PriorityGranted` ≤ curseur. Après un fork, le store charge la branche fille jusqu'à la fin ; si la simulation attend une décision, `ActionPanel` affiche les actions légales.

## Commander

- **Format** : 4 joueurs, 40 PV, mini-decks de démo (1 commandant + 39 cartes). `GameFactory::create()` place le commandant en zone de commandement (`PlayerState::$command`).
- **Lancer un commandant** : `LegalActionGenerator` considère main **et** zone de commandement ; `PlayerState::castCost()` ajoute la taxe (+2 par lancement précédent, `commanderCasts`), compteur incrémenté à chaque lancement même si le sort est contré.
- **Remplacement** : `GameState::sendToGraveyard()` renvoie un commandant en zone de commandement au lieu du cimetière (résolution et contre). Toujours passer par cette méthode.
- **Dégâts de commandant** : `PlayerState::$commanderDamage` + élimination à 21 (`StateBasedActions`) sont en place ; seul le combat (à venir) les alimentera.
- **Ton siège** : `GameFactory::create($seed, [0 => 'human:auto', 1 => 'ai:heuristic', ...])`. La simulation se suspend dès que J0 a une action utile ; un fork permet ensuite de changer de ligne depuis n'importe quel checkpoint.

## Sécurité du modèle

- Le client n'envoie qu'un `actionId` ; le serveur recalcule les actions légales.
- Les agents connus sont une liste fermée (`AgentRegistry::keys()`) ; le service valide les clés reçues.
- L'aléa non déterministe (seed par défaut) n'existe que dans le contrôleur, jamais dans le moteur.
