<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Repas\Repas\Infrastructure\Entity\Conversion as ConversionEntity;
use Repas\Shared\Domain\Tool\UuidGenerator;

class ConversionFixture extends RepasFixture implements DependentFixtureInterface, FixtureGroupInterface
{
    const string FILE_NAME = "conversion.json";

    public function getDependencies(): array
    {
        return [
            UnitFixture::class,
            IngredientFixture::class,
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
            foreach ($this->readFileObjectByObject($filePath) as $conversionData) {
                $conversionEntity = new ConversionEntity(
                    id: $conversionData["id"],
                    startUnitSlug: $conversionData["start_unit"],
                    endUnitSlug: $conversionData["end_unit"],
                    coefficient: $conversionData["coefficient"],
                    ingredientSlug: $conversionData["ingredient"],
                );

                $manager->persist($conversionEntity);
            }

            $manager->flush();
        } catch (Exception $e) {
            dump(sprintf("Failed to create Conversion: %s", $conversionData["id"] ?? 'Unknown'));
            throw $e;
        }
    }

}
