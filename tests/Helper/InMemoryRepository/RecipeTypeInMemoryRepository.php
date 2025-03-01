<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;

class RecipeTypeInMemoryRepository extends AbstractInMemoryRepository implements RecipeTypeRepository
{
    use SaveModelTrait;

    protected static function getClassName(): string
    {
        return RecipeType::class;
    }

    public function findAll(): Tab
    {
        return $this->models;
    }

    public function findOneBySlug(string $slug): RecipeType
    {
        return $this->models[$slug] ?? throw RecipeException::typeNotFound($slug);
    }

}
