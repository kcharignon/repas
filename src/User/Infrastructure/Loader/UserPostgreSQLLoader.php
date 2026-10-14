<?php

namespace Repas\User\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\User\Application\Loader\UserLoader;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;
use Repas\User\Domain\Model\UserStatus;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\User as DomainUser;
use Repas\User\Infrastructure\Entity\User;

final readonly class UserPostgreSQLLoader implements UserLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $id): CurrentUserDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('u.id AS id', 'u.email AS email', 'u.roles AS roles', 'u.defaultServing AS defaultServing')
            ->from(User::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            throw UserException::NotFound($id);
        }

        $roles = array_values($row['roles']);

        return new CurrentUserDTO(
            $row['id'],
            $row['email'],
            $roles,
            in_array('ROLE_ADMIN', $roles, true),
            $row['defaultServing'],
        );
    }
    public function list(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('u.id AS id', 'u.email AS email', 'u.roles AS roles', 'u.defaultServing AS defaultServing', 'u.status AS status', 'u.statistics AS statistics')
            ->from(User::class, 'u')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): UserSummaryDTO {
            $statistics = $row['statistics'] ?? [];
            $created = $statistics['createdAt'] ?? null;

            return new UserSummaryDTO(
                id: $row['id'],
                email: $row['email'],
                defaultServing: (int) $row['defaultServing'],
                recipeCount: (int) ($statistics['recipes'] ?? 0),
                ingredientCount: (int) ($statistics['ingredients'] ?? 0),
                shoppingListCount: count($statistics['shoppingLists'] ?? []),
                // Les statistiques stockent la date comme un DateTime sérialisé : { date, timezone_type, timezone }
                createdAt: is_array($created) && isset($created['date'], $created['timezone'])
                    ? new \DateTimeImmutable($created['date'], new \DateTimeZone($created['timezone']))->format(DATE_ATOM)
                    : '',
                isDisabled: $row['status'] === UserStatus::DISABLED,
                isAdmin: in_array('ROLE_ADMIN', $row['roles'], true),
            );
        }, $rows);
    }

    public function emailExists(string $email): bool
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u.id')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', DomainUser::normalizeEmail($email))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
