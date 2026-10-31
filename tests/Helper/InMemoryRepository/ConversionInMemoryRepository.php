<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

/**
 * @extends AbstractInMemoryRepository<Conversion>
 */
class ConversionInMemoryRepository extends AbstractInMemoryRepository implements ConversionRepository
{
    protected static function getClassName(): string
    {
        return Conversion::class;
    }

    /** Lectures des conversions d'UN ingrédient, et lectures groupées pour plusieurs (de quoi prouver qu'une recette n'en fait qu'une) */
    public int $singleReads = 0;
    public int $batchReads = 0;

    public function findByIngredientOrCommon(Ingredient $ingredient): Tab
    {
        $this->singleReads++;
        // Les conversions propres d'abord, comme le repository de la base
        return $this->findByIngredient($ingredient)->merge($this->models->filter(fn (Conversion $conversion) => $conversion->getIngredient() === null));
    }

    public function findByIngredientsOrCommon(array $ingredients): array
    {
        $this->batchReads++;
        $byIngredient = [];
        foreach ($ingredients as $ingredient) {
            $byIngredient[$ingredient->getSlug()] = $this->ownThenCommon($ingredient);
        }

        return $byIngredient;
    }

    private function ownThenCommon(Ingredient $ingredient): Tab
    {
        return $this->findByIngredient($ingredient)->merge($this->models->filter(fn (Conversion $conversion) => $conversion->getIngredient() === null));
    }

    public function findByIngredient(Ingredient $ingredient): Tab
    {
        return $this->models->filter(fn (Conversion $conversion) => $ingredient->isEqual($conversion->getIngredient()));
    }


    public function ingredientHasAtLeast(Ingredient $ingredient, int $count): bool
    {
        return $this->findByIngredient($ingredient)->count() >= $count;
    }

    public function save(Conversion $conversion): void
    {
        $this->models[$conversion->getId()] = $conversion;
    }

    public function delete(Conversion $conversion): void
    {
        unset($this->models[$conversion->getId()]);
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
        $ingredient->isEqual($conversion->getIngredient())
            && $conversion->getStartUnit()->isEqual($startUnit)
            && $conversion->getEndUnit()->isEqual($endUnit)
        );
    }

    public function findOwnBetween(Ingredient $ingredient, Unit $a, Unit $b): Tab
    {
        $found = $this->models->filter(fn(Conversion $conversion) =>
            $ingredient->isEqual($conversion->getIngredient())
            && (
                ($conversion->getStartUnit()->isEqual($a) && $conversion->getEndUnit()->isEqual($b))
                || ($conversion->getStartUnit()->isEqual($b) && $conversion->getEndUnit()->isEqual($a))
            )
        )->toArray();
        // Même ordre que la base : par identifiant
        uasort($found, fn(Conversion $x, Conversion $y) => strcmp($x->getId(), $y->getId()));

        return Tab::fromArray(...array_values($found));
    }

    public function deleteByIngredient(Ingredient $ingredient): void
    {
        $this->models = $this->models->filter(fn (Conversion $conversion) => !$ingredient->isEqual($conversion->getIngredient()));
    }

    public function deleteByIngredientCreator(string $creatorId): void
    {
        $this->models = $this->models->filter(fn (Conversion $conversion) => $conversion->getIngredient()?->getCreatorId() !== $creatorId);
    }
}
