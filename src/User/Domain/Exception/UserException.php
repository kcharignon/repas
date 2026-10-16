<?php

namespace Repas\User\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;
use Repas\User\Domain\Model\UserStatus;

final class UserException extends DomainException
{
    public const string INVALID_PASSWORD = 'INVALID_PASSWORD';
    public const string INVALID_EMAIL_VERIFICATION_TOKEN = 'INVALID_EMAIL_VERIFICATION_TOKEN';
    public const string CANNOT_MANAGE_OWN_ACCOUNT = 'CANNOT_MANAGE_OWN_ACCOUNT';

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

    /**
     * Le compte n'est pas dans un état d'où il peut passer à celui demandé (ADR 0021) : conflit avec l'état courant.
     */
    public static function invalidStatusTransition(UserStatus $from, UserStatus $to): self
    {
        return new self(sprintf("A '%s' account cannot become '%s'", $from->value, $to->value), 409);
    }

    /**
     * Un administrateur ne peut ni désactiver ni supprimer son propre compte : il se priverait de l'administration (ADR 0021).
     */
    public static function cannotManageOwnAccount(): self
    {
        return new self(self::CANNOT_MANAGE_OWN_ACCOUNT, 422);
    }
}
