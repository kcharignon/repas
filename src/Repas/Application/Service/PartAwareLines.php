<?php

namespace Repas\Repas\Application\Service;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\PartEquivalence;
use Repas\Shared\Domain\Tool\Tab;

/**
 * Prépare les quantités à ajouter à une liste de courses quand l'un des ingrédients est une PARTIE d'un autre (ADR 0038 décisions 9 et 14) : un ajout
 * (planifier, ajouter des personnes, ajouter à la main) se décide d'après l'ingrédient D'AUJOURD'HUI (`Ingredient::isPart()`, déjà chargé avec la
 * ligne de recette), un retrait d'après la LISTE (`ShoppingList::subtractLine`).
 *
 * Coût : le parent d'une partie n'est lu que s'il y en a une, en UNE lecture pour tous les parents (`IngredientRepository::findBySlugs`, sans
 * requête de plus pour ceux que Doctrine connaît déjà), avec la préparation de leurs conversions (`ConversionService::preloadIngredients`, aucune
 * pour un parent dont les deux unités sont les mêmes). Sans partie : aucune lecture, aucun appel de plus, les écritures d'avant.
 */
readonly class PartAwareLines
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private ConversionService $conversionService,
        private PartEquivalence $partEquivalence,
    ) {
    }

    /**
     * Les parents des parties parmi ces ingrédients, par slug. Vide, sans aucune lecture, quand aucun n'est une partie.
     *
     * @param iterable<Ingredient> $ingredients
     *
     * @return array<string, Ingredient>
     *
     * @throws IngredientException le parent d'une partie n'existe plus (404) : jamais une liste fausse
     */
    public function parentsOf(iterable $ingredients): array
    {
        $slugs = [];
        foreach ($ingredients as $ingredient) {
            if ($ingredient->isPart()) {
                $slugs[(string) $ingredient->getParentSlug()] = true;
            }
        }
        if ($slugs === []) {
            return [];
        }

        $parents = [];
        foreach ($this->ingredientRepository->findBySlugs(Tab::fromArray(array_keys($slugs))) as $parent) {
            $parents[$parent->getSlug()] = $parent;
        }
        $this->conversionService->preloadIngredients($parents);

        return $parents;
    }

    /**
     * La quantité à poser pour cet ingrédient : une ligne ordinaire, ou la part d'une partie avec son équivalent dans l'unité d'achat du parent.
     *
     * @param float                      $quantity dans l'unité d'achat de l'ingrédient
     * @param array<string, Ingredient>  $parents  ceux de `parentsOf()`
     *
     * @throws IngredientException le parent n'a pas été lu, ou il ne convertit pas son unité de recette en son unité d'achat
     */
    public function prepare(Ingredient $ingredient, float $quantity, array $parents): PreparedLine
    {
        $purchaseUnitSlug = $ingredient->getDefaultPurchaseUnit()->getSlug();
        if (!$ingredient->isPart()) {
            return new PreparedLine($ingredient->getSlug(), $purchaseUnitSlug, $quantity);
        }

        $parent = $parents[$ingredient->getParentSlug()] ?? throw IngredientException::notFound((string) $ingredient->getParentSlug());

        return new PreparedLine(
            $ingredient->getSlug(),
            $purchaseUnitSlug,
            $quantity,
            $parent->getSlug(),
            $parent->getDefaultPurchaseUnit()->getSlug(),
            $this->partEquivalence->of($ingredient, $parent, $quantity),
        );
    }

    /**
     * La part d'une partie ajoutée à la main (le sélecteur de produit) : l'ingrédient est lu par son slug (déjà dans Doctrine quand le placement
     * vient d'être lu), son parent en une lecture.
     *
     * @throws IngredientException l'ingrédient ou son parent est inconnu, ou le parent ne convertit pas son unité de recette
     */
    public function prepareBySlug(string $ingredientSlug, float $quantity): PreparedLine
    {
        $ingredient = $this->ingredientRepository->findOneBySlug($ingredientSlug);

        return $this->prepare($ingredient, $quantity, $this->parentsOf([$ingredient]));
    }
}
