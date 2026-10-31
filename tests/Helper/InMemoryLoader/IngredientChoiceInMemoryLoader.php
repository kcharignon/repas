<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\IngredientChoiceLoader;
use Repas\Tests\Helper\InMemoryRepository\IngredientChoiceInMemoryRepository;

/**
 * La lecture des choix de produits par ingrédient sur les mêmes tableaux que `IngredientChoiceInMemoryRepository` (celui que le test
 * remplit) : le rapport lit ce que l'écriture a écrit. Le SQL est prouvé sur la vraie base (`tests/Drive/OutputAdapter/IngredientChoiceLoaderTest`).
 */
final readonly class IngredientChoiceInMemoryLoader implements IngredientChoiceLoader
{
    public function __construct(
        private IngredientChoiceInMemoryRepository $choices,
    ) {
    }

    public function fingerprint(string $userId): array
    {
        $fingerprint = $this->choices->fingerprint($userId);

        return [
            'choices' => $fingerprint->choices,
            'products' => $fingerprint->products,
            'packSizeSum' => $fingerprint->packSizeSum,
            'contentHash' => $fingerprint->contentHash,
            'ingredientSlugByChoice' => $fingerprint->ingredientSlugByChoice,
            'productCountByChoice' => $fingerprint->productCountByChoice,
        ];
    }

    public function referencedSlugs(string $userId): array
    {
        return $this->choices->referencedSlugs($userId);
    }

    public function referencedSlugsOfEveryUser(): array
    {
        return $this->choices->referencedSlugsOfEveryUser();
    }

    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): array
    {
        $impact = $this->choices->mergeImpact($userId, $absorbedSlug, $keptSlug);

        return ['moved' => $impact->choicesMoved, 'dropped' => $impact->choicesDropped, 'products' => $impact->productsDropped];
    }
}
