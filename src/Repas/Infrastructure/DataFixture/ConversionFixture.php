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
        [
            "ingredient" => "ail",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 80
        ],
        [
            "ingredient" => "tortilla",
            "startUnit" => "paquet",
            "endUnit" => "piece",
            "coefficient" => 8
        ],
        [
            "ingredient" => "biscuit-cuillere",
            "startUnit" => "paquet",
            "endUnit" => "piece",
            "coefficient" => 36
        ],
        [
            "ingredient" => "bouillon-de-legume",
            "startUnit" => "cube",
            "endUnit" => "gramme",
            "coefficient" => 10
        ],
        [
            "ingredient" => "bouillon-de-boeuf",
            "startUnit" => "cube",
            "endUnit" => "piece",
            "coefficient" => 1
        ],
        [
            "ingredient" => "feuille-de-brique",
            "startUnit" => "paquet",
            "endUnit" => "piece",
            "coefficient" => 10
        ],
        [
            "ingredient" => "chevre",
            "startUnit" => "buche",
            "endUnit" => "gramme",
            "coefficient" => 180
        ],
        [
            "ingredient" => "miel",
            "startUnit" => "litre",
            "endUnit" => "gramme",
            "coefficient" => 1420
        ],
        [
            "ingredient" => "steack-hache",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 100,
        ],
        [
            "ingredient" => "pomme-de-terre",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 160,
        ],
        [
            "ingredient" => "pomme",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 125,
        ],
        [
            "ingredient" => "pomme-golden",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 200,
        ],
        [
            "ingredient" => "mozzarella",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 125,
        ],
        [
            "ingredient" => "nutella",
            "startUnit" => "kilo",
            "endUnit" => "pot",
            "coefficient" => 1,
        ],
        [
            "ingredient" => "soupe",
            "startUnit" => "brique",
            "endUnit" => "litre",
            "coefficient" => 1,
        ],
        [
            "ingredient" => "quenelle",
            "startUnit" => "paquet",
            "endUnit" => "piece",
            "coefficient" => 6,
        ],
        [
            "ingredient" => "courgette",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 250,
        ],
        [
            "ingredient" => "avocat",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 200,
        ],
        [
            "ingredient" => "bettrave",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 300,
        ],
        [
            "ingredient" => "brocoli",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 300,
        ],
        [
            "ingredient" => "chou-blanc",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 700,
        ],
        [
            "ingredient" => "chou-rouge",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 1250,
        ],
        [
            "ingredient" => "choux-fleur",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 850,
        ],
        [
            "ingredient" => "courge",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 1500,
        ],
        [
            "ingredient" => "échalote",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 30,
        ],
        [
            "ingredient" => "concombre",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 450,
        ],
        [
            "startUnit" => "sachet",
            "endUnit" => "paquet",
            "coefficient" => 1,
        ],
        [
            "startUnit" => "boite",
            "endUnit" => "paquet",
            "coefficient" => 1,
        ],
        [
            "startUnit" => "conserve",
            "endUnit" => "boite",
            "coefficient" => 1,
        ],
        [
            "startUnit" => "pot",
            "endUnit" => "boite",
            "coefficient" => 1,
        ],
        [
            "ingredient" => "jambon-blanc",
            "startUnit" => "tranche",
            "endUnit" => "gramme",
            "coefficient" => 35,
        ],
        [
            "ingredient" => "jambon-cru",
            "startUnit" => "tranche",
            "endUnit" => "gramme",
            "coefficient" => 20,
        ],
        [
            "ingredient" => "pain",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 200,
        ],
        [
            "ingredient" => "carotte",
            "startUnit" => "piece",
            "endUnit" => "gramme",
            "coefficient" => 125,
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
