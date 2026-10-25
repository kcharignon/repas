<?php

namespace Repas\Voice\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\Voice\Domain\Interface\AuthorizationCodeRepository;
use Repas\Voice\Domain\Model\AuthorizationCode;
use Repas\Voice\Infrastructure\Entity\AuthorizationCode as AuthorizationCodeEntity;

readonly class AuthorizationCodePostgreSQLRepository extends PostgreSQLRepository implements AuthorizationCodeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, AuthorizationCodeEntity::class);
    }

    public function add(AuthorizationCode $code): void
    {
        $entity = new AuthorizationCodeEntity();
        $entity->codeHash = $code->getId();
        $entity->userId = $code->getUserId();
        $entity->clientId = $code->getClientId();
        $entity->redirectUri = $code->getRedirectUri();
        $entity->codeChallenge = $code->getCodeChallenge();
        $entity->expiresAt = $code->getExpiresAt();
        $entity->usedAt = $code->getUsedAt();
        $entity->linkId = $code->getLinkId();

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findOneByHash(string $codeHash): ?AuthorizationCode
    {
        $entity = $this->entityRepository->find($codeHash);
        if (!$entity instanceof AuthorizationCodeEntity) {
            return null;
        }

        return AuthorizationCode::load([
            'code_hash' => $entity->codeHash,
            'user_id' => $entity->userId,
            'client_id' => $entity->clientId,
            'redirect_uri' => $entity->redirectUri,
            'code_challenge' => $entity->codeChallenge,
            'expires_at' => $entity->expiresAt,
            'used_at' => $entity->usedAt,
            'link_id' => $entity->linkId,
        ]);
    }

    /**
     * Un `UPDATE` conditionnel (`used_at IS NULL`) : PostgreSQL fait attendre un second échange simultané, qui relit alors la ligne déjà
     * consommée et n'écrit rien. L'entité éventuellement lue plus tôt dans la requête est détachée : son état en mémoire serait périmé.
     */
    public function consume(string $codeHash, DateTimeImmutable $now, ?string $linkId): bool
    {
        $consumed = $this->entityManager->getConnection()->executeStatement(
            'UPDATE voice_authorization_code SET used_at = :now, link_id = :linkId WHERE code_hash = :codeHash AND used_at IS NULL',
            ['now' => $now, 'linkId' => $linkId, 'codeHash' => $codeHash],
            ['now' => Types::DATETIME_IMMUTABLE],
        ) === 1;

        $managed = $this->findManaged($codeHash);
        if ($managed !== null) {
            $this->entityManager->detach($managed);
        }

        return $consumed;
    }

    public function deleteExpiredOfUser(string $userId, DateTimeImmutable $now): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM voice_authorization_code WHERE user_id = :userId AND expires_at <= :now',
            ['userId' => $userId, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
