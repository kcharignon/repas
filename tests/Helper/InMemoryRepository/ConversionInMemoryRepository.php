<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;

class ConversionInMemoryRepository implements ConversionRepository
{
    /**
     * @var Tab<string, Conversion>
     */
    private Tab $conversions;

    /**
     * @param ?Tab<Conversion> $conversions
     */
    public function __construct(?Tab $conversions = null)
    {
        $conversions ??= Tab::newEmptyTyped(Conversion::class);
        $this->conversions = Tab::newEmptyTyped(Conversion::class);
        foreach ($conversions as $conversion) {
            $this->save($conversion);
        }
    }


    public function findByIngredient(Ingredient $ingredient): Tab
    {
        return $this->conversions->filter(fn (Conversion $conversion) => $conversion->getIngredient()->isEqual($ingredient) || $conversion->getIngredient() === null);
    }

    public function save(Conversion $conversion): void
    {
        $this->conversions[$conversion->getId()] = $conversion;
    }

}
