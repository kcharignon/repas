<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Repas\Repas\Infrastructure\Entity\RecipeType as RecipeTypeEntity;

class RecipeTypeFixture extends RepasFixture implements FixtureGroupInterface
{
    const string FILE_NAME = "recipe_type.json";

    public static function getGroups(): array
    {
        return ['prod', 'test', 'dev'];
    }

    /**
     * @throws Exception
     */
    public function load(ObjectManager $manager): void
    {
        $filePath = $this->getFilePath(self::FILE_NAME);
        try {
            foreach ($this->readFileObjectByObject($filePath) as $recipeTypeData) {
                $recipeTypeEntity = new RecipeTypeEntity(
                    slug: $recipeTypeData['slug'],
                    name: $recipeTypeData['name'],
                    image: $recipeTypeData['image'],
                    sequence: $recipeTypeData['sequence'],
                );
                $manager->persist($recipeTypeEntity);

                $this->addReference($recipeTypeEntity->getSlug(), $recipeTypeEntity);
            }

            $manager->flush();
        } catch (Exception $e) {
            dump(sprintf("Failed to create RecipeType: %s", $recipeTypeData["slug"] ?? 'Unknown'));
            throw $e;
        }
    }
}
