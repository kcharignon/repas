<?php

namespace Repas\User\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\User\Application\Loader\UserLoader;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Domain\Exception\UserException;
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
}
