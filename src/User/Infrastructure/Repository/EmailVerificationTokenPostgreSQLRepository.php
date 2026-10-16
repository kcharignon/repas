<?php

namespace Repas\User\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Infrastructure\Entity\EmailVerificationToken as EmailVerificationTokenEntity;

readonly class EmailVerificationTokenPostgreSQLRepository extends PostgreSQLRepository implements EmailVerificationTokenRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, EmailVerificationTokenEntity::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?EmailVerificationToken
    {
        $entity = $this->entityRepository->findOneBy(['tokenHash' => $tokenHash]);

        return $entity instanceof EmailVerificationTokenEntity ? $this->convertEntityToModel($entity) : null;
    }

    public function findOneByUserId(string $userId): ?EmailVerificationToken
    {
        $entity = $this->entityRepository->findOneBy(['userId' => $userId]);

        return $entity instanceof EmailVerificationTokenEntity ? $this->convertEntityToModel($entity) : null;
    }

    public function add(EmailVerificationToken $token): void
    {
        $this->entityManager->persist(EmailVerificationTokenEntity::fromModel($token));
        $this->entityManager->flush();
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(EmailVerificationTokenEntity::class, 't')
            ->where('t.userId = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->execute();

        // Les lignes supprimées par DQL restent dans l'identity map : un `find()` ultérieur les rendrait comme vivantes
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[EmailVerificationTokenEntity::class] ?? [] as $entity) {
            if ($entity->getUserId() === $userId) {
                $this->entityManager->detach($entity);
            }
        }
    }

    private function convertEntityToModel(EmailVerificationTokenEntity $entity): EmailVerificationToken
    {
        return EmailVerificationToken::load([
            'id' => $entity->getId(),
            'user_id' => $entity->getUserId(),
            'token_hash' => $entity->getTokenHash(),
            'issued_at' => $entity->getIssuedAt(),
            'expires_at' => $entity->getExpiresAt(),
        ]);
    }
}
