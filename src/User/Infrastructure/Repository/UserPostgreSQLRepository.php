<?php

namespace Repas\User\Infrastructure\Repository;



use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;

class UserPostgreSQLRepository extends ServiceEntityRepository implements UserRepository
{
    public function __construct(ManagerRegistry $managerRegistry) {
        parent::__construct($managerRegistry, UserEntity::class);
    }

    /**
     * @throws UserException
     */
    public function getUserByEmail(string $email): User
    {
        $userEntity = $this->findOneBy(['email' => $email]);

        if (!$userEntity instanceof UserEntity) {
            throw UserException::NotFound();
        }

        return $userEntity->toModel();
    }

    public function save(User $user): void
    {
        $entity = UserEntity::fromModel($user);
        $this->getEntityManager()->persist($entity);
    }
}
