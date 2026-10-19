<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CardCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CardCache> */
class CardCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardCache::class);
    }

    /**
     * @param list<string> $normalizedNames
     * @return array<string, CardCache> indexé par nom normalisé
     */
    public function findByNames(array $normalizedNames): array
    {
        if ($normalizedNames === []) {
            return [];
        }
        $found = [];
        foreach ($this->findBy(['name' => $normalizedNames]) as $card) {
            $found[$card->getName()] = $card;
        }

        return $found;
    }
}
