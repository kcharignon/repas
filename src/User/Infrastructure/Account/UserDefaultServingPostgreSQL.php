<?php

namespace Repas\User\Infrastructure\Account;


use Doctrine\ORM\EntityManagerInterface;
use Repas\Shared\Application\Interface\UserDefaultServing;
use Repas\User\Infrastructure\Entity\User;

/**
 * Le nombre de personnes par défaut d'un compte : une requête sur la colonne, sans charger le compte.
 */
final readonly class UserDefaultServingPostgreSQL implements UserDefaultServing
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function of(string $userId): int
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('u.defaultServing AS serving')
            ->from(User::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $userId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return is_int($row['serving'] ?? null) && $row['serving'] > 0 ? $row['serving'] : self::FALLBACK;
    }
}
