<?php

declare(strict_types=1);

namespace App\Engine\Rules;

use App\Engine\Action\Action;
use App\Engine\Action\BottomCard;
use App\Engine\Action\KeepHand;
use App\Engine\Action\Mulligan;
use App\Engine\Action\PregameChoice;
use App\Engine\Game\CardInstance;
use App\Engine\Agent\AgentRegistry;
use App\Engine\Game\GameState;
use App\Engine\Game\PriorityState;

/**
 * Avant-partie : mulligan de Londres (CR 103.5), premier mulligan gratuit en multijoueur (CR 103.5c).
 *
 * Chaque joueur, dans l'ordre du tour à partir du premier joueur, garde sa main ou prend un mulligan (main remise dans
 * la bibliothèque, mélange, 7 nouvelles cartes) ; une fois la main gardée, il met au fond de sa bibliothèque une carte
 * par mulligan au delà du premier, une action par carte. Comme la défausse du cleanup, ce n'est PAS une priorité : le
 * joueur qui décide occupe `priority->holder` (le serveur et le rejeu retrouvent l'action via LegalActionGenerator::for)
 * et aucun PriorityGranted n'est émis (donc aucun checkpoint : on ne forke pas avant la première priorité).
 *
 * Les décisions sont séquentielles et non simultanées comme le prévoit CR 103.5 : c'est sans effet sur des agents qui ne
 * regardent que leur propre main. L'état tient dans `GameState::$mulligan` ; le nombre de cartes à mettre au fond se
 * recalcule (voir `toBottom`), ce qui rend la reprise après DecisionRequired triviale.
 *
 * Une fois toutes les mains gardées, un joueur qui n'est pas le premier joueur et qui a en main une carte
 * `STATIC_PREGAME_LUCK` (Gemstone Caverns) décide de la mettre en jeu, avec un marqueur de chance, en exilant une autre
 * carte de sa main, ou de la garder (`PregameChoice`, une décision par carte ; `mulligan[id]['pregame']` les retient).
 */
