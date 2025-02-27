<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

class ConversionInMemoryRepository extends AbstractInMemoryRepository implements ConversionRepository
{
    protected static function getClassName(): string
    {
        return Conversion::class;
    }

    public function findByIngredientOrCommon(Ingredient $ingredient): Tab
    {
        return $this->models->filter(fn (Conversion $conversion) => $conversion->getIngredient() === null || $conversion->getIngredient()->isEqual($ingredient));
    }

    public function findByIngredient(Ingredient $ingredient): Tab
    {
        return $this->models->filter(fn (Conversion $conversion) => $ingredient->isEqual($conversion->getIngredient()));
    }


    public function save(Conversion $conversion): void
    {
        $this->models[$conversion->getId()] = $conversion;
    }

    public function findAll(): Tab
    {
        return $this->models;
    }

    /**
     * @throws ConversionException
     */
    public function findById(string $id): ?Conversion
    {
        return $this->models[$id] ?? throw ConversionException::notFound($id);
    }

    public function findByIngredientAndStartUnitAndEndUnit(Ingredient $ingredient, Unit $startUnit, Unit $endUnit): ?Conversion
    {
        return $this->models->find(fn(Conversion $conversion) =>
        ($conversion->getIngredient() === null || $conversion->getIngredient()->isEqual($ingredient))
            && $conversion->getStartUnit()->isEqual($startUnit)
            && $conversion->getEndUnit()->isEqual($endUnit)
        );
    }

    public function deleteByIngredient(Ingredient $ingredient): void
    {
        $this->models = $this->models->filter(fn (Conversion $conversion) => !$ingredient->isEqual($conversion->getIngredient()));
    }
}
