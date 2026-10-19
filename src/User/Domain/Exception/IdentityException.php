<?php

namespace Repas\User\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class IdentityException extends DomainException
{
    public const string INVALID_CREDENTIAL = 'Invalid Google credential.';

    /**
     * Jeton d'identité refusé (signature, émetteur, audience, dates, revendications, clés indisponibles) : une seule réponse,
     * `401`, au caractère près, pour ne rien apprendre à qui essaie (ADR 0024 décision 6).
     */
    public static function invalidCredential(): self
    {
        return new self(self::INVALID_CREDENTIAL, 401);
    }
}
