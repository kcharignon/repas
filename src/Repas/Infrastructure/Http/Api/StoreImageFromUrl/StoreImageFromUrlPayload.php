<?php

namespace Repas\Repas\Infrastructure\Http\Api\StoreImageFromUrl;


use OpenApi\Attributes as OA;
use Repas\Repas\Infrastructure\Http\PayloadMessages;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps du téléchargement d'une image par lien : le lien direct, rien d'autre. Ce corps ne juge que sa forme (un texte de 2 000 caractères au plus) ;
 * le reste est une erreur métier (`IMAGE_URL_REFUSED`, `ImageUrl::parse`).
 */
final readonly class StoreImageFromUrlPayload
{
    public function __construct(
        // `mixed` : la valeur reçue est jugée telle quelle (un texte JSON, rien d'autre), sans conversion
        #[Assert\NotNull(message: PayloadMessages::IMAGE_URL)]
        #[Assert\Type(type: 'string', message: PayloadMessages::IMAGE_URL)]
        #[Assert\Length(max: 2000, maxMessage: PayloadMessages::IMAGE_URL)]
        #[OA\Property(description: "Le lien direct de l'image (`https://…`, un nom de domaine public, le port 443). 2 000 caractères au plus (`422` sinon)", type: 'string', maxLength: 2000, example: 'https://exemple.org/images/surgeles.png')]
        public mixed $url = null,
    ) {
    }
}
