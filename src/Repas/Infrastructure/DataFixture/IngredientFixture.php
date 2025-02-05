<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Shared\Domain\Tool\StringTool;

class IngredientFixture extends RepasFixture implements DependentFixtureInterface, FixtureGroupInterface
{
    const string FILE_NAME = "ingredient.json";

    public function getDependencies(): array
    {
        return [
            UnitFixture::class,
            DepartmentFixture::class,
        ];
    }

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
            foreach ($this->readFileObjectByObject($filePath) as $ingredientData) {
                $ingredientEntity = new IngredientEntity(
                    slug: $ingredientData['slug'],
                    name: $ingredientData['name'],
                    image: $ingredientData['image'] ?? '',
                    department: $ingredientData['department'],
                    defaultCookingUnit: $ingredientData['default_cooking_unit'],
                    defaultPurchaseUnit: $ingredientData['default_purchase_unit'],
                );
                $manager->persist($ingredientEntity);

                $this->addReference($ingredientEntity->getSlug(), $ingredientEntity);
            }

            $manager->flush();
        } catch (Exception $e) {
            dump(sprintf("Failed to create Ingredient: %s", $ingredientData["name"] ?? 'Unknown'));
            throw $e;
        }
    }
}
