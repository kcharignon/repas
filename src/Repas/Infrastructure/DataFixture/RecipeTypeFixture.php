<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Infrastructure\Entity\RecipeType as RecipeTypeEntity;

class RecipeTypeFixture extends Fixture
{
    const RECIPE_TYPES = [
        [
            "name" => "plat",
            "image" => "images/recipe/type/meal.svg",
            "order" => 2,
        ],
        [
            "name" => "dessert",
            "image" => "images/recipe/type/dessert.svg",
            "order" => 3,
        ],
        [
            "name" => "entrée",
            "image" => "images/recipe/type/starter.svg",
            "order" => 1,
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::RECIPE_TYPES as $recipeTypeData) {
            $recipeTypeModel = RecipeType::create(
                $recipeTypeData['name'],
                $recipeTypeData['image'],
                $recipeTypeData['order'],
            );

            $recipeTypeEntity = RecipeTypeEntity::fromModel($recipeTypeModel);
            $manager->persist($recipeTypeEntity);

            $this->addReference($recipeTypeEntity->getSlug(), $recipeTypeEntity);
        }

        $manager->flush();
    }
}
