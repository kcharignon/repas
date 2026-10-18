<?php

namespace Repas\Drive\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;
use Repas\Shared\Domain\Exception\DriveFeatureException;

final class DriveException extends DomainException
{
    public const string DRIVE_STORE_INVALID = 'DRIVE_STORE_INVALID';
    public const string DRIVE_NOT_CONFIGURED = 'DRIVE_NOT_CONFIGURED';
    public const string DRIVE_CHOICE_INVALID = 'DRIVE_CHOICE_INVALID';
    public const string DRIVE_CHOICE_NOT_FOUND = 'DRIVE_CHOICE_NOT_FOUND';
    public const string DRIVE_STORE_MISMATCH = 'DRIVE_STORE_MISMATCH';

    /**
     * Magasin refusé : adresse qui n'est pas celle d'un magasin de l'enseigne, identifiant différent de celui de l'adresse,
     * ou valeur trop longue. Règle métier, `422`.
     */
    public static function storeInvalid(): self
    {
        return new self(self::DRIVE_STORE_INVALID, 422);
    }

    /**
     * L'utilisateur n'a pas encore enregistré son magasin : rien à envoyer, `409`.
     */
    public static function notConfigured(): self
    {
        return new self(self::DRIVE_NOT_CONFIGURED, 409);
    }

    /**
     * Produit ou préférence refusés : valeur hors des bornes (identifiant, libellé, contenu, prix), plus de 5 produits acceptés
     * ou de 20 refusés, produit à la fois accepté et refusé, lot trop gros. Règle métier, `422`.
     */
    public static function invalidChoice(): self
    {
        return new self(self::DRIVE_CHOICE_INVALID, 422);
    }

    /**
     * L'utilisateur n'a pas de préférence pour cet ingrédient dans ce magasin, `404`.
     */
    public static function choiceNotFound(): self
    {
        return new self(self::DRIVE_CHOICE_NOT_FOUND, 404);
    }

    /**
     * Le magasin de la demande n'est pas le magasin courant de l'utilisateur (il l'a changé depuis), `409`.
     */
    public static function storeMismatch(): self
    {
        return new self(self::DRIVE_STORE_MISMATCH, 409);
    }

    /**
     * Interrupteur coupé : la fonction fait comme si elle n'existait pas, `404`. Même message que `DriveFeatureException`, que
     * `DriveEnabledListener` (`Shared`) lève avant d'entrer dans un controller.
     */
    public static function disabled(): self
    {
        return new self(DriveFeatureException::DRIVE_DISABLED, 404);
    }
}
