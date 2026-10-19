<?php

namespace Repas\User\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Model\PasswordResetToken;
use Repas\User\Infrastructure\Entity\PasswordResetToken as PasswordResetTokenEntity;

readonly class PasswordResetTokenPostgreSQLRepository extends PostgreSQLRepository implements PasswordResetTokenRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, PasswordResetTokenEntity::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?PasswordResetToken
    {
        $entity = $this->entityRepository->findOneBy(['tokenHash' => $tokenHash]);

        return $entity instanceof PasswordResetTokenEntity ? $this->convertEntityToModel($entity) : null;
    }

    public function findOneByUserId(string $userId): ?PasswordResetToken
    {
        $entity = $this->entityRepository->findOneBy(['userId' => $userId]);

        return $entity instanceof PasswordResetTokenEntity ? $this->convertEntityToModel($entity) : null;
    }

    public function add(PasswordResetToken $token): void
    {
        $this->entityManager->persist(PasswordResetTokenEntity::fromModel($token));
        $this->entityManager->flush();
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(PasswordResetTokenEntity::class, 't')
            ->where('t.userId = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->execute();

        // Les lignes supprimées par DQL restent dans l'identity map : un `find()` ultérieur les rendrait comme vivantes
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[PasswordResetTokenEntity::class] ?? [] as $entity) {
            if ($entity->getUserId() === $userId) {
                $this->entityManager->detach($entity);
            }
        }
    }

    private function convertEntityToModel(PasswordResetTokenEntity $entity): PasswordResetToken
    {
        return PasswordResetToken::load([
            'id' => $entity->getId(),
            'user_id' => $entity->getUserId(),
            'token_hash' => $entity->getTokenHash(),
            'issued_at' => $entity->getIssuedAt(),
            'expires_at' => $entity->getExpiresAt(),
        ]);
    }
}
