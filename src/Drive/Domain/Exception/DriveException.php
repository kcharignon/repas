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
    public const string DRIVE_INGREDIENT_NOT_FOUND = 'DRIVE_INGREDIENT_NOT_FOUND';
    public const string DRIVE_SEARCH_QUERY_INVALID = 'DRIVE_SEARCH_QUERY_INVALID';
    public const string DRIVE_PACK_SIZE_INVALID = 'DRIVE_PACK_SIZE_INVALID';
    public const string DRIVE_STORE_MISMATCH = 'DRIVE_STORE_MISMATCH';
    public const string DRIVE_LIMIT_REACHED = 'DRIVE_LIMIT_REACHED';
    public const string DRIVE_RATE_LIMITED = 'DRIVE_RATE_LIMITED';
    public const string SHOPPING_LIST_NOT_SENT = 'SHOPPING_LIST_NOT_SENT';
    public const string SHOPPING_LIST_FORBIDDEN = 'SHOPPING_LIST_FORBIDDEN';

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
     * Un choix ne s'enregistre que sur un ingrédient de l'utilisateur : le slug reçu n'est ni l'un de ses ingrédients ni l'ancien slug
     * (alias valide) de l'un d'eux. Le slug d'un ingrédient qui n'existe pas et celui d'un ingrédient d'un autre compte sont
     * indiscernables, `404`. Rien n'est enregistré.
     */
    public static function ingredientNotFound(): self
    {
        return new self(self::DRIVE_INGREDIENT_NOT_FOUND, 404);
    }

    /**
     * Recherche mémorisée refusée (DRV-650) : pas un texte, moins de 2 ou plus de 80 caractères une fois les espaces ramenées à une
     * seule, ou un caractère de contrôle ou invisible. Règle métier, `422`, distincte de `invalidChoice()` pour que le client sache
     * que c'est ce texte-là qu'il doit corriger.
     */
    public static function searchQueryInvalid(): self
    {
        return new self(self::DRIVE_SEARCH_QUERY_INVALID, 422);
    }

    /**
     * Contenance mémorisée refusée (DRV-652) : valeur qui n'est pas un nombre de 0,001 à 9 999 avec trois décimales au plus, slug
     * d'unité mal formé, ou unité qui n'existe pas. Règle métier, `422`, distincte de `invalidChoice()` pour que le client sache que
     * c'est cette réponse-là qu'il doit corriger.
     */
    public static function packSizeInvalid(): self
    {
        return new self(self::DRIVE_PACK_SIZE_INVALID, 422);
    }

    /**
     * Plafond par utilisateur atteint (DRV-613, constat C2 de la revue de sécurité) : trop de préférences dans le magasin
     * (`Choice::MAX_PER_USER_AND_STORE`) ou trop d'écritures de produits aujourd'hui
     * (`DriveSettings::MAX_PRODUCT_WRITES_PER_DAY`). Les tables du drive sont communes : un compte ne peut pas les remplir.
     * Règle métier, `422`.
     */
    public static function limitReached(): self
    {
        return new self(self::DRIVE_LIMIT_REACHED, 422);
    }

    /**
     * Plafond de débit par utilisateur dépassé (DRV-615, constat C2 de la revue de sécurité) : trop d'écritures ou de lectures
     * coûteuses du drive dans la dernière minute. Levée par `DriveRateLimitListener` (`Infrastructure`), qui l'enveloppe dans
     * une `TooManyRequestsHttpException` portant `Retry-After`, `429`.
     */
    public static function rateLimited(): self
    {
        return new self(self::DRIVE_RATE_LIMITED, 429);
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
     * Le plan d'envoi ne se calcule que pour une liste envoyée au drive : celle-ci ne l'est pas (ou plus), `409`. Même message
     * que `ShoppingListException::notSent()` de `Repas`, qu'un contexte n'importe pas.
     */
    public static function shoppingListNotSent(): self
    {
        return new self(self::SHOPPING_LIST_NOT_SENT, 409);
    }

    /**
     * La liste appartient à un autre utilisateur, `403`. Le voter `SHOPPING_LIST_OWNER` du controller est la défense
     * principale (il laisse passer un administrateur) ; le plan, lui, ne se calcule que pour le propriétaire.
     */
    public static function shoppingListForbidden(): self
    {
        return new self(self::SHOPPING_LIST_FORBIDDEN, 403);
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
