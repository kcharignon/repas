<?php

namespace Repas\User\Domain\Model;


use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\User\Domain\Exception\IdentityException;

/**
 * Le lien entre un compte Repas et une identité externe (ADR 0024 décision 8) : chez ce fournisseur, la personne au sujet
 * `subject` est ce compte. La clé est `(provider, subject)`, le `sub` du jeton de Google, **jamais l'adresse** : elle peut
 * changer en silence et ne sert qu'à retrouver un compte à la première connexion ; elle n'est pas conservée ici.
 *
 * Un lien ne change jamais : il naît à la première connexion avec le fournisseur et disparaît avec le compte
 * (`DeleteUserHandler`, ADR 0021). Un compte a au plus un lien par fournisseur, et une identité au plus un compte.
 */
final class UserIdentity implements ModelInterface
{
    use ModelTrait;

    /**
     * Longueur maximale d'un sujet, en octets : un `sub` ne dépasse pas 255 caractères ASCII (OpenID Connect Core 1.0 § 2),
     * taille de la colonne `subject`. Le format n'est pas supposé (chez Google, des chiffres).
     */
    public const int SUBJECT_MAX_LENGTH = 255;

    private function __construct(
        private string $id,
        private string $userId,
        private IdentityProvider $provider,
        private string $subject,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @throws IdentityException un sujet vide ou trop long ne désigne personne : même refus qu'un jeton invalide (`401`)
     */
    public static function create(
        string $id,
        string $userId,
        IdentityProvider $provider,
        string $subject,
        DateTimeImmutable $createdAt,
    ): self {
        if (trim($subject) === '' || strlen($subject) > self::SUBJECT_MAX_LENGTH) {
            throw IdentityException::invalidCredential();
        }

        return new self(
            id: $id,
            userId: $userId,
            provider: $provider,
            subject: $subject,
            createdAt: $createdAt,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            userId: $datas['user_id'],
            provider: $datas['provider'],
            subject: $datas['subject'],
            createdAt: $datas['created_at'],
        );
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
