<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Tableau PHP <-> JSON stocké comme texte (LONGTEXT sur MySQL).
 *
 * Pourquoi pas le type `json` natif de MySQL : on n'interroge jamais ces colonnes en SQL, on les relit en
 * entier. Le type natif obligerait MySQL à valider et convertir chaque document en binaire (deux fois plus
 * lent sur les checkpoints) et réordonnerait les clés des objets. Ici le texte est stocké tel quel.
 */
final class JsonTextType extends Type
{
    public const NAME = 'json_text';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }
}
