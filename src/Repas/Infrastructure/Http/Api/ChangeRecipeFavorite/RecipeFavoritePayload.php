<?php

namespace Repas\Repas\Infrastructure\Http\Api\ChangeRecipeFavorite;


use OpenApi\Attributes as OA;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de la mise en favoris d'une recette : vrai ou faux, rien d'autre.
 */
final readonly class RecipeFavoritePayload
{
    public function __construct(
        // `mixed` : la valeur reçue est jugée telle quelle (un booléen JSON, rien d'autre), sans conversion
        #[Assert\NotNull(message: PayloadMessages::FAVORITE)]
        #[Assert\Type(type: 'bool', message: PayloadMessages::FAVORITE)]
        #[OA\Property(description: "Vrai : la recette est mise en favoris ; faux : elle en est retirée. Un booléen JSON, rien d'autre (`422` sinon). Renvoyer la valeur actuelle est sans effet", type: 'boolean', example: true)]
        public mixed $favorite = null,
    ) {
    }
}
