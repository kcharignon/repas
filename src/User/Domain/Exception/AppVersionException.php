<?php

namespace Repas\User\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class AppVersionException extends DomainException
{
    public const string INVALID_APP_VERSION = 'INVALID_APP_VERSION';

    /**
     * Une version d'application est trois nombres séparés par des points (`0.1.8`), 20 caractères au plus : rien d'autre n'est
     * retenu (ni suffixe, ni quatrième nombre). Règle métier, `422`.
     */
    public static function invalidVersion(): self
    {
        return new self(self::INVALID_APP_VERSION, 422);
    }
}
