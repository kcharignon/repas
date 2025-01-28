<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;

class UnitFixture extends Fixture implements FixtureGroupInterface
{
    const array UNITS = [
        [
            "name" => "piece",
            "symbol" => ""
        ],
        [
            "name" => "kilo",
            "symbol" => "kg"
        ],
        [
            "name" => "gramme",
            "symbol" => "g"
        ],
        [
            "name" => "litre",
            "symbol" => "l"
        ],
        [
            "name" => "millilitre",
            "symbol" => "ml"
        ],
        [
            "name" => "centilitre",
            "symbol" => "cl"
        ],
        [
            "name" => "cuillère à soupe",
            "symbol" => "cuillère à soupe"
        ],
        [
            "name" => "cuillère à café",
            "symbol" => "cuillère à café"
        ],
        [
            "name" => "tasse à café",
            "symbol" => "tasse à café"
        ],
        [
            "name" => "verre",
            "symbol" => "verre"
        ],
        [
            "name" => "bol",
            "symbol" => "bol"
        ],
        [
            "name" => "plaquette",
            "symbol" => "plaquette"
        ],
        [
            "name" => "tablette",
            "symbol" => "tablette"
        ],
        [
            "name" => "tranche",
            "symbol" => "tranche"
        ],
        [
            "name" => "paquet",
            "symbol" => "paquet"
        ],
        [
            "name" => "boîte",
            "symbol" => "boîte"
        ],
        [
            "name" => "sachet",
            "symbol" => "sachet"
        ],
        [
            "name" => "bouteille",
            "symbol" => "bouteille"
        ],
        [
            "name" => "conserve",
            "symbol" => "conserve"
        ],
    ];

    public static function getGroups(): array
    {
        return ['prod', 'test', 'dev'];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::UNITS as $unitData) {
            $unitModel = Unit::create($unitData["name"], $unitData["symbol"]);
            $unitEntity = UnitEntity::fromModel($unitModel);
            $manager->persist($unitEntity);

            $this->addReference($unitEntity->getSlug(), $unitEntity);
        }

        $manager->flush();
    }
}
