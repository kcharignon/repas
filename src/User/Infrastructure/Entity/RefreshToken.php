<?php

namespace Repas\User\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Repas\User\Domain\Model\RefreshToken as RefreshTokenModel;

#[ORM\Entity]
#[ORM\Table(name: 'refresh_token')]
#[ORM\Index(name: 'idx_refresh_token_family', columns: ['family_id'])]
#[ORM\Index(name: 'idx_refresh_token_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_refresh_token_expires_at', columns: ['expires_at'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    private string $userId;

    #[ORM\Column(name: 'family_id', type: Types::STRING, length: 36)]
    private string $familyId;

    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(name: 'credentials_version', type: Types::INTEGER)]
    private int $credentialsVersion;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'family_expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $familyExpiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    public static function fromModel(RefreshTokenModel $model): self
    {
        $entity = new self();
        $entity->id = $model->getId();
        $entity->updateFromModel($model);

        return $entity;
    }

    public function updateFromModel(RefreshTokenModel $model): void
    {
        $this->userId = $model->getUserId();
        $this->familyId = $model->getFamilyId();
        $this->tokenHash = $model->getTokenHash();
        $this->credentialsVersion = $model->getCredentialsVersion();
        $this->issuedAt = $model->getIssuedAt();
        $this->expiresAt = $model->getExpiresAt();
        $this->familyExpiresAt = $model->getFamilyExpiresAt();
        $this->usedAt = $model->getUsedAt();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getFamilyId(): string
    {
        return $this->familyId;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCredentialsVersion(): int
    {
        return $this->credentialsVersion;
    }

    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getFamilyExpiresAt(): DateTimeImmutable
    {
        return $this->familyExpiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }
}
