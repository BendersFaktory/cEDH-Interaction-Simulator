<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Simulation;
use App\Service\Deck\DeckLoadException;
use App\Service\Deck\DeckLoader;
use App\Service\SimulationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API fine : aucune règle de jeu ici, tout est délégué au service puis au moteur.
 */
#[Route('/api/simulations')]
final class SimulationController extends AbstractController
{
    public function __construct(private readonly SimulationService $simulations, private readonly DeckLoader $deckLoader)
    {
    }

    /** Met une partie en file (202) ; le worker la joue jusqu'à sa fin (ou la première décision humaine). Sonder GET /{id}. */
    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $request->toArray();
        $seed = (int) ($body['seed'] ?? random_int(1, 2_000_000_000)); // l'aléa « non déterministe » reste ICI, hors moteur
        $controllers = [];
        // Défaut : Commander à 4, tu joues J0 (human:auto) contre 3 IA. Passer 'ai:heuristic' pour J0 = spectateur.
        $default = [0 => 'human:auto', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];
        foreach (($body['controllers'] ?? $default) as $id => $key) {
            $controllers[(int) $id] = (string) $key;
        }

        // Decks optionnels : {"decks": {"0": "<decklist>" | {"decklist": "...", "commander": "..."}}} ; sièges absents = deck de démo.
        $decks = [];
        $problems = [];
        foreach (($body['decks'] ?? []) as $id => $spec) {
            $list = is_array($spec) ? (string) ($spec['decklist'] ?? '') : (string) $spec;
            try {
                $decks[(int) $id] = $this->deckLoader->load($list, is_array($spec) ? ($spec['commander'] ?? null) : null);
            } catch (DeckLoadException $e) {
                $problems["J$id"] = $e->problems;
            }
        }
        if ($problems !== []) {
            return $this->json(['error' => 'Decklist refusée.', 'problems' => $problems], 422);
        }

        return $this->guard(fn () => $this->view($this->simulations->create($seed, $controllers, $decks)), 202);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Simulation $simulation): JsonResponse
    {
        return $this->json($this->view($simulation));
    }

    #[Route('/{id}/events', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function events(Simulation $simulation, Request $request): JsonResponse
    {
        if ($busy = $this->busy($simulation)) {
            return $busy;
        }
        return $this->json($this->simulations->events($simulation, $request->query->getInt('after', 0)));
    }

    #[Route('/{id}/state', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function state(Simulation $simulation, Request $request): JsonResponse
    {
        if ($busy = $this->busy($simulation)) {
            return $busy;
        }
        $at = $request->query->getInt('at', PHP_INT_MAX);

        return $this->json($this->simulations->stateAt($simulation, $at) ?? ['error' => 'Aucun checkpoint'], 200);
    }

    #[Route('/{id}/pending', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pending(Simulation $simulation): JsonResponse
    {
        return $this->json($simulation->getPending());
    }

    /** Body : {"atEvent": 42, "controllers": {"1": "human:auto"}} */
    #[Route('/{id}/fork', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function fork(Simulation $simulation, Request $request): JsonResponse
    {
        if ($busy = $this->busy($simulation)) {
            return $busy;
        }
        $body = $request->toArray();
        $overrides = [];
        foreach (($body['controllers'] ?? []) as $id => $key) {
            $overrides[(int) $id] = (string) $key;
        }

        return $this->guard(fn () => $this->view($this->simulations->fork($simulation, (int) ($body['atEvent'] ?? 0), $overrides)), 201);
    }

    /** Body : {"actionId": "cast:1:p1_c12:stack:s3"} */
    #[Route('/{id}/decisions', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decide(Simulation $simulation, Request $request): JsonResponse
    {
        if ($busy = $this->busy($simulation)) {
            return $busy;
        }
        $actionId = (string) ($request->toArray()['actionId'] ?? '');

        return $this->guard(fn () => $this->view($this->simulations->decide($simulation, $actionId)));
    }

    /** Body : {"player": 0, "stops": {"steps": ["main1"], "onOpponentSpell": true, ...} | null} */
    #[Route('/{id}/stops', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function stops(Simulation $simulation, Request $request): JsonResponse
    {
        if ($busy = $this->busy($simulation)) {
            return $busy;
        }
        $body = $request->toArray();
        $stops = $body['stops'] ?? null;
        if (!isset($body['player']) || !is_int($body['player']) || ($stops !== null && !is_array($stops))) {
            return $this->json(['error' => 'Body attendu : {"player": int, "stops": object|null}'], 400);
        }

        try {
            return $this->json($this->view($this->simulations->setStops($simulation, $body['player'], $stops)));
        } catch (\ValueError|\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    /** 409 tant que la simulation n'est pas jouée (ou si elle a échoué). */
    private function busy(Simulation $s): ?JsonResponse
    {
        return match ($s->getStatus()) {
            Simulation::QUEUED, Simulation::RUNNING => $this->json(['error' => 'Simulation en cours.', 'status' => $s->getStatus()], 409),
            Simulation::FAILED => $this->json(['error' => 'Simulation en échec : ' . $s->getError(), 'status' => Simulation::FAILED], 409),
            default => null,
        };
    }

    private function view(Simulation $s): array
    {
        return [
            'id' => $s->getId(),
            'parentId' => $s->getParent()?->getId(),
            'forkEventSeq' => $s->getForkEventSeq(),
            'seed' => $s->getSeed(),
            'status' => $s->getStatus(),
            'progress' => $s->getProgress(),
            'error' => $s->getError(),
            'pending' => $s->getPending(),
            'eventCount' => $s->getState()['eventSeq'] ?? 0,
            'controllers' => $s->getState()['controllers'] ?? [],
            'cardImages' => (object) $this->cardImages($s),
            'stops' => (object) ($s->getState()['stops'] ?? []),
        ];
    }

    /** @return array<string, array{small: ?string, normal: ?string}> nom de carte => URL d'images (tous les decks chargés) */
    private function cardImages(Simulation $s): array
    {
        $images = [];
        foreach ($s->getDecks() ?? [] as $deck) {
            $images += $deck['images'] ?? [];
        }

        return $images;
    }

    private function guard(callable $fn, int $okStatus = 200): JsonResponse
    {
        try {
            return $this->json($fn(), $okStatus);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }
}
