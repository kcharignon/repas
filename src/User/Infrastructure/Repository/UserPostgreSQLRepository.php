<?php

namespace Repas\User\Infrastructure\Repository;



use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;

readonly class UserPostgreSQLRepository extends PostgreSQLRepository implements UserRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, UserEntity::class);
    }

    /**
     * @throws UserException
     */
    public function findOneById(string $id): User
    {
        if (($entity = $this->entityRepository->find($id)) !== null) {
            return $this->convertEntityToModel($entity);
        }
        throw UserException::NotFound($id);
    }

    /**
     * @throws UserException
     */
    public function findOneByEmail(string $email): User
    {
        if (($userEntity = $this->entityRepository->findOneBy(['email' => User::normalizeEmail($email)])) !== null) {
            return $this->convertEntityToModel($userEntity);
        }
        throw UserException::NotFound($email);
    }

    public function emailExists(string $email): bool
    {
        return $this->entityRepository->findOneBy(['email' => User::normalizeEmail($email)]) !== null;
    }

    public function findAll(): Tab
    {
        $entities = new Tab($this->entityRepository->findBy([], ['email' => 'ASC']), UserEntity::class);

        return $this->convertEntitiesToModels($entities);
    }

    public function save(User $user): void
    {
        $existingUserEntity = $this->entityRepository->find($user->getId());

        if ($existingUserEntity instanceof UserEntity) {
            // Mise à jour de l'utilisateur existant
            $existingUserEntity->updateFromModel($user);
        } else {
            // Création d'un nouvel utilisateur
            $newUserEntity = UserEntity::fromModel($user);
            $this->entityManager->persist($newUserEntity);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux inscriptions simultanées du même email : la seconde échoue sur l'unicité en base, comme si le contrôle d'existence l'avait vue
            throw UserException::emailAlreadyUsed($user->getEmail());
        }
    }

    private function convertEntityToModel(UserEntity $entity): User
    {
        return User::load([
            'id' => $entity->getId(),
            'email' => $entity->getEmail(),
            'roles' => $entity->getRoles(),
            'password' => $entity->getPassword(),
            'default_serving' => $entity->getDefaultServing(),
            'status' => $entity->getStatus(),
            'statistics' => $entity->getStatistics(),
        ]);
    }

    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(function (UserEntity $user) {
            return $this->convertEntityToModel($user);
        });
    }
}
