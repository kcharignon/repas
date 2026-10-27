<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

final class Conversion implements ModelInterface
{
    use ModelTrait;

    /**
     * Nombre de conversions propres à partir duquel un ingrédient n'en reçoit plus : seule l'ÉCRITURE d'une conversion (par son propriétaire ou
     * par l'administrateur sur les siens) est refusée ; la copie d'un ingrédient (amorçage, copie de recette, fusion) n'y passe pas.
     */
    public const int MAX_PER_INGREDIENT = 50;

    protected function __construct(
        private string      $id,
        private Unit        $startUnit,
        private Unit        $endUnit,
        private float       $coefficient,
        private ?Ingredient $ingredient,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStartUnit(): Unit
    {
        return $this->startUnit;
    }

    public function getEndUnit(): Unit
    {
        return $this->endUnit;
    }

    public function getCoefficient(): float
    {
        return $this->coefficient;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public static function load(array $datas): self {
        return new self(
            id: $datas['id'],
            startUnit: $datas['start_unit'],
            endUnit: $datas['end_unit'],
            coefficient: $datas['coefficient'],
            ingredient: $datas['ingredient'],
        );
    }

    /**
     * @throws ConversionException le coefficient n'est pas un nombre fini de 0,000001 à 1 000 000
     */
    public static function create(
        string $id,
        Unit $startUnit,
        Unit $endUnit,
        float $coefficient,
        ?Ingredient $ingredient,
    ): self {
        self::assertCoefficient($coefficient);

        return new self(
            $id,
            $startUnit,
            $endUnit,
            $coefficient,
            $ingredient,
        );
    }

    public static function copyFromOriginal(string $id, Conversion $conversion, Ingredient $newIngredient): self
    {
        // Copie fidèle : le coefficient de l'original n'est pas jugé à nouveau (une conversion plus ancienne que les bornes se copie telle quelle)
        return new self(
            $id,
            $conversion->getStartUnit(),
            $conversion->getEndUnit(),
            $conversion->getCoefficient(),
            $newIngredient,
        );
    }

    /**
     * @throws ConversionException le coefficient n'est pas un nombre fini de 0,000001 à 1 000 000
     */
    public function update(
        Unit $startUnit,
        Unit $endUnit,
        float $coefficient,
        ?Ingredient $ingredient = null,
    ): void {
        self::assertCoefficient($coefficient);

        $this->startUnit = $startUnit;
        $this->endUnit = $endUnit;
        $this->coefficient = $coefficient;
        $this->ingredient = $ingredient;
    }

    private static function assertCoefficient(float $coefficient): void
    {
        if (!QuantityBounds::isCoefficient($coefficient)) {
            throw ConversionException::invalidCoefficient();
        }
    }
}
