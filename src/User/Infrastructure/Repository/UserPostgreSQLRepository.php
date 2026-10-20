<?php

namespace Repas\User\Infrastructure\Repository;



use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
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

    /**
     * Une seule instruction, qui ne nomme que `last_login_at` (aucune lecture, aucun `flush`, l'ORM n'y touche pas) : elle ne peut
     * donc écraser ni un statut ni un mot de passe changés par une autre requête. Le `WHERE` empêche la date de reculer quand
     * deux connexions se croisent, et ne trouve rien pour un compte supprimé entre-temps (la ligne ne renaît pas). Comme
     * `PostgreSQLRepository::bumpVersion()`, elle laisse l'identity map de Doctrine telle quelle : l'entité déjà chargée garde
     * l'ancienne date jusqu'à la fin de la requête, sans conséquence (aucun `flush` ne la réécrit : elle n'est pas modifiée).
     */
    public function recordLogin(string $userId, DateTimeImmutable $at): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE "user" SET last_login_at = :at WHERE id = :id AND (last_login_at IS NULL OR last_login_at < :at)',
            // Doctrine relit les dates dans le fuseau par défaut de PHP : on écrit dans le même, quel que soit celui de `$at`
            ['at' => $at->setTimezone(new DateTimeZone(date_default_timezone_get())), 'id' => $userId],
            ['at' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function delete(User $user): void
    {
        $entity = $this->entityRepository->find($user->getId());
        if ($entity instanceof UserEntity) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
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
            'credentials_version' => $entity->getCredentialsVersion(),
            'last_login_at' => $entity->getLastLoginAt(),
        ]);
    }

    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(function (UserEntity $user) {
            return $this->convertEntityToModel($user);
        });
    }
}
