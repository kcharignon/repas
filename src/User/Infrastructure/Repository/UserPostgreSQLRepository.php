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

        return $userEntity->getModel();
    }

    public function save(User $user): void
    {
        // Récupérer l'EntityManager
        $entityManager = $this->getEntityManager();

        // Vérifiez si un utilisateur avec le même id existe déjà
        $existingUserEntity = $this->find($user->getId());

        if ($existingUserEntity instanceof UserEntity) {
            // Mise à jour de l'utilisateur existant
            $existingUserEntity->updateFromModel($user);
        } else {
            // Création d'un nouvel utilisateur
            $newUserEntity = UserEntity::fromModel($user);
            $entityManager->persist($newUserEntity);
        }

        // Sauvegarder les changements dans la base de données
        $entityManager->flush();
    }
}
