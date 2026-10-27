<?php

namespace Repas\Repas\Domain\Model;

use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

final class RecipeRow implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $id,
        private string $recipeId,
        private Ingredient $ingredient,
        private float $quantity,
        private Unit $unit,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getIngredient(): Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(Ingredient $ingredient): void
    {
        $this->ingredient = $ingredient;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    /**
     * @throws RecipeException la quantité n'est pas un nombre fini, strictement positif, d'un million au plus
     */
    public function setQuantity(float $quantity): void
    {
        self::assertQuantity($quantity);
        $this->quantity = $quantity;
    }

    /**
     * Met la quantité à l'échelle (nombre de personnes d'un repas) : le produit reste un nombre fini, sans quoi il ne s'écrirait ni en liste ni
     * en JSON. Pas de maximum ici : une recette d'un million pour 100 personnes dépasse un million.
     *
     * @throws RecipeException le produit n'est pas un nombre fini
     */
    public function multiplyQuantityBy(float $coefficient): self
    {
        $scaled = $this->quantity * $coefficient;
        if (!is_finite($scaled)) {
            throw RecipeException::invalidQuantity();
        }
        $this->quantity = $scaled;

        return $this;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): void
    {
        $this->unit = $unit;
    }

    public function getRecipeId(): string
    {
        return $this->recipeId;
    }

    public function setRecipeId(string $recipeId): RecipeRow
    {
        $this->recipeId = $recipeId;
        return $this;
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            recipeId: $datas['recipe_id'],
            ingredient: $datas['ingredient'],
            quantity: $datas['quantity'],
            unit: $datas['unit'],
        );
    }

    /**
     * @throws RecipeException la quantité n'est pas un nombre fini, strictement positif, d'un million au plus
     */
    public static function create(
        string $id,
        string $recipeId,
        Ingredient $ingredient,
        float $quantity,
        Unit $unit
    ): self {
        self::assertQuantity($quantity);

        return new self($id, $recipeId, $ingredient, $quantity, $unit);
    }

    /**
     * La ligne copiée dans une autre recette : même quantité, même unité, et l'ingrédient que le copieur a RETENU pour cette ligne
     * (`CopyRecipeHandler` : le sien, retrouvé par sa provenance ou son nom, ou une copie) - la ligne ne crée plus d'ingrédient.
     */
    public static function copyFromOriginal(
        string $id,
        RecipeRow $originalRow,
        string $recipeId,
        Ingredient $ingredient,
    ): self {
        // Copie fidèle : la quantité de l'original n'est pas jugée à nouveau (une donnée plus ancienne que les bornes se copie telle quelle)
        return new self(
            id: $id,
            recipeId: $recipeId,
            ingredient: $ingredient,
            quantity: $originalRow->getQuantity(),
            unit: $originalRow->getUnit(),
        );
    }

    private static function assertQuantity(float $quantity): void
    {
        if (!QuantityBounds::isQuantity($quantity)) {
            throw RecipeException::invalidQuantity();
        }
    }

    public function getDepartment(): Department
    {
        return $this->ingredient->getDepartment();
    }
}
