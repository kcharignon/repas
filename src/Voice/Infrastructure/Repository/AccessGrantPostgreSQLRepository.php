<?php

namespace Repas\Voice\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Infrastructure\Entity\AccessGrant as AccessGrantEntity;

readonly class AccessGrantPostgreSQLRepository extends PostgreSQLRepository implements AccessGrantRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, AccessGrantEntity::class);
    }

    public function add(AccessGrant $grant): void
    {
        $entity = new AccessGrantEntity();
        $entity->accessHash = $grant->getId();
        $entity->refreshHash = $grant->getRefreshHash();
        $entity->linkId = $grant->getLinkId();
        $entity->userId = $grant->getUserId();
        $entity->expiresAt = $grant->getExpiresAt();
        $entity->refreshExpiresAt = $grant->getRefreshExpiresAt();
        $entity->usedAt = $grant->getUsedAt();

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findOneByRefreshHash(string $refreshHash): ?AccessGrant
    {
        $entity = $this->entityRepository->findOneBy(['refreshHash' => $refreshHash]);
        if (!$entity instanceof AccessGrantEntity) {
            return null;
        }

        return AccessGrant::load([
            'access_hash' => $entity->accessHash,
            'refresh_hash' => $entity->refreshHash,
            'link_id' => $entity->linkId,
            'user_id' => $entity->userId,
            'expires_at' => $entity->expiresAt,
            'refresh_expires_at' => $entity->refreshExpiresAt,
            'used_at' => $entity->usedAt,
        ]);
    }

    /**
     * Un `UPDATE` conditionnel (`used_at IS NULL`) : PostgreSQL fait attendre un second échange simultané, qui relit alors la ligne déjà
     * consommée et n'écrit rien. L'entité lue plus tôt dans la requête est détachée : son état en mémoire serait périmé.
     */
    public function consumeRefresh(string $refreshHash, DateTimeImmutable $now): bool
    {
        $consumed = $this->entityManager->getConnection()->executeStatement(
            'UPDATE voice_access_grant SET used_at = :now WHERE refresh_hash = :refreshHash AND used_at IS NULL',
            ['now' => $now, 'refreshHash' => $refreshHash],
            ['now' => Types::DATETIME_IMMUTABLE],
        ) === 1;

        $this->detachWhere(static fn(AccessGrantEntity $entity): bool => $entity->refreshHash === $refreshHash);

        return $consumed;
    }

    public function deleteByLink(string $linkId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM voice_access_grant WHERE link_id = :linkId',
            ['linkId' => $linkId],
        );
        $this->detachWhere(static fn(AccessGrantEntity $entity): bool => $entity->linkId === $linkId);
    }

    public function deleteExpiredOfLink(string $linkId, DateTimeImmutable $now): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM voice_access_grant WHERE link_id = :linkId AND refresh_expires_at <= :now',
            ['linkId' => $linkId, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        $this->detachWhere(static fn(AccessGrantEntity $entity): bool => $entity->linkId === $linkId && $entity->refreshExpiresAt <= $now);
    }

    /**
     * Les lignes écrites ou effacées en SQL direct restent dans l'identity map de Doctrine : une lecture ultérieure les rendrait
     * telles qu'elles étaient. On détache ces entités (aucune requête), sans toucher aux autres.
     *
     * @param callable(AccessGrantEntity): bool $changed
     */
    private function detachWhere(callable $changed): void
    {
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[AccessGrantEntity::class] ?? [] as $entity) {
            if ($changed($entity)) {
                $this->entityManager->detach($entity);
            }
        }
    }
}
