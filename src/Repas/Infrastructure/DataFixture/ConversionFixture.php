<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\ConversionSpecific;
use Repas\Repas\Infrastructure\Entity\Conversion as ConversionEntity;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Unit;

class ConversionFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const array CONVERSIONS = [
        [
            "startUnit" => "litre",
            "endUnit" => "centilitre",
            "coefficient" => 100,
        ],
        [
            "startUnit" => "litre",
            "endUnit" => "millilitre",
            "coefficient" => 1000,
        ],
        [
            "startUnit" => "centilitre",
            "endUnit" => "millilitre",
            "coefficient" => 10,
        ],
        [
            "startUnit" => "bol",
            "endUnit" => "centilitre",
            "coefficient" => 35,
        ],
        [
            "startUnit" => "verre",
            "endUnit" => "centilitre",
            "coefficient" => 25,
        ],
        [
            "startUnit" => "tasse-a-cafe",
            "endUnit" => "centilitre",
            "coefficient" => 10,
        ],
        [
            "startUnit" => "cuillere-a-cafe",
            "endUnit" => "millilitre",
            "coefficient" => 5,
        ],
        [
            "startUnit" => "cuillere-a-soupe",
            "endUnit" => "millilitre",
            "coefficient" => 15,
        ],
        [
            "startUnit" => "kilo",
            "endUnit" => "gramme",
            "coefficient" => 1000,
        ],
        [
            "ingredient" => "beurre",
            "startUnit" => "plaquette",
            "endUnit" => "gramme",
            "coefficient" => 250
        ],
        [
            "ingredient" => "pain-a-burger",
            "startUnit" => "paquet",
            "endUnit" => "piece",
            "coefficient" => 4
        ],
    ];

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

    public function load(ObjectManager $manager): void
    {
        foreach (self::CONVERSIONS as $conversion) {
            $startUnitEntity = $this->getReference($conversion["startUnit"], Unit::class);
            $endUnitEntity = $this->getReference($conversion["endUnit"], Unit::class);
            $ingredientEntity = ($conversion["ingredient"] ?? null) ? $this->getReference($conversion["ingredient"], Ingredient::class) : null;
            $conversionModel = Conversion::create(
                $startUnitEntity->getModel(),
                $endUnitEntity->getModel(),
                $conversion["coefficient"],
                $ingredientEntity?->getModel(),
            );

            $conversionEntity = ConversionEntity::fromModel($conversionModel);
            $conversionEntity->setStartUnit($startUnitEntity);
            $conversionEntity->setEndUnit($endUnitEntity);
            $conversionEntity->setIngredient($ingredientEntity);

            $manager->persist($conversionEntity);
        }

        $manager->flush();
    }

}
