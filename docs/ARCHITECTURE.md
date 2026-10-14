# Architecture

## Vue d'ensemble

```
Vue (reducer, store)  ──HTTP──►  SimulationController
                                       │
                                SimulationService  ──►  Doctrine (Simulation, GameEventRecord, Checkpoint)
                                       │
                                  GameEngine  (PHP pur, src/Engine)
                                  ├── TurnManager        étapes et tours (untap sans priorité ; cleanup : voir « Triggers »)
                                  ├── PriorityManager    boucle de priorité
                                  ├── CombatManager      déclarations d'attaquants/bloqueurs (+ CombatResolver : dégâts)
                                  ├── HandSizeManager    défausse à 7 au cleanup (décision hors priorité)
                                  ├── StackResolver      résolution du sommet de pile
                                  ├── StateBasedActions  vérifiées avant chaque octroi de priorité
                                  ├── TriggerManager     triggers, ordre APNAP
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

1. Si la priorité du détenteur n'a pas été « annoncée », ou si des triggers attendent : actions basées sur l'état → triggers sur la pile (voir ci-dessous) → réparation du détenteur s'il a été éliminé → événement `PriorityGranted` → **checkpoint** si c'est un début de ronde (personne n'a encore passé) ou si la pile n'est pas vide.
2. Les actions légales du détenteur sont calculées ; son agent décide (ou la décision injectée est validée).
3. Selon l'action :
   - **Passer** : ajouté à `passed`. Si tous les joueurs en vie ont passé : pile vide → fin de l'étape ; sinon résolution du sommet, puis nouvelle ronde commençant par le joueur actif. Sinon la priorité passe au joueur suivant.
   - **Lancer un sort** : le sort va sur la pile, `passed` est remis à zéro, le lanceur reçoit à nouveau la priorité.
   - **Jouer un terrain** : action spéciale, pas de pile, pas de nouvelle ronde (le joueur garde la priorité).

`PriorityState::$announced` évite de ré-émettre `PriorityGranted` quand une simulation suspendue est reprise : c'est ce qui rend la reprise identique à une exécution continue.

## Triggers

- **Détection explicite**, jamais par lecture du journal : `GameState::enterBattlefield()` (seul chemin d'entrée en jeu : sort résolu, terrain joué, jeton créé) met en file les `etb` ; la mort par SBA et `GameState::sacrifice()` d'une créature (jeton compris) les `dies` ; `TurnManager::beginStep` à l'entretien les `upkeep` des permanents du joueur actif.
- **File sérialisée** `GameState::$pendingTriggers` (`PendingTrigger` : contrôleur, copie de la source, rang du trigger). La source est une copie : information de dernière connaissance pour une créature morte, et aucun état partagé avec le champ de bataille (sinon un état reconstruit depuis une ancre divergerait). Même règle pour la source d'une capacité activée sur la pile.
- **Mise sur la pile** (`TriggerManager`, avant chaque `PriorityGranted`) : APNAP — le joueur actif pose tous les siens, puis chaque joueur dans le sens du tour. Choix d'ordre ou de cible = décision hors priorité, comme la défausse : le contrôleur occupe `priority->holder`, l'ancien détenteur est gardé dans `triggerResumeHolder` et retrouve la priorité ensuite, passes remises à zéro. Un seul choix possible : posé sans décision. Les triggers d'un joueur éliminé disparaissent.
- **Résolution** : `StackResolver` traite un trigger comme une capacité activée (mêmes effets : `damage`, `draw`, `token`). Un contresort ne le cible pas.
- Événements : `etb`, `dies`, `upkeep`, `attacks` (déclaration d'attaquant), `end_step` (fin de tour de son contrôleur), `cast` (sort lancé par son contrôleur, `filter` = `instant_sorcery` possible), `discard` (défausse de son contrôleur). Catalogue dans `docs/EVENTS.md`.
- **Cleanup (règle 514.3a)** : `GameEngine::cleanup()` fait la défausse à 7, puis, si des SBA ont agi ou si des triggers attendent, ouvre une ronde de priorité complète et recommence une étape de cleanup. `GameState::$cleanupRound` marque cette ronde (sauvegardée dans le snapshot) : une reprise après une décision ne rejoue pas la défausse. Pas de ronde sinon.

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
- **Mots-clés de combat** : `CardInstance::$keywords` (constantes `FLYING`…`LIFELINK`, `hasKeyword()`). Légalité des déclarations dans `LegalActionGenerator` (vol/portée, menace, célérité, défenseur), vigilance dans `CombatManager::declareAttacker`, dégâts dans `CombatResolver::resolve($s, $firstStrike)` appelé par les étapes `first_strike_damage` (sautée sans initiative) et `combat_damage` ; contact mortel = `CardInstance::$deathtouchDamage`, lu par `StateBasedActions`.
- **Dégâts de commandant** : `PlayerState::$commanderDamage` + élimination à 21 (`StateBasedActions`) sont en place ; seul le combat (à venir) les alimentera.
- **Ton siège** : `GameFactory::create($seed, [0 => 'human:auto', 1 => 'ai:heuristic', ...])`. La simulation se suspend dès que J0 a une action utile ; un fork permet ensuite de changer de ligne depuis n'importe quel checkpoint.

## Mana

- **Modèle** (`Engine/Mana/`) : `Color` (enum `White…Colorless`, `symbol()` = lettre WUBRGC, seul format sérialisé), `ManaCost` (générique, coloré, hybride, X ; `parse('{X}{2}{R/G}{U}')`, forme canonique via `__toString`), `ManaPool` (réserve par couleur ; **persiste jusqu'à la fin de l'étape**, vidée par `TurnManager::endStep` avec un événement `ManaPoolEmptied` si elle n'était pas vide ; une partie libre et une partie restreinte, voir « Mana conditionnel »). `CardInstance` porte `manaCost` (chaîne, `null` = `{cost}` générique), `produces` (terrains), `abilities` (capacités activées) et `adds` (mana ajouté par un rituel) ; `cost` reste la valeur de mana entière.
- **Capacités activées** (`Engine/Ability/`) : `ActivatedAbility` (coût `{T}` et/ou mana, effet `mana` ou `damage`, `isManaAbility`) est le **seul** modèle. Les terrains n'ont pas de cas particulier : `CardInstance::abilities()` leur synthétise une capacité de mana par lettre de `produces`. `AbilityManager::activate()` est le seul chemin d'activation : une capacité de mana (`isManaAbility`) est **résolue immédiatement** (règle 605 : ni pile, ni nouvelle ronde, le joueur garde la priorité, aucune passe remise à zéro, événement `ManaAbilityActivated`) ; une autre capacité va sur la pile (`StackItem::$ability`, la source ne change pas de zone), remet les passes à zéro et se résout via `StackResolver` (`AbilityActivated` / `AbilityResolved`). Action : `ActivateAbility` (id `ability:P:CARD:IDX:OPT:CIBLES`).
- **Paiement** : `ManaPayment::plan()` est une recherche exacte déterministe sur la réserve et les capacités de mana des permanents non engagés ; elle rend un `ManaPlan` (activations + mana dépensé). Ordre de préférence : réserve d'abord, moins de gaspillage, moins d'engagements, sources multicolores préservées. `PlayerState::payWithPlan` l'exécute ; le surplus (Sol Ring pour un `{1}`) reste dans la réserve. `maxX()` donne le X maximal ; une action par X (0 à 10) pour un sort à {X} (`CastSpell::$x`). L'humain peut activer lui-même ses sources de mana (choix des terrains) : la réserve est dépensée avant tout nouvel engagement. `HumanAgent` en auto-pass ignore ces activations de mana (sinon la moindre terre arrêterait la partie).
- **Mana conditionnel** : `ManaRestriction` (`kind`, `allows($cartePayée)`) ; une option de capacité de mana peut être restreinte (`ActivatedAbility::mana('{C}{C}@eldrazi')`, `restriction($option)`). `activateManaSource` range ce mana dans la partie restreinte de la réserve (`ManaPool::add(..., $restriction)`). `ManaPayment::plan(..., $for)` reçoit la carte payée (sort lancé, ou source de la capacité) : le mana restreint de la réserve et les options restreintes ne comptent que si la restriction admet `$for` ; le mana restreint de la réserve paie le coût demandé avant la réserve libre (jamais le coût d'un convertisseur) ; une option restreinte n'est candidate que sans convertisseur et n'est retenue que si elle fait strictement mieux selon la clé de choix. `ManaPlan::$spendRestricted` dit quelle part de `spend` vient de la partie restreinte. Eldrazi Temple / Eldrazi Mimic dans les decks de démo.
- **Types multiples** : `CardInstance::$types` (liste ; le constructeur accepte une chaîne ou une liste), `hasType()` ; sérialisé sous `type` (chaîne si un seul type). `GameState::FORMAT_VERSION` versionne `toArray()` (clé `version`).
- **Sous-types** : `CardInstance::$subtypes` = table type → sous-types (`['creature' => ['Eldrazi']]`), validée à la construction par le catalogue `Card/Subtypes` (un sous-type doit être admis pour le type de la carte : un artefact Eldrazi lève une exception). `hasSubtype($sous-type, $type)`, `isColorless()` (déduit du coût de mana).
- **Rituels** : sort `effect 'mana'` ; à la résolution, `adds` est ajouté à la réserve (`ManaAdded`).
- **Taxe de commandant** : `PlayerState::castCost()` renvoie un `ManaCost` avec +2 de générique par lancement précédent.
- **X** : la valeur choisie est portée par `StackItem::$x` ; un sort de dégâts dont le coût contient {X} inflige X.
- **Limites** : pas de capacité de mana avec un coût de mana (Signets), mana conditionnel limité à la restriction `eldrazi`, pas de créatures à mana (mal d'invocation géré mais non exercé), pas d'identité de couleurs ; la recherche de paiement est bornée en nœuds.

## Sécurité du modèle

- Le client n'envoie qu'un `actionId` ; le serveur recalcule les actions légales.
- Les agents connus sont une liste fermée (`AgentRegistry::keys()`) ; le service valide les clés reçues.
- L'aléa non déterministe (seed par défaut) n'existe que dans le contrôleur, jamais dans le moteur.
