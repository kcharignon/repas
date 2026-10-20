<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

/**
 * Les refus de la migration d'adoption des communs (ING-003, `docs/ingredients/migration.md`) : des listes envoyées au drive qui n'ont pas
 * été acceptées, un slug trop long, une empreinte qui diverge. Cette migration est une commande de console, pas une route : les messages
 * sont écrits en français pour l'opérateur qui la lance, et ne contiennent jamais d'identifiant d'utilisateur, d'adresse ni de nom
 * d'ingrédient personnel (les sorties de la commande sont conservées dans des journaux).
 */
final class IngredientAdoptionException extends DomainException
{
    public static function sentShoppingListsNotAccepted(int $count): static
    {
        return new static(
            sprintf("%d liste(s) déjà envoyée(s) au drive seraient modifiées : elles sont immuables (ADR 0023), l'acceptation explicite est nécessaire (--accept-sent).", $count),
            409,
        );
    }

    public static function slugTooLong(string $commonSlug, int $length): static
    {
        return new static(sprintf("Le slug de la copie du commun '%s' ferait %d caractères, le maximum est 255.", $commonSlug, $length), 422);
    }

    /**
     * Une empreinte d'après ne correspond pas à celle d'avant : le travail de cet utilisateur est annulé en entier (la transaction n'est
     * pas validée), rien n'est écrit.
     *
     * @param non-empty-list<string> $discrepancies
     */
    public static function verificationFailed(array $discrepancies): static
    {
        return new static(
            "Les empreintes d'avant et d'après ne correspondent pas, rien n'est écrit pour ce compte (transaction annulée) : " . implode(' ; ', $discrepancies),
            500,
        );
    }
}
