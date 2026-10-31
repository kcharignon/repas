<?php

namespace Repas\Drive\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class DriveFeatureException extends DomainException
{
    public const string DRIVE_DISABLED = 'DRIVE_DISABLED';
    public const string DRIVE_RETAILER_DISABLED = 'DRIVE_RETAILER_DISABLED';

    /**
     * L'interrupteur du drive est coupé : la route fait comme si elle n'existait pas, `404` (docs/drive/architecture.md § 4.5).
     */
    public static function disabled(): self
    {
        return new self(self::DRIVE_DISABLED, 404);
    }

    /**
     * L'enseigne demandée n'est pas allumée (`DRIVE_RETAILERS`, DRV-702) : pour l'utilisateur elle n'existe pas, `404`. Code distinct de
     * `DRIVE_DISABLED` (que l'application connaît déjà et qui coupe tout le drive) ; les données de l'enseigne restent en base.
     */
    public static function retailerDisabled(): self
    {
        return new self(self::DRIVE_RETAILER_DISABLED, 404);
    }
}
