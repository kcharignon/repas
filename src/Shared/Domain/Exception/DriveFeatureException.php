<?php

namespace Repas\Shared\Domain\Exception;


final class DriveFeatureException extends DomainException
{
    public const string DRIVE_DISABLED = 'DRIVE_DISABLED';

    /**
     * L'interrupteur du drive est coupé : la route fait comme si elle n'existait pas, `404` (docs/drive/architecture.md § 4.5).
     */
    public static function disabled(): self
    {
        return new self(self::DRIVE_DISABLED, 404);
    }
}
