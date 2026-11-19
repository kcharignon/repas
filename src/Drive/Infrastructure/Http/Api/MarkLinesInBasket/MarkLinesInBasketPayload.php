<?php

namespace Repas\Drive\Infrastructure\Http\Api\MarkLinesInBasket;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\MarkLinesInBasket\MarkLinesInBasketCommand;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Ce que le passage au drive a fait des lignes (DRV-745, ADR 0041) : les articles que l'agent a mis au panier de l'enseigne (des slugs d'ingrédients,
 * distincts, 0 à 1 000) et, facultativement, ceux qu'il n'y a pas mis, avec leur issue (0 à 1 000). Au moins une ligne dans l'un des deux. La liste et
 * l'utilisateur viennent de l'adresse et du jeton, jamais du corps.
 */
final readonly class MarkLinesInBasketPayload
{
    /**
     * @param list<mixed> $ingredientSlugs
     * @param list<NotAddedLinePayload> $notAdded
     */
    public function __construct(
        #[Assert\Count(max: MarkLinesInBasketCommand::MAX_SLUGS)]
        #[Assert\Unique]
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank(),
            new Assert\Regex(pattern: '/^[a-z0-9-]{1,255}$/D'),
        ])]
        #[OA\Property(
            description: "Slugs des ingrédients dont l'article est au panier : 0 à 1 000, distincts, au moins une ligne ici ou dans `notAdded`. Un slug qui n'est pas dans la liste est ignoré ; un ancien slug (ancien commun, ingrédient fusionné) désigne l'ingrédient actuel",
            type: 'array',
            items: new OA\Items(type: 'string', pattern: '^[a-z0-9-]{1,255}$', example: 'farine'),
            maxItems: MarkLinesInBasketCommand::MAX_SLUGS,
            uniqueItems: true,
        )]
        public array $ingredientSlugs = [],
        #[Assert\Count(max: MarkLinesInBasketCommand::MAX_SLUGS)]
        #[Assert\Valid]
        #[OA\Property(
            description: "Les lignes que le passage n'a PAS mises au panier, avec leur issue (ADR 0041) : 0 à 1 000, facultatif (un site plus ancien ne l'envoie pas). Elles restent décochées ; l'issue est gardée sur la ligne pour comprendre la liste, rien ne s'appuie dessus",
            type: 'array',
            items: new OA\Items(ref: new Model(type: NotAddedLinePayload::class)),
            maxItems: MarkLinesInBasketCommand::MAX_SLUGS,
        )]
        public array $notAdded = [],
    ) {
    }

    #[Assert\Callback]
    public function assertNotEmpty(ExecutionContextInterface $context): void
    {
        if ($this->ingredientSlugs === [] && $this->notAdded === []) {
            $context->buildViolation('Au moins une ligne est attendue, au panier ou non.')->atPath('ingredientSlugs')->addViolation();
        }
    }
}
