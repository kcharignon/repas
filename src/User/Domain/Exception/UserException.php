<?php

namespace Repas\User\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class UserException extends DomainException
{
    public const string INVALID_PASSWORD = 'INVALID_PASSWORD';
    public const string INVALID_EMAIL_VERIFICATION_TOKEN = 'INVALID_EMAIL_VERIFICATION_TOKEN';

    public static function NotFound(string $user): static
    {
        return new static(sprintf("User '%s' not found", $user), 404);
    }

    public static function Banned(): static
    {
        return new static('USER_BANNED', 403);
    }

    public static function emailAlreadyUsed(string $email): self
    {
        return new self(sprintf("Email '%s' is already used", $email), 409);
    }

    public static function invalidPassword(): self
    {
        return new self(self::INVALID_PASSWORD, 422);
    }

    /**
     * Lien de confirmation inconnu, déjà utilisé ou expiré : on ne dit pas lequel.
     */
    public static function invalidEmailVerificationToken(): self
    {
        return new self(self::INVALID_EMAIL_VERIFICATION_TOKEN, 422);
    }
}
