<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceAction;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\Placement;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChoiceIntentPayload
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[OA\Property(description: "Slug de l'ingrédient (minuscules, chiffres et tirets, 255 au plus)", example: 'farine')]
        public string $ingredientSlug,
        #[OA\Property(
            description: "`ADD_PRODUCT` (`product` avec `label` et `placement` requis) ; `REJECT_PRODUCT` (`product` avec `label` requis) ; `HAVE_AT_HOME` (ni produit ni placement) ; `REFRESH_PRICE` (`product` avec `retailerProductId` et `price` seulement : rafraîchit le prix d'un produit déjà accepté pour cet ingrédient, sans changer la préférence ; sans effet sinon)",
            enum: ['ADD_PRODUCT', 'REJECT_PRODUCT', 'HAVE_AT_HOME', 'REFRESH_PRICE'],
            example: 'ADD_PRODUCT',
        )]
        public ChoiceAction $action,
        #[OA\Property(description: "Pour `ADD_PRODUCT` : `PRIMARY` (nouveau produit préféré, rang 1) ou `FALLBACK` (produit de secours, après les autres)", enum: ['PRIMARY', 'FALLBACK'], example: 'PRIMARY', nullable: true)]
        public ?Placement $placement = null,
        #[Assert\Valid]
        #[OA\Property(description: 'Le produit concerné, selon `action`', nullable: true)]
        public ?ProductPayload $product = null,
    ) {
    }

    public function toIntent(): ChoiceIntent
    {
        return new ChoiceIntent($this->ingredientSlug, $this->action, $this->placement, $this->product?->toInput());
    }
}
