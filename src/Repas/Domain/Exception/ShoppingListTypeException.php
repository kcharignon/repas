<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

final class ShoppingListTypeException extends DomainException
{
    /** Les messages sont des constantes : le front les distingue, ils ne se renomment pas. */
    public const string NOT_FOUND = 'LIST_TYPE_NOT_FOUND';
    public const string LIMIT_REACHED = 'LIST_TYPE_LIMIT_REACHED';
    public const string NAME_ALREADY_USED = 'LIST_TYPE_NAME_ALREADY_USED';
    public const string NAME_INVALID = 'LIST_TYPE_NAME_INVALID';

    /**
     * Le type est inconnu, ou il est à un autre utilisateur : le même refus, on ne révèle pas son existence (ADR 0036 décision 13).
     */
    public static function notFound(): static
    {
        return new static(self::NOT_FOUND, 404);
    }

    /** Le compte a déjà `ShoppingListType::MAX_PER_OWNER` types : il n'en crée pas d'autre. Règle métier, `422`. */
    public static function limitReached(): static
    {
        return new static(self::LIMIT_REACHED, 422);
    }

    /** Le compte a déjà un type de ce nom, une fois normalisé. Règle métier, `409`. */
    public static function nameAlreadyUsed(): static
    {
        return new static(self::NAME_ALREADY_USED, 409);
    }

    /** Un nom de type compte de 1 à `ShoppingListType::MAX_NAME_LENGTH` caractères et au moins une lettre ou un chiffre. Règle métier, `422`. */
    public static function invalidName(): static
    {
        return new static(self::NAME_INVALID, 422);
    }
}
