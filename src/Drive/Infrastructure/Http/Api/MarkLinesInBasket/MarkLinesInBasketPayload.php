<?php

namespace Repas\Drive\Infrastructure\Http\Api\MarkLinesInBasket;

use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\MarkLinesInBasket\MarkLinesInBasketCommand;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les articles que l'agent a mis au panier de l'enseigne (DRV-745) : des slugs d'ingrédients, distincts, 1 à 1 000. La liste et l'utilisateur
 * viennent de l'adresse et du jeton, jamais du corps.
 */
final readonly class MarkLinesInBasketPayload
{
    /**
     * @param list<mixed> $ingredientSlugs
     */
    public function __construct(
        #[Assert\Count(min: 1, max: MarkLinesInBasketCommand::MAX_SLUGS)]
        #[Assert\Unique]
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank(),
            new Assert\Regex(pattern: '/^[a-z0-9-]{1,255}$/D'),
        ])]
        #[OA\Property(
            description: "Slugs des ingrédients dont l'article est au panier : 1 à 1 000, distincts. Un slug qui n'est pas dans la liste est ignoré ; un ancien slug (ancien commun, ingrédient fusionné) désigne l'ingrédient actuel",
            type: 'array',
            items: new OA\Items(type: 'string', pattern: '^[a-z0-9-]{1,255}$', example: 'farine'),
            minItems: 1,
            maxItems: MarkLinesInBasketCommand::MAX_SLUGS,
            uniqueItems: true,
        )]
        public array $ingredientSlugs,
    ) {
    }
}
