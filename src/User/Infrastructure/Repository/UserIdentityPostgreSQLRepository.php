<?php

namespace Repas\User\Infrastructure\Repository;


use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Interface\UserIdentityRepository;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\UserIdentity;
use Repas\User\Infrastructure\Entity\UserIdentity as UserIdentityEntity;

readonly class UserIdentityPostgreSQLRepository extends PostgreSQLRepository implements UserIdentityRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, UserIdentityEntity::class);
    }

    public function findOneByProviderAndSubject(IdentityProvider $provider, string $subject): ?UserIdentity
    {
        $entity = $this->entityRepository->findOneBy(['provider' => $provider, 'subject' => $subject]);

        return $entity instanceof UserIdentityEntity ? $this->convertEntityToModel($entity) : null;
    }

    public function findOneByUserAndProvider(string $userId, IdentityProvider $provider): ?UserIdentity
    {
        $entity = $this->entityRepository->findOneBy(['userId' => $userId, 'provider' => $provider]);

        return $entity instanceof UserIdentityEntity ? $this->convertEntityToModel($entity) : null;
    }

    /**
     * @throws IdentityException
     */
    public function save(UserIdentity $identity): void
    {
        // Un lien lu dans cette requête est dans l'identity map ; un lien nouveau s'insère sans le chercher d'abord en base
        $entity = $this->findManaged($identity->getId());
        if ($entity instanceof UserIdentityEntity) {
            $entity->updateFromModel($identity);
        } else {
            $this->entityManager->persist(UserIdentityEntity::fromModel($identity));
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux connexions simultanées ont toutes deux vu « aucun lien » : la seconde échoue sur une clé unique de la table.
            // Doctrine a fermé l'EntityManager : l'exception remonte et le bus annule la transaction, rien d'autre n'est tenté.
            throw IdentityException::alreadyLinked();
        }
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(UserIdentityEntity::class, 'i')
            ->where('i.userId = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->execute();

        // Les lignes supprimées par DQL restent dans l'identity map : un `find()` ultérieur les rendrait comme vivantes
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[UserIdentityEntity::class] ?? [] as $entity) {
            if ($entity->getUserId() === $userId) {
                $this->entityManager->detach($entity);
            }
        }
    }

    private function convertEntityToModel(UserIdentityEntity $entity): UserIdentity
    {
        return UserIdentity::load([
            'id' => $entity->getId(),
            'user_id' => $entity->getUserId(),
            'provider' => $entity->getProvider(),
            'subject' => $entity->getSubject(),
            'created_at' => $entity->getCreatedAt(),
        ]);
    }
}
