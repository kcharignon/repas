<?php

namespace Repas\Repas\Infrastructure\Http\Api\ImportMarmitonRecipe;


use OpenApi\Attributes as OA;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de l'import d'une recette Marmiton : le lien collé, rien d'autre. Il n'est jamais appelé tel quel (`MarmitonUrl::parse` en tire
 * l'identifiant) ; ce corps ne juge que sa forme (un texte de 2 000 caractères au plus), le reste est une erreur métier (`MARMITON_URL_INVALID`).
 */
final readonly class ImportMarmitonRecipePayload
{
    public function __construct(
        // `mixed` : la valeur reçue est jugée telle quelle (un texte JSON, rien d'autre), sans conversion
        #[Assert\NotNull(message: PayloadMessages::MARMITON_URL)]
        #[Assert\Type(type: 'string', message: PayloadMessages::MARMITON_URL)]
        #[Assert\Length(max: 2000, maxMessage: PayloadMessages::MARMITON_URL)]
        #[OA\Property(description: "Le lien de la recette Marmiton collé par la personne (la page de la recette, par exemple `https://www.marmiton.org/recettes/recette_tarte-au-citron-meringuee_22082.aspx`). Seul l'identifiant de la recette est retenu : l'adresse lue est reconstruite, jamais celle-ci. 2 000 caractères au plus (`422` sinon)", type: 'string', maxLength: 2000, example: 'https://www.marmiton.org/recettes/recette_tarte-au-citron-meringuee_22082.aspx')]
        public mixed $url = null,
    ) {
    }
}
