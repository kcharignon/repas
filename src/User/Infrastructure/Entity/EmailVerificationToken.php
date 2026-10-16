<?php

namespace Repas\User\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Repas\User\Domain\Model\EmailVerificationToken as EmailVerificationTokenModel;

#[ORM\Entity]
#[ORM\Table(name: 'email_verification_token')]
#[ORM\Index(name: 'idx_email_verification_token_user', columns: ['user_id'])]
class EmailVerificationToken
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    private string $userId;

    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    public static function fromModel(EmailVerificationTokenModel $model): self
    {
        $entity = new self();
        $entity->id = $model->getId();
        $entity->updateFromModel($model);

        return $entity;
    }

    public function updateFromModel(EmailVerificationTokenModel $model): void
    {
        $this->userId = $model->getUserId();
        $this->tokenHash = $model->getTokenHash();
        $this->issuedAt = $model->getIssuedAt();
        $this->expiresAt = $model->getExpiresAt();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
