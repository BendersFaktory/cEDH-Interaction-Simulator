<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CardCacheRepository;
use Doctrine\ORM\Mapping as ORM;

/** Carte Scryfall mise en cache, clé = nom normalisé (minuscules, espaces compactés). */
#[ORM\Entity(repositoryClass: CardCacheRepository::class)]
#[ORM\Table(name: 'card_cache')]
#[ORM\UniqueConstraint(name: 'uniq_card_cache_name', columns: ['name'])]
class CardCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<string, mixed> $data Objet carte Scryfall brut
     */
    public function __construct(
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column(type: 'json_text')]
        private array $data,
        #[ORM\Column(length: 512, nullable: true)]
        private ?string $imageSmall,
        #[ORM\Column(length: 512, nullable: true)]
        private ?string $imageNormal,
        #[ORM\Column]
        private \DateTimeImmutable $fetchedAt,
    ) {
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function getImageSmall(): ?string
    {
        return $this->imageSmall;
    }

    public function getImageNormal(): ?string
    {
        return $this->imageNormal;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    /** @param array<string, mixed> $data */
    public function refresh(array $data, ?string $imageSmall, ?string $imageNormal, \DateTimeImmutable $fetchedAt): void
    {
        $this->data = $data;
        $this->imageSmall = $imageSmall;
        $this->imageNormal = $imageNormal;
        $this->fetchedAt = $fetchedAt;
    }
}
