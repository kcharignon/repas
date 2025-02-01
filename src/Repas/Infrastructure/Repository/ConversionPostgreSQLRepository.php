<?php

namespace Repas\Repas\Infrastructure\Repository;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;

class ConversionPostgreSQLRepository implements ConversionRepository
{
    public function findByIngredient(Ingredient $ingredient): Tab
    {

    }
}
