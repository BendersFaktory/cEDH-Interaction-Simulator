<?php

declare(strict_types=1);

namespace App\Service;

/** Interrompt un rejeu une fois la cible dépassée (levée par le listener, attrapée par StateReconstructor). */
final class ReplayStop extends \RuntimeException
{
}
