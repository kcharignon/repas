<?php

namespace Repas\User\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\UserIdentity as UserIdentityModel;

/**
 * Mapping de `user_identity` (ADR 0024 décision 8). Aucune clé étrangère : `userId` est effacé avec le compte (ADR 0021). La
 * clé unique `(user_id, provider)` sert aussi aux recherches par compte et à la suppression des liens d'un compte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_identity')]
#[ORM\UniqueConstraint(name: 'uniq_user_identity_provider_subject', columns: ['provider', 'subject'])]
#[ORM\UniqueConstraint(name: 'uniq_user_identity_user_provider', columns: ['user_id', 'provider'])]
class UserIdentity
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    private string $userId;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: IdentityProvider::class)]
    private IdentityProvider $provider;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $subject;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public static function fromModel(UserIdentityModel $model): self
    {
        $entity = new self();
        $entity->id = $model->getId();
        $entity->updateFromModel($model);

        return $entity;
    }

    public function updateFromModel(UserIdentityModel $model): void
    {
        $this->userId = $model->getUserId();
        $this->provider = $model->getProvider();
        $this->subject = $model->getSubject();
        $this->createdAt = $model->getCreatedAt();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getProvider(): IdentityProvider
    {
        return $this->provider;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
