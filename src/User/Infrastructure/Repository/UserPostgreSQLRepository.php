<?php

namespace Repas\User\Infrastructure\Repository;



use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;

readonly class UserPostgreSQLRepository extends PostgreSQLRepository implements UserRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private ModelCache $modelCache,
    ) {
        parent::__construct($managerRegistry, UserEntity::class);
    }

    /**
     * @throws UserException
     */
    public function findOneById(string $id): User
    {
        if (($model = $this->modelCache->getModelCache(User::class, $id)) !== null) {
            return $model;
        }

        if (($entity = $this->entityRepository->find($id)) !== null) {
            $model = $this->convertEntityToModel($entity);
            $this->modelCache->setModelCache($model);
            return $model;
        }
        throw UserException::NotFound();
    }

    /**
     * @throws UserException
     */
    public function findOneByEmail(string $email): User
    {
        if (($userEntity = $this->entityRepository->findOneBy(['email' => $email])) !== null) {
            $userModel = $this->convertEntityToModel($userEntity);
            $this->modelCache->setModelCache($userModel);
            return $userModel;
        }
        throw UserException::NotFound();
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

        $this->modelCache->setModelCache($user);
        $this->entityManager->flush();

    }

    private function convertEntityToModel(UserEntity $entity): User
    {
        return User::load([
            'id' => $entity->getId(),
            'email' => $entity->getEmail(),
            'roles' => $entity->getRoles(),
            'password' => $entity->getPassword(),
            'default_serving' => $entity->getDefaultServing()
        ]);
    }
}
