<?php

declare(strict_types=1);

namespace App\Service\Deck;

/** Carte que le moteur ne sait pas jouer fidèlement ; le message dit pourquoi. */
final class UnsupportedCard extends \DomainException
{
    public function __construct(public readonly string $card, public readonly string $reason)
    {
        parent::__construct("« $card » : $reason");
    }
}
