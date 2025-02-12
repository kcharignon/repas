<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;

class ConversionInMemoryRepository extends AbstractInMemoryRepository implements ConversionRepository
{
    protected static function getClassName(): string
    {
        return Conversion::class;
    }

    public function findByIngredient(Ingredient $ingredient): Tab
    {
        return $this->models->filter(fn (Conversion $conversion) => $conversion->getIngredient()->isEqual($ingredient) || $conversion->getIngredient() === null);
    }

    public function save(Conversion $conversion): void
    {
        $this->models[$conversion->getId()] = $conversion;
    }

}
