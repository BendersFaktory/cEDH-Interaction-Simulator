<?php

declare(strict_types=1);

namespace App\Service\Deck;

/** Decklist refusée : `problems` liste tout ce qui bloque (une ligne par carte ou par règle), pour tout corriger en une fois. */
final class DeckLoadException extends \InvalidArgumentException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode("\n", $problems));
    }
}
