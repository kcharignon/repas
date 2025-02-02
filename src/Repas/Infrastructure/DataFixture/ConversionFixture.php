<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Repas\Repas\Infrastructure\Entity\Conversion as ConversionEntity;
use Repas\Shared\Domain\Tool\UuidGenerator;

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
        [
            "ingredient" => "salade",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 289
        ],
        [
            "ingredient" => "salade-verte",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 289
        ],
        [
            "ingredient" => "cheddar-en-tranche",
            "startUnit" => "piece",
            "endUnit" => "tranche",
            "coefficient" => 1
        ],
        [
            "ingredient" => "ketchup",
            "startUnit" => "centilitre",
            "endUnit" => "gramme",
            "coefficient" => 11.375
        ],
        [
            "ingredient" => "bearnaise",
            "startUnit" => "centilitre",
            "endUnit" => "gramme",
            "coefficient" => 9.875
        ],
        [
            "ingredient" => "mayonnaise",
            "startUnit" => "centilitre",
            "endUnit" => "gramme",
            "coefficient" => 9.875
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
            $conversionEntity = new ConversionEntity(
                id: UuidGenerator::new(),
                startUnitSlug: $conversion["startUnit"],
                endUnitSlug: $conversion["endUnit"],
                coefficient: $conversion["coefficient"],
                ingredientSlug: $conversion["ingredient"] ?? null,
            );

            $manager->persist($conversionEntity);
        }

        $manager->flush();
    }

}
