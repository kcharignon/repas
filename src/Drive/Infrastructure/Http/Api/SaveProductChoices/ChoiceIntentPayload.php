<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveProductChoices;


use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceAction;
use Repas\Drive\Application\Command\SaveProductChoices\ChoiceIntent;
use Repas\Drive\Application\Command\SaveProductChoices\Placement;
use Repas\Drive\Application\Command\SaveProductChoices\SearchQueryChange;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\SearchQuery;
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
        #[OA\Property(
            description: "Le texte qui a fait trouver le produit sur le site de l'enseigne (DRV-650), pour `ADD_PRODUCT` seulement : espaces de bord retirées et suites d'espaces réduites à une seule, 2 à 80 caractères, aucun caractère de contrôle ni invisible (sinon `422` `DRIVE_SEARCH_QUERY_INVALID`, et rien n'est enregistré). **Clé absente : la recherche déjà mémorisée est gardée** (une application qui ne connaît pas ce champ n'efface rien ; le `default: null` que le générateur écrit ici est un artefact, une clé absente n'est pas `null`). **`null` : elle est effacée.** Un texte : elle est remplacée. La recherche est propre à l'enseigne, au magasin et à l'ingrédient de ce choix, et n'est jamais reprise d'ailleurs. Sur une autre action, la mentionner (texte ou `null`) est `422` `DRIVE_CHOICE_INVALID`",
            maxLength: 80,
            example: 'céréales coussins chocolat',
            nullable: true,
        )]
        public ?string $searchQuery = null,
    ) {
    }

    /**
     * @param bool $mentionsSearchQuery vrai si le corps de la requête porte la clé `searchQuery` pour cette intention, même à `null` :
     *                                  absente de la charge utile et `null` ne se distinguent pas, c'est le corps brut qui le dit
     * @param bool $mentionsPackSize    de même pour la clé `packSize` du produit de cette intention (DRV-652)
     *
     * @throws DriveException `searchQueryInvalid()` (422) pour une recherche hors des bornes, `packSizeInvalid()` (422) pour une
     *                        contenance hors des bornes
     */
    public function toIntent(bool $mentionsSearchQuery, bool $mentionsPackSize = false): ChoiceIntent
    {
        $searchQueryChange = match (true) {
            !$mentionsSearchQuery => null,
            $this->searchQuery === null => SearchQueryChange::clear(),
            default => SearchQueryChange::set(SearchQuery::of($this->searchQuery)),
        };

        return new ChoiceIntent($this->ingredientSlug, $this->action, $this->placement, $this->product?->toInput($mentionsPackSize), $searchQueryChange);
    }
}
