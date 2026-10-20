<?php

namespace Repas\User\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Repas\User\Application\Loader\UserLoader;
use Repas\User\Application\Query\GetCurrentUser\AppUpdateDTO;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;
use Repas\User\Domain\Interface\AppReleaseProvider;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Repas\User\Domain\Model\UserStatus;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\User as DomainUser;
use Repas\User\Infrastructure\Entity\User;
use Repas\User\Infrastructure\Entity\UserAppVersion;

final readonly class UserPostgreSQLLoader implements UserLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AppReleaseProvider $appReleaseProvider,
    ) {
    }

    public function get(string $id): CurrentUserDTO
    {
        $row = $this->withAppVersion($this->entityManager->createQueryBuilder()
            ->select('u.id AS id', 'u.email AS email', 'u.roles AS roles', 'u.defaultServing AS defaultServing')
            ->from(User::class, 'u'))
            ->andWhere('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            throw UserException::NotFound($id);
        }

        $roles = array_values($row['roles']);
        $release = $this->appReleaseProvider->current();

        return new CurrentUserDTO(
            $row['id'],
            $row['email'],
            $roles,
            in_array('ROLE_ADMIN', $roles, true),
            $row['defaultServing'],
            new AppUpdateDTO(
                latest: $release->latest?->toString(),
                minimum: $release->minimum?->toString(),
                outdated: $release->isOutdated(AppVersion::tryFromString($row['appVersion'])),
            ),
        );
    }
    public function list(): array
    {
        $rows = $this->withAppVersion($this->entityManager->createQueryBuilder()
            ->select('u.id AS id', 'u.email AS email', 'u.roles AS roles', 'u.defaultServing AS defaultServing', 'u.status AS status', 'u.statistics AS statistics', 'u.lastLoginAt AS lastLoginAt')
            ->from(User::class, 'u'))
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $release = $this->appReleaseProvider->current();

        return array_map(static function (array $row) use ($release): UserSummaryDTO {
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
                // Toujours écrite : `null` pour un compte jamais vu depuis le suivi
                lastLoginAt: $row['lastLoginAt']?->format(DATE_ATOM),
                isDisabled: $row['status'] === UserStatus::DISABLED,
                isAdmin: in_array('ROLE_ADMIN', $row['roles'], true),
                status: $row['status']->value,
                appVersion: $row['appVersion'],
                appOutdated: $release->isOutdated(AppVersion::tryFromString($row['appVersion'])),
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

    /**
     * La dernière version de l'application Android vue pour le compte (`appVersion`, `null` si aucune), dans la même requête :
     * au plus une ligne par compte et par plateforme (clé de `user_app_version`), la jointure ne duplique aucun compte.
     */
    private function withAppVersion(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->addSelect('v.version AS appVersion')
            ->leftJoin(UserAppVersion::class, 'v', Join::WITH, 'v.userId = u.id AND v.platform = :platform')
            ->setParameter('platform', AppPlatform::ANDROID->value);
    }
}
