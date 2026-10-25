<?php

namespace Repas\User\Infrastructure\Account;


use Doctrine\ORM\EntityManagerInterface;
use Repas\Shared\Application\Interface\ActiveUserChecker;
use Repas\User\Domain\Model\UserStatus;
use Repas\User\Infrastructure\Entity\User;

/**
 * Un compte est actif quand il existe et que son statut est `ACTIVE` (ADR 0021). Une requête sur la colonne du statut, sans charger
 * le compte.
 */
final readonly class ActiveUserCheckerPostgreSQL implements ActiveUserChecker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function isActive(string $userId): bool
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('u.status AS status')
            ->from(User::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $userId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row !== null && $row['status'] === UserStatus::ACTIVE;
    }
}
