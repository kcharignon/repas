<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Infrastructure\Entity\Department as DepartmentEntity;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;

class IngredientFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const array INGREDIENTS = [
        [
            "name" => "aubergine",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "ail",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "avocat",
            "image" => "https://cdn-icons-png.flaticon.com/64/135/135609.png",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "bettrave",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "brocoli",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "banane",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "épinard",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "sachet",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "carotte",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "ananas",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "chou",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "courge",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "échalotte",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "concombre",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "champignon",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "haricot rouge",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "endive",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "haricot frais",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "salade",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "salade verte",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "poivron",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "pomme de terre",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "pomme",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "pomme golden",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "betternut",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "choux fleur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "melon",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "poireau",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "citron",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "persil",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "oignon",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "oignon rouge",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "oignon blanc",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "petit pois",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "conserve",
            "department" => "conserve",
        ],
        [
            "name" => "tomate cerise",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "tomate à farcir",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "courgette à farcir",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "tomate",
            "image" => "https://cdn-icons-png.flaticon.com/64/135/135702.png",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "courgette",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
            "department" => "fruit-et-legume",
        ],
        [
            "name" => "emmental rapé",
            "department" => "fromage",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "sachet",
        ],
        [
            "name" => "comté",
            "department" => "fromage",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "piece",
        ],
        [
            "name" => "parmesan",
            "department" => "fromage",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "piece",
        ],
        [
            "name" => "vache qui rit",
            "department" => "fromage",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "kiri",
            "department" => "fromage",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "raclette",
            "department" => "fromage",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "fromage fondue",
            "department" => "fromage",
            "defaultCookingUnit" => "sachet",
            "defaultPurchaseUnit" => "sachet",
        ],
        [
            "name" => "mascarpone",
            "department" => "fromage",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "reblochon",
            "department" => "fromage",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
        ],
        [
            "name" => "chèvre",
            "department" => "fromage",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "piece",
        ],
        [
            "name" => "fromage frais",
            "department" => "fromage",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "mozzarella",
            "department" => "fromage",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "fromage à croc monssieur",
            "department" => "fromage",
            "defaultCookingUnit" => "tranche",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "cheddar en tranche",
            "department" => "fromage",
            "defaultCookingUnit" => "tranche",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "crème fraiche épaisse",
            "department" => "fromage",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "crème fraiche liquide",
            "department" => "fromage",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "bouteille",
        ],
        [
            "name" => "divers",
            "department" => "fromage",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "beurre",
            "department" => "fromage",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "plaquette",
        ],
        [
            "name" => "perle de lait",
            "department" => "yaourt",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "pat patrouille",
            "department" => "yaourt",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "la laitière",
            "department" => "yaourt",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "velouté",
            "department" => "yaourt",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "yaourt nature",
            "department" => "yaourt",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "compote en pot",
            "department" => "yaourt",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "compote à boire",
            "department" => "yaourt",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "compote",
            "department" => "yaourt",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "figolu",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "bn",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "chamonix",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "barquette chocolat",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "barquette fraise",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "the lu",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "boudoire",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "biscuit cuillère",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "speculos",
            "department" => "gateau",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "lait 1er âge",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
            "department" => "bebe",
        ],
        [
            "name" => "lait 2e âge",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
            "department" => "bebe",
        ],
        [
            "name" => "lait 3e âge",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
            "department" => "bebe",
        ],
        [
            "name" => "couche",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "bebe",
        ],
        [
            "name" => "lingettes pour bébé",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "bebe",
        ],
        [
            "name" => "chips",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "aperitif",
        ],
        [
            "name" => "cacahuète",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "aperitif",
        ],
        [
            "name" => "chips ondulé",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "aperitif",
        ],
        [
            "name" => "chips aux vinaigre",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "aperitif",
        ],
        [
            "name" => "chipster",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "aperitif",
        ],
        [
            "name" => "mini pizza",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "aperitif",
        ],
        [
            "name" => "cone 3d",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "aperitif",
        ],
        [
            "name" => "springle",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
            "department" => "aperitif",
        ],
        [
            "name" => "aiguilette de poulet",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "cuisse de poulet",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "steack",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "dinde",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "poulet",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "saumon",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "poisson",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "knacki",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "chorizo",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "jambon cru",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "jambon blanc",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "bœuf",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "bœuf bourguignon",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "jambon",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "blanquette de veau",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "chair à saucisse",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "cote de porc",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "agneau",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "veau",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "saucisse",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "saucisson",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "saucisson fouet",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "saucisson st agaune",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "merguez",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "charcuterie",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
            "department" => "viande",
        ],
        [
            "name" => "lardon",
            "department" => "viande",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "bière blonde",
            "department" => "alcool",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "bière artisinale",
            "department" => "alcool",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "vin blanc cuisine",
            "department" => "alcool",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "vin blanc table",
            "department" => "alcool",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "vin rouge cuisine",
            "department" => "alcool",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "vin rouge table",
            "department" => "alcool",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "schweps tonic",
            "department" => "boisson",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "sirop fruit rouge",
            "department" => "boisson",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "ice tea green",
            "department" => "boisson",
            "defaultCookingUnit" => "millilitre",
            "defaultPurchaseUnit" => "millilitre",
        ],
        [
            "name" => "steack haché",
            "department" => "surgele",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "viande hachée",
            "department" => "surgele",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "petit pois surgelé",
            "department" => "surgele",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "épinard surgelé",
            "department" => "surgele",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "framboise surgelée",
            "department" => "surgele",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "nuggets",
            "department" => "surgele",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "cordon bleu",
            "department" => "surgele",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "frite",
            "department" => "surgele",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "maïs",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "petit pois carotte",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "haricot",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "olive",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "capre",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "olive verte",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "concentré de tomate",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "olive noir",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "gratin daufinois",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "confiture",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "nutella",
            "image" => "https://cdn-icons-png.flaticon.com/64/135/135605.png",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "salade de fruit",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "compote en bocal",
            "department" => "conserve",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "farine",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "farine pain",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "farine brioche",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "sucre",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "maïzena",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "pépite de chocolat",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "sucre roux",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "sucre vanillé",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "sachet",
            "defaultPurchaseUnit" => "sachet",
        ],
        [
            "name" => "levure patissière",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "levure boulangère",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "vanille",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "chocolat",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "chocolat liquide",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "caramel",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "gélatine",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "agaragar",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "caramel liquide",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "riz",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "blé",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "boulgour",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "quinoa",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "lentille",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "risotto",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "boulgoure fin",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "nouille",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "pate",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "pate lasagne",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "pate ramen",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "coquilette",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "spaguetti",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "nouille chinoise",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "soja",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "raviole",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "purée",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "vermicelle",
            "department" => "cereale",
            "defaultCookingUnit" => "gramme",
            "defaultPurchaseUnit" => "gramme",
        ],
        [
            "name" => "pizza",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "soupe",
            "department" => "traiteur",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "galette",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
        ],
        [
            "name" => "pate pizza",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "pate feuilletée",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "riste aubergine",
            "department" => "traiteur",
            "defaultCookingUnit" => "boite",
            "defaultPurchaseUnit" => "boite",
        ],
        [
            "name" => "pate brisée",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "pate sablée",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "gnocci",
            "department" => "traiteur",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "quenelle",
            "department" => "traiteur",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "hachis parmentier",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "lasagne",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "poisson pané",
            "department" => "traiteur",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "nem",
            "department" => "traiteur",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "bouillon",
            "department" => "traiteur",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "bouillon de bœuf",
            "department" => "traiteur",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "bouillon de légume",
            "department" => "traiteur",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "sol",
            "department" => "produit-menager",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "javel",
            "department" => "produit-menager",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "vitre",
            "department" => "produit-menager",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "bicarbonate",
            "department" => "produit-menager",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "lingette ménagère",
            "department" => "produit-menager",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "pain",
            "department" => "boulangerie",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "pain de mie",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "pain panini",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "pain tartine",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "pain baggle",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "pain à burger",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "brioche",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "biscotte",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "cracotte",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "pain au lait",
            "department" => "boulangerie",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "vinaigre",
            "department" => "sauce",
            "defaultCookingUnit" => "cuillere-a-soupe",
            "defaultPurchaseUnit" => "cuillere-a-soupe",
        ],
        [
            "name" => "vinaigre balsamique",
            "department" => "sauce",
            "defaultCookingUnit" => "cuillere-a-soupe",
            "defaultPurchaseUnit" => "bouteille",
        ],
        [
            "name" => "vinaigre xérès",
            "department" => "sauce",
            "defaultCookingUnit" => "cuillere-a-soupe",
            "defaultPurchaseUnit" => "bouteille",
        ],
        [
            "name" => "huile",
            "department" => "sauce",
            "defaultCookingUnit" => "cuillere-a-soupe",
            "defaultPurchaseUnit" => "bouteille",
        ],
        [
            "name" => "huile d'olive",
            "department" => "sauce",
            "defaultCookingUnit" => "cuillere-a-soupe",
            "defaultPurchaseUnit" => "bouteille",
        ],
        [
            "name" => "béarnaise",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "ketchup",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "mayonnaise",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "bolognaise",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "sauce 4 fromages",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "pesto",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "tartare",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "bbq",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "sauce soja",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "sauce napolitaine",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "sauce tomate",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "sauce nuoc man",
            "department" => "sauce",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "croquette",
            "department" => "animal",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "litière",
            "department" => "animal",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "œuf",
            "department" => "divers",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "lait",
            "department" => "divers",
            "defaultCookingUnit" => "litre",
            "defaultPurchaseUnit" => "litre",
        ],
        [
            "name" => "poivre",
            "department" => "divers",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "cornichon",
            "department" => "divers",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "chocolat en poudre",
            "department" => "divers",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "miel",
            "department" => "divers",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "sel",
            "department" => "divers",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "bouquet garni",
            "department" => "divers",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "tortilla",
            "department" => "cuisine-du-monde",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "feuille de brique",
            "department" => "cuisine-du-monde",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "épice guacamole",
            "department" => "cuisine-du-monde",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
        ],
        [
            "name" => "épice viande",
            "department" => "cuisine-du-monde",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "épice",
            "department" => "cuisine-du-monde",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "curry",
            "department" => "cuisine-du-monde",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",

        ],
        [
            "name" => "ramen",
            "department" => "cuisine-du-monde",
            "defaultCookingUnit" => "paquet",
            "defaultPurchaseUnit" => "paquet",
        ],
        [
            "name" => "feuille-gelatine",
            "department" => "aide-patissier",
            "defaultCookingUnit" => "piece",
            "defaultPurchaseUnit" => "piece",
        ],
    ];

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

    public function load(ObjectManager $manager): void
    {
        foreach (self::INGREDIENTS as $ingredientData) {
            $defaultCookingUnit = $this->getReference($ingredientData['defaultCookingUnit'], UnitEntity::class);
            $defaultPurchaseUnit = $this->getReference($ingredientData['defaultPurchaseUnit'], UnitEntity::class);
            $department = $this->getReference($ingredientData['department'], DepartmentEntity::class);

            $ingredientModel = Ingredient::create(
                $ingredientData['name'],
                $ingredientData['image'] ?? '',
                $department->getModel(),
                $defaultCookingUnit->getModel(),
                $defaultPurchaseUnit->getModel(),
            );

            // On modifie manuellement pour avoir un entite manager par doctrine
            $ingredientEntity = IngredientEntity::fromModel($ingredientModel);
            $ingredientEntity->setDefaultCookingUnit($defaultCookingUnit);
            $ingredientEntity->setDefaultPurchaseUnit($defaultPurchaseUnit);
            $ingredientEntity->setDepartment($department);
            $manager->persist($ingredientEntity);

            $this->addReference($ingredientEntity->getSlug(), $ingredientEntity);
        }

        $manager->flush();
    }
}
