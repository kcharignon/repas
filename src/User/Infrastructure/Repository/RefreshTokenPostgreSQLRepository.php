<?php

namespace Repas\User\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\User\Domain\Interface\RefreshTokenRepository;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Infrastructure\Entity\RefreshToken as RefreshTokenEntity;

readonly class RefreshTokenPostgreSQLRepository extends PostgreSQLRepository implements RefreshTokenRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, RefreshTokenEntity::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?RefreshToken
    {
        $entity = $this->entityRepository->findOneBy(['tokenHash' => $tokenHash]);

        return $entity instanceof RefreshTokenEntity ? $this->convertEntityToModel($entity) : null;
    }

    public function save(RefreshToken $refreshToken): void
    {
        $entity = $this->entityRepository->find($refreshToken->getId());
        if ($entity instanceof RefreshTokenEntity) {
            $entity->updateFromModel($refreshToken);
        } else {
            $this->entityManager->persist(RefreshTokenEntity::fromModel($refreshToken));
        }

        $this->entityManager->flush();
    }

    public function deleteFamily(string $familyId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(RefreshTokenEntity::class, 't')
            ->where('t.familyId = :family')
            ->setParameter('family', $familyId)
            ->getQuery()
            ->execute();
        $this->detachWhere(static fn(RefreshTokenEntity $entity): bool => $entity->getFamilyId() === $familyId);
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(RefreshTokenEntity::class, 't')
            ->where('t.userId = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->execute();
        $this->detachWhere(static fn(RefreshTokenEntity $entity): bool => $entity->getUserId() === $userId);
    }

    public function deleteOldestFamiliesOfUser(string $userId, int $keep): void
    {
        /** @var list<array{familyId: string}> $families */
        $families = $this->entityManager->createQueryBuilder()
            ->select('t.familyId AS familyId')
            ->from(RefreshTokenEntity::class, 't')
            ->where('t.userId = :user')
            ->setParameter('user', $userId)
            ->groupBy('t.familyId')
            ->orderBy('MAX(t.issuedAt)', 'DESC')
            ->addOrderBy('t.familyId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        foreach (array_slice($families, $keep) as $family) {
            $this->deleteFamily($family['familyId']);
        }
    }

    public function deleteExpired(DateTimeImmutable $now): int
    {
        $deleted = $this->entityManager->createQueryBuilder()
            ->delete(RefreshTokenEntity::class, 't')
            ->where('t.expiresAt <= :now OR t.familyExpiresAt <= :now')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
        $this->detachWhere(static fn(RefreshTokenEntity $entity): bool => $entity->getExpiresAt() <= $now || $entity->getFamilyExpiresAt() <= $now);

        return (int) $deleted;
    }

    /**
     * Les lignes supprimées par DQL restent dans l'identity map de Doctrine : un `find()` ultérieur les rendrait comme vivantes.
     * On détache ces entités sans toucher aux autres (aucune requête).
     *
     * @param callable(RefreshTokenEntity): bool $wasDeleted
     */
    private function detachWhere(callable $wasDeleted): void
    {
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[RefreshTokenEntity::class] ?? [] as $entity) {
            if ($wasDeleted($entity)) {
                $this->entityManager->detach($entity);
            }
        }
    }

    private function convertEntityToModel(RefreshTokenEntity $entity): RefreshToken
    {
        return RefreshToken::load([
            'id' => $entity->getId(),
            'user_id' => $entity->getUserId(),
            'family_id' => $entity->getFamilyId(),
            'token_hash' => $entity->getTokenHash(),
            'credentials_version' => $entity->getCredentialsVersion(),
            'issued_at' => $entity->getIssuedAt(),
            'expires_at' => $entity->getExpiresAt(),
            'family_expires_at' => $entity->getFamilyExpiresAt(),
            'used_at' => $entity->getUsedAt(),
        ]);
    }
}
