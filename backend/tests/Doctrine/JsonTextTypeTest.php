<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\Type\JsonTextType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use PHPUnit\Framework\TestCase;

final class JsonTextTypeTest extends TestCase
{
    private JsonTextType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new JsonTextType();
        $this->platform = new MySQL84Platform();
    }

    private function roundTrip(mixed $value): mixed
    {
        return $this->type->convertToPHPValue($this->type->convertToDatabaseValue($value, $this->platform), $this->platform);
    }

    public function testLeSQLDeLaColonneEstUnTexteLongEtNonUnJsonNatif(): void
    {
        self::assertSame('LONGTEXT', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testAllerRetourConserveLOrdreDesClesNonAlphabetique(): void
    {
        $value = ['zebre' => 1, 'abeille' => ['z' => 1, 'a' => 2], 'milieu' => [3, 1, 2]];

        self::assertSame($value, $this->roundTrip($value));
        self::assertSame(['zebre', 'abeille', 'milieu'], array_keys($this->roundTrip($value)));
    }

    public function testClesNumeriquesEtListesVides(): void
    {
        $controllers = [0 => 'human:auto', 1 => 'ai:heuristic', 2 => 'ai:heuristic', 3 => 'ai:heuristic'];
        $value = ['controllers' => $controllers, 'passed' => [], 'stack' => []];

        self::assertSame($value, $this->roundTrip($value));
    }

    public function testAccentsEtSlashsSontStockesSansEchappement(): void
    {
        $json = $this->type->convertToDatabaseValue(['label' => 'Passer la priorité', 'id' => 'land:0/p0'], $this->platform);

        self::assertSame('{"label":"Passer la priorité","id":"land:0/p0"}', $json);
    }

    public function testNullEtTypesScalaires(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
        self::assertSame(['a' => true, 'b' => null, 'c' => 1.5, 'd' => 0], $this->roundTrip(['a' => true, 'b' => null, 'c' => 1.5, 'd' => 0]));
    }

    public function testJsonInvalideLeveUneException(): void
    {
        $this->expectException(\JsonException::class);

        $this->type->convertToPHPValue('{pas du json', $this->platform);
    }
}
