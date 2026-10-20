<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

/**
 * Les refus de la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` § 6) : un classement qui ne correspond pas aux ingrédients
 * sans créateur, une précondition qui manque, une empreinte qui diverge. La passation est une commande de console, pas une route : les messages
 * sont écrits en français pour l'opérateur et ne contiennent jamais d'identifiant d'utilisateur, d'adresse ni de nom d'ingrédient personnel (les
 * sorties de la commande sont conservées dans des journaux).
 */
final class IngredientCatalogException extends DomainException
{
    /**
     * Le classement (phase 1) refuse d'écrire tant que l'ensemble des ingrédients sans créateur n'est pas EXACTEMENT celui du fichier.
     */
    public static function classificationMismatch(int $unknown, int $missing): static
    {
        return new static(
            sprintf(
                "Le classement ne correspond pas aux ingrédients sans créateur : %d ne sont pas dans le fichier, %d du fichier n'y sont pas. Il faut classer ce commun d'abord (demande au propriétaire, puis le fichier de classement) : rien n'est écrit.",
                $unknown,
                $missing,
            ),
            422,
        );
    }

    /**
     * La passation (phase 4) relit ses préconditions dans sa transaction : si l'une manque, rien n'est écrit et le message dit laquelle.
     *
     * @param non-empty-list<string> $unmet les phrases des préconditions qui manquent
     */
    public static function preconditionsNotMet(array $unmet): static
    {
        return new static('La passation est refusée, rien n\'est écrit : ' . implode(' ; ', $unmet), 409);
    }

    /**
     * Une empreinte d'après ne correspond pas à celle d'avant : la transaction est annulée, rien n'est écrit.
     *
     * @param non-empty-list<string> $discrepancies
     */
    public static function handoverVerificationFailed(array $discrepancies): static
    {
        return new static(
            "Les empreintes d'avant et d'après la passation ne correspondent pas, rien n'est écrit (transaction annulée) : " . implode(' ; ', $discrepancies),
            500,
        );
    }
}
