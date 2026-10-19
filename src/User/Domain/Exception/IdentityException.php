<?php

namespace Repas\User\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class IdentityException extends DomainException
{
    public const string INVALID_CREDENTIAL = 'Invalid Google credential.';
    public const string ALREADY_LINKED = 'ALREADY_LINKED';

    /**
     * Jeton d'identité refusé (signature, émetteur, audience, dates, revendications, clés indisponibles) : une seule réponse,
     * `401`, au caractère près, pour ne rien apprendre à qui essaie (ADR 0024 décision 6).
     */
    public static function invalidCredential(): self
    {
        return new self(self::INVALID_CREDENTIAL, 401);
    }

    /**
     * Le lien ne peut pas être posé : cette identité externe est déjà liée à un compte, ou ce compte a déjà un lien chez ce
     * fournisseur (clés uniques de `user_identity`, ADR 0024 décision 8). En pratique, deux connexions simultanées avec le même
     * jeton : la seconde échoue sur l'unicité en base, comme `emailAlreadyUsed` à l'inscription. Conflit, `409`.
     */
    public static function alreadyLinked(): self
    {
        return new self(self::ALREADY_LINKED, 409);
    }
}
