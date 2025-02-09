<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\RecipeRow as RecipeRowEntity;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Infrastructure\DataFixture\UserFixture;
use Repas\User\Infrastructure\Entity\User as UserEntity;

class RecipeFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const array RECIPES = [
        [
            "name" => "pates carbonara",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate",
                    "quantity" => 500,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 1,
                    "unit" => "piece"
                ],
                [
                    "slug" => "creme-fraiche-epaisse",
                    "quantity" => 250,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 250,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "parmesan",
                    "quantity" => 100,
                    "unit" => "gramme"
                ],

            ]
        ],
        [
            "name" => "fajitas",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "tomate",
                    "quantity" => 2,
                    "unit" => "piece"
                ],
                [
                    "slug" => "mais",
                    "quantity" => 50,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "creme-fraiche-epaisse",
                    "quantity" => 100,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "viande-hachee",
                    "quantity" => 250,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 150,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "avocat",
                    "quantity" => 2,
                    "unit" => "piece"
                ],
                [
                    "slug" => "ail",
                    "quantity" => 30,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "epice-guacamole",
                    "quantity" => 1,
                    "unit" => "sachet"
                ],
                [
                    "slug" => "epice-viande",
                    "quantity" => 1,
                    "unit" => "sachet"
                ],
                [
                    "slug" => "tortilla",
                    "quantity" => 8,
                    "unit" => "piece"
                ],
            ],
        ],
        [
            "name" => "œufs à la coque",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 2,
            "ingredients" => [
                [
                    "slug" => "oeuf",
                    "quantity" => 4,
                    "unit" => "piece"
                ],
                [
                    "slug" => "pain",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
            ]
        ],
        [
            "name" => "burger",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 2,
            "ingredients" => [
                [
                    "slug" => "pain-a-burger",
                    "quantity" => 2,
                    "unit" => "piece"
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "steack-hache",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "oignon-rouge",
                    "quantity" => 1,
                    "unit" => "piece"
                ],
                [
                    "slug" => "cheddar-en-tranche",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "bearnaise",
                    "quantity" => 10,
                    "unit" => "millilitre"
                ],
                [
                    "slug" => "ketchup",
                    "quantity" => 10,
                    "unit" => "millilitre"
                ],
                [
                    "slug" => "mayonnaise",
                    "quantity" => 10,
                    "unit" => "millilitre"
                ],
                [
                    "slug" => "salade-verte",
                    "quantity" => 100,
                    "unit" => "gramme"
                ],
            ],
        ],
        [
            "name" => "tarte tatin",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "beurre",
                    "quantity" => 100,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "pomme-golden",
                    "quantity" => 8,
                    "unit" => "piece"
                ],
                [
                    "slug" => "pate-feuilletee",
                    "quantity" => 1,
                    "unit" => "piece"
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 100,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "sucre-vanille",
                    "quantity" => 2,
                    "unit" => "sachet"
                ],
            ],
        ],
        [
            "name" => "ramen",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 2,
            "ingredients" => [
                [
                    "slug" => "boeuf",
                    "quantity" => 300,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "pate-ramen",
                    "quantity" => 200,
                    "unit" => "gramme"
                ],
            ],
        ],
        [
            "name" => "salade caesar",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "aiguilette-de-poulet",
                    "quantity" => 200,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "salade-verte",
                    "quantity" => 1,
                    "unit" => "piece"
                ],
                [
                    "slug" => "parmesan",
                    "quantity" => 50,
                    "unit" => "gramme"
                ],
                [
                    "slug" => "pain",
                    "quantity" => 1,
                    "unit" => "piece"
                ],
            ],
        ],
        [
            "name" => "gaspacho",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 2,
            "ingredients" => [
                [
                    "slug" => "tomate",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "poivron",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "concombre",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "melon",
                    "quantity" => 0.5,
                    "unit" => "piece",
                ],
                [
                    "slug" => "huile-d-olive",
                    "quantity" => 2,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "vinaigre",
                    "quantity" => 1,
                    "unit" => "cuillere-a-soupe",
                ],
            ],
        ],
        [
            "name" => "poulet thai",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 2,
            "ingredients" => [
                [
                    "slug" => "aiguilette-de-poulet",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "poivron",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "cacahuete",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sauce-soja",
                    "quantity" => 2,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "huile",
                    "quantity" => 1,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 1,
                    "unit" => "cuillere-a-cafe",
                ],
                [
                    "slug" => "sauce-soja",
                    "quantity" => 3,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "sauce-nuoc-man",
                    "quantity" => 2,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "caramel-liquide",
                    "quantity" => 1,
                    "unit" => "cuillere-a-soupe",
                ],
            ],
        ],
        [
            "name" => "gnocci",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "gnocci",
                    "quantity" => 1,
                    "unit" => "sachet",
                ],
            ],
        ],
        [
            "name" => "salade composee",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "riz",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "concombre",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "mais",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "raviole",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "raviole",
                    "quantity" => 1,
                    "unit" => "sachet",
                ],
            ],
        ],
        [
            "name" => "sauce lentille",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "saucisse",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "lentille",
                    "quantity" => 250,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "carotte",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "ble dinde",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "ble",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "dinde",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "potage",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "vermicelle",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poireau",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "bouillon",
                    "quantity" => 1,
                    "unit" => "cube",
                ],
            ],
        ],
        [
            "name" => "gratin de pate",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate",
                    "quantity" => 250,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "gratin de choux fleur",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "choux-fleur",
                    "quantity" => 500,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poivre",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "gratin courgette",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "courgette",
                    "quantity" => 600,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poivre",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "lasagne",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate-lasagne",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "viande-hachee",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "parmesan",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poivre",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sauce-tomate",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "quenelle",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "quenelle",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poivre",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sauce-tomate",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "olive",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "crumble de courgette",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "courgette",
                    "quantity" => 600,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poivre",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "pizza",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate-pizza",
                    "quantity" => 3,
                    "unit" => "piece",
                ],
                [
                    "slug" => "sauce-tomate",
                    "quantity" => 200,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "charcuterie",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "mozzarella",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "pizza big apple",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate-pizza",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "pomme",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "raclette",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sauce-napolitaine",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 250,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "baggle",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pain-baggle",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "fromage-frais",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "charcuterie",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "salade-verte",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "woke",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "nouille-chinoise",
                    "quantity" => 250,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "boeuf",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "soja",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "concombre",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "avocat",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "nem",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "riz cantonais",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "riz",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "petit-pois",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 3,
                    "unit" => "piece",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "tomate farcie",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "tomate-a-farcir",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "courgette-a-farcir",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "poivron",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "chair-a-saucisse",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "pain-de-mie",
                    "quantity" => 2,
                    "unit" => "tranche",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "huile-d-olive",
                    "quantity" => 3,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "ail",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "riz",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "bouillon-de-boeuf",
                    "quantity" => 1,
                    "unit" => "cube",
                ],
            ],
        ],
        [
            "name" => "omelette",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "oeuf",
                    "quantity" => 8,
                    "unit" => "piece",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "œuf dur",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "oeuf",
                    "quantity" => 8,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "chili con carne",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "steack-hache",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "haricot-rouge",
                    "quantity" => 250,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 30,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "bouillon-de-boeuf",
                    "quantity" => 1,
                    "unit" => "cube",
                ],
                [
                    "slug" => "concentre-de-tomate",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "ail",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "epice",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "riz",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "panini",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pain-panini",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "cheddar-en-tranche",
                    "quantity" => 4,
                    "unit" => "tranche",
                ],
                [
                    "slug" => "charcuterie",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "ketchup",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "bearnaise",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "tartine",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pain-tartine",
                    "quantity" => 4,
                    "unit" => "tranche",
                ],
                [
                    "slug" => "ail",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "charcuterie",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "mozzarella",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "crepe",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "farine",
                    "quantity" => 250,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "galette bretonne",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "galette",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "charcuterie",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "croque monsieur",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pain-de-mie",
                    "quantity" => 8,
                    "unit" => "tranche",
                ],
                [
                    "slug" => "charcuterie",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "cheddar-en-tranche",
                    "quantity" => 4,
                    "unit" => "tranche",
                ],
            ],
        ],
        [
            "name" => "brique",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "feuille-de-brique",
                    "quantity" => 8,
                    "unit" => "piece",
                ],
                [
                    "slug" => "jambon-cru",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "huile-d-olive",
                    "quantity" => 3,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "chevre",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "miel",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "raclette",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "raclette",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "charcuterie",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "pomme-de-terre",
                    "quantity" => 800,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "cornichon",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "fondue",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "fromage-fondue",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "vin-blanc-cuisine",
                    "quantity" => 200,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "pain",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "soupe",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "soupe",
                    "quantity" => 1,
                    "unit" => "litre",
                ],
            ],
        ],
        [
            "name" => "endive au jambon",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "endive",
                    "quantity" => 8,
                    "unit" => "piece",
                ],
                [
                    "slug" => "jambon-blanc",
                    "quantity" => 8,
                    "unit" => "tranche",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poivre",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "wrap",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "tortilla",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "poulet",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "cheddar-en-tranche",
                    "quantity" => 4,
                    "unit" => "tranche",
                ],
                [
                    "slug" => "salade",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "tartare",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "viande-hachee",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "echalote",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "capre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "cornichon",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "terrine aubergine",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "riste-aubergine",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poivre",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "creme-fraiche-epaisse",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "taboulet syrien",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "boulgoure-fin",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "salade-verte",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "huile-d-olive",
                    "quantity" => 3,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "citron",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "persil",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "poulet basquaise",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "poulet",
                    "quantity" => 600,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "poivron",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "ail",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "huile-d-olive",
                    "quantity" => 3,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "bouquet-garni",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "tarte mozza",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate-feuilletee",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "mozzarella",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 3,
                    "unit" => "piece",
                ],
                [
                    "slug" => "oignon-rouge",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "moussaka",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "aubergine",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "courgette",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "ail",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "concentre-de-tomate",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "huile-d-olive",
                    "quantity" => 3,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "viande-hachee",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 5,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poivre",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "emmental-rape",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "tourte épinard",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate-feuilletee",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "epinard",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "creme-fraiche-liquide",
                    "quantity" => 200,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "saumon",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "tartiflette",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pomme-de-terre",
                    "quantity" => 800,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "reblochon",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "creme-fraiche-epaisse",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "flamenkuche",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate-feuilletee",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "creme-fraiche-epaisse",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "blanquette de veau",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "bouillon-de-legume",
                    "quantity" => 1,
                    "unit" => "cube",
                ],
                [
                    "slug" => "carotte",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "champignon",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "citron",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "blanquette-de-veau",
                    "quantity" => 600,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "creme-fraiche-epaisse",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "vin-blanc-cuisine",
                    "quantity" => 200,
                    "unit" => "millilitre",
                ],
            ],
        ],
        [
            "name" => "bœuf bourguignon",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "boeuf-bourguignon",
                    "quantity" => 600,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oignon",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "champignon",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "ail",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "bouquet-garni",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "vin-rouge-cuisine",
                    "quantity" => 500,
                    "unit" => "millilitre",
                ],
            ],
        ],
        [
            "name" => "poulet indien",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "poulet",
                    "quantity" => 600,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "riz",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "curry",
                    "quantity" => 10,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "huile-d-olive",
                    "quantity" => 3,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "concombre",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "carotte",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "cacahuete",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "vinaigre-balsamique",
                    "quantity" => 2,
                    "unit" => "cuillere-a-soupe",
                ],
            ],
        ],
        [
            "name" => "pate bolognaise",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "bolognaise",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "pate pesto",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "pesto",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "saucisse purée",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pomme-de-terre",
                    "quantity" => 800,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "saucisse",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 200,
                    "unit" => "millilitre",
                ],
            ],
        ],
        [
            "name" => "petit pois poisson",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "petit-pois-surgele",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "poisson-pane",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "carotte",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "pomme-de-terre",
                    "quantity" => 500,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "bouillon",
                    "quantity" => 1,
                    "unit" => "cube",
                ],
                [
                    "slug" => "lardon",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "nuggets fritte",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "nuggets",
                    "quantity" => 12,
                    "unit" => "piece",
                ],
                [
                    "slug" => "frite",
                    "quantity" => 600,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "légume cordon bleu",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "haricot",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "pomme-de-terre",
                    "quantity" => 500,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "cordon-bleu",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "risotto",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "risotto",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "chorizo",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "vin-blanc-cuisine",
                    "quantity" => 200,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "parmesan",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "saumon papillotte",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "plat",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "saumon",
                    "quantity" => 4,
                    "unit" => "tranche",
                ],
                [
                    "slug" => "poivron",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "tomate",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "huile-d-olive",
                    "quantity" => 3,
                    "unit" => "cuillere-a-soupe",
                ],
                [
                    "slug" => "citron",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "riz",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "haricot",
                    "quantity" => 400,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "tarte au pomme",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "pate-sablee",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "pomme",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "compote",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "charlotte framboise",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "biscuit-cuillere",
                    "quantity" => 20,
                    "unit" => "piece",
                ],
                [
                    "slug" => "framboise-surgelee",
                    "quantity" => 300,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sirop-fruit-rouge",
                    "quantity" => 100,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "citron",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "mascarpone",
                    "quantity" => 250,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "creme-fraiche-epaisse",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "feuille-gelatine",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "moelleux chocolat",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "sucre",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "chocolat",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 4,
                    "unit" => "piece",
                ],
                [
                    "slug" => "levure-patissiere",
                    "quantity" => 1,
                    "unit" => "sachet",
                ],
            ],
        ],
        [
            "name" => "gateau yaourt",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "farine",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 3,
                    "unit" => "piece",
                ],
                [
                    "slug" => "yaourt-nature",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "citron",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "huile",
                    "quantity" => 100,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "levure-patissiere",
                    "quantity" => 1,
                    "unit" => "sachet",
                ],
            ],
        ],
        [
            "name" => "cookies",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "farine",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "maizena",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "vanille",
                    "quantity" => 1,
                    "unit" => "gousse",
                ],
                [
                    "slug" => "pepite-de-chocolat",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "muffin pépitte chocolat",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "farine",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 100,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "vanille",
                    "quantity" => 1,
                    "unit" => "gousse",
                ],
                [
                    "slug" => "pepite-de-chocolat",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "nutella",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "bananabread",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "farine",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "levure-patissiere",
                    "quantity" => 1,
                    "unit" => "sachet",
                ],
                [
                    "slug" => "bicarbonate",
                    "quantity" => 1,
                    "unit" => "cuillere-a-cafe",
                ],
                [
                    "slug" => "sel",
                    "quantity" => 2,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "banane",
                    "quantity" => 3,
                    "unit" => "piece",
                ],
                [
                    "slug" => "lait",
                    "quantity" => 50,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "beurre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
            ],
        ],
        [
            "name" => "sablé",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "beurre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 1,
                    "unit" => "piece",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "marbré",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "beurre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 3,
                    "unit" => "piece",
                ],
                [
                    "slug" => "sucre",
                    "quantity" => 150,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "creme-fraiche-liquide",
                    "quantity" => 100,
                    "unit" => "millilitre",
                ],
                [
                    "slug" => "farine",
                    "quantity" => 200,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "levure-patissiere",
                    "quantity" => 1,
                    "unit" => "sachet",
                ],
                [
                    "slug" => "vanille",
                    "quantity" => 1,
                    "unit" => "gousse",
                ],
                [
                    "slug" => "chocolat-en-poudre",
                    "quantity" => 50,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "tiramisu citron",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "dessert",
            "people" => 4,
            "ingredients" => [
                [
                    "slug" => "sucre",
                    "quantity" => 100,
                    "unit" => "gramme",
                ],
                [
                    "slug" => "citron",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "biscuit-cuillere",
                    "quantity" => 20,
                    "unit" => "piece",
                ],
                [
                    "slug" => "oeuf",
                    "quantity" => 3,
                    "unit" => "piece",
                ],
                [
                    "slug" => "mascarpone",
                    "quantity" => 250,
                    "unit" => "gramme",
                ],
            ],
        ],
        [
            "name" => "guacamole",
            "owner" => "alexiane.sichi@gmail.com",
            "type" => "entree",
            "people" => 2,
            "ingredients" => [
                [
                    "slug" => "avocat",
                    "quantity" => 2,
                    "unit" => "piece",
                ],
                [
                    "slug" => "epice-guacamole",
                    "quantity" => 0.5,
                    "unit" => "sachet",
                ],
            ],
        ],
    ];
    private static array $recipesIds = [];

    public function getDependencies(): array
    {
        return [
            UserFixture::class,
            RecipeTypeFixture::class,
            IngredientFixture::class,
            UnitFixture::class,
        ];
    }

    public static function getGroups(): array
    {
        return ['prod', 'test', 'dev'];
    }

    public function load(ObjectManager $manager): void
    {
        try {
            foreach (self::RECIPES as $recipeDatas) {

                $authorEntity = $this->getReference($recipeDatas['owner'], UserEntity::class);

                $recipeEntity = new RecipeEntity(
                    id: UuidGenerator::new(),
                    name: $recipeDatas['name'],
                    serving: $recipeDatas['people'],
                    authorId: $authorEntity->getId(),
                    typeSlug: $recipeDatas['type'],
                );
                $manager->persist($recipeEntity);

                foreach ($recipeDatas['ingredients'] as $recipeRowData) {
                    $recipeRowEntity = new RecipeRowEntity(
                        id: UuidGenerator::new(),
                        ingredientSlug: $recipeRowData['slug'],
                        quantity: $recipeRowData['quantity'],
                        unitSlug: $recipeRowData['unit'],
                        recipeId: $recipeEntity->getId(),
                    );
                    $manager->persist($recipeRowEntity);
                }

                self::$recipesIds[$authorEntity->getId()] ??= [];
                self::$recipesIds[$authorEntity->getId()][] = $recipeEntity->getId();
                $manager->persist($recipeEntity);
                $this->addReference($recipeEntity->getId(), $recipeEntity);

            }

            $manager->flush();
        } catch (Exception $e) {
            dump(sprintf("Failed to create Recipe: %s", $recipeDatas["name"] ?? 'Unknown'));
            throw $e;
        }
    }

    public static function getRecipesIds(string $ownerId): array
    {
        return self::$recipesIds[$ownerId] ?? [];
    }
}