final class MulliganManager
{
    public const HAND_SIZE = 7;
    private const MAX_DECISIONS = 500;

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly LegalActionGenerator $legal,
    ) {
    }

    /** Cartes qu'il reste à mettre au fond pour ce joueur (0 tant que sa main n'est pas gardée). */
    public static function toBottom(GameState $s, int $playerId): int
    {
        $m = $s->mulligan[$playerId] ?? null;
        if ($m === null || !$m['kept']) {
            return 0;
        }

        return max(0, count($s->player($playerId)->hand) - (self::HAND_SIZE - max(0, $m['taken'] - 1)));
    }

    /** Peut lever DecisionRequired (état intact, repris via $injected). */
    public function run(GameState $s, ?Action &$injected): void
    {
        for ($i = 0; $i < self::MAX_DECISIONS; $i++) {
            $playerId = $this->nextUndecided($s);
            if ($playerId === null) {
                $s->mulligan = null;
                $s->priority = new PriorityState($s->activePlayer);

                return;
            }

            $s->priority = new PriorityState($playerId, [], true);
            $legal = $this->legal->for($s, $playerId);

            if ($injected !== null) {
                $action = $this->legal->match($injected, $playerId, $legal);
                $injected = null;
            } else {
                $action = $this->agents->get($s->controllers[$playerId])->decide($s, $playerId, $legal);
            }

            match (true) {
                $action instanceof PregameChoice => $this->pregame($s, $playerId, $action),
                $action instanceof Mulligan => $this->mulligan($s, $playerId),
                $action instanceof KeepHand => $this->keep($s, $playerId),
                $action instanceof BottomCard => $this->bottom($s, $playerId, $action->cardId),
                default => throw new \LogicException('Décision de mulligan attendue : ' . $action::class),
            };
        }

        throw new \LogicException('Mulligan sans fin (garde-fou).');
    }

    /** Premier joueur, dans l'ordre du tour depuis le joueur actif, qui n'a pas fini (main non gardée ou cartes au fond). */
    private function nextUndecided(GameState $s): ?int
    {
        $ids = $s->alivePlayerIds();
        $start = (int) array_search($s->activePlayer, $ids, true);
        $ordered = [...array_slice($ids, $start), ...array_slice($ids, 0, $start)];
        foreach ($ordered as $id) {
            if (!($s->mulligan[$id]['kept'] ?? true) || self::toBottom($s, $id) > 0) {
                return $id;
            }
        }
        foreach ($ordered as $id) {
            if (self::pregameCard($s, $id) !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Carte d'avant-partie (Gemstone Caverns) encore à décider pour ce joueur : toutes les mains gardées et mises au fond, pas
     * premier joueur, au moins une autre carte en main à exiler ; null sinon.
     */
    public static function pregameCard(GameState $s, int $playerId): ?CardInstance
    {
        if ($s->mulligan === null || $playerId === $s->activePlayer || in_array(false, array_column($s->mulligan, 'kept'), true)) {
            return null;
        }
        foreach ($s->alivePlayerIds() as $id) {
            if (self::toBottom($s, $id) > 0) {
                return null;
            }
        }
        $hand = $s->player($playerId)->hand;
        if (count($hand) < 2) {
            return null;
        }
        foreach ($hand as $card) {
            if (in_array(CardInstance::STATIC_PREGAME_LUCK, $card->statics, true) && !in_array($card->id, $s->mulligan[$playerId]['pregame'] ?? [], true)) {
                return $card;
            }
        }

        return null;
    }

    /** Gemstone Caverns : en jeu avec un marqueur de chance, la carte choisie exilée de la main (`PregameCardPlaced`) ; ou gardée. */
    private function pregame(GameState $s, int $playerId, PregameChoice $a): void
    {
        $s->mulligan[$playerId]['pregame'][] = $a->sourceId;
        if ($a->cardId === null) {
            return;
        }
        $p = $s->player($playerId);
        $source = $p->takeFromHand($a->sourceId) ?? throw new \DomainException("Carte d'avant-partie absente de la main : $a->sourceId");
        $exiled = $p->takeFromHand($a->cardId) ?? throw new \DomainException("Carte à exiler absente de la main : $a->cardId");
        $source->enteredTurn = $s->turn;
        $p->battlefield[] = $source;
        $s->emit('PregameCardPlaced', ['player' => $playerId, 'card' => $source->toPublic(), 'hand' => count($p->hand)]);
        $s->addCounters($playerId, $source, CardInstance::LUCK, 1);
        $s->exileFromHand($playerId, $exiled, $source->name);
    }

    private function mulligan(GameState $s, int $playerId): void
    {
        $p = $s->player($playerId);
        $taken = $s->mulligan[$playerId]['taken'] + 1;
        $s->mulligan[$playerId]['taken'] = $taken;
        $p->library = $s->rng->shuffle([...$p->hand, ...$p->library]);
        $p->hand = [];
        $s->emit('MulliganTaken', ['player' => $playerId, 'mulligans' => $taken, 'hand' => 0, 'library' => count($p->library)]);
        for ($i = 0; $i < self::HAND_SIZE; $i++) {
            $s->drawCard($playerId, setup: true);
        }
    }

    private function keep(GameState $s, int $playerId): void
    {
        $s->mulligan[$playerId]['kept'] = true;
        $s->emit('HandKept', [
            'player' => $playerId,
            'mulligans' => $s->mulligan[$playerId]['taken'],
            'toBottom' => self::toBottom($s, $playerId),
        ]);
    }

    private function bottom(GameState $s, int $playerId, string $cardId): void
    {
        $p = $s->player($playerId);
        $card = $p->takeFromHand($cardId) ?? throw new \DomainException("Carte à mettre au fond absente de la main : $cardId");
        $p->library[] = $card;
        $s->emit('CardPutOnBottom', [
            'player' => $playerId,
            'card' => $card->toPublic(),
            'hand' => count($p->hand),
            'library' => count($p->library),
        ]);
    }
}
