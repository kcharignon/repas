<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Application\Command\TickLineOnShoppingList\TickLineOnShoppingListCommand;
use Repas\Repas\Application\Command\UpdateShoppingList\UpdateShoppingListCommand;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow as ShoppingListRowEntity;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Infrastructure\DataFixture\DemoUserFixture;
use Repas\User\Infrastructure\Entity\User as UserEntity;

/**
 * Recettes et listes de courses des comptes de démonstration, créées par les vrais cas d'usage (commandes) : les règles
 * du domaine (unités convertibles, quantités de la liste, statistiques) s'appliquent comme en usage réel. Les données de
 * référence (rayons, unités, ingrédients, types de recette) doivent déjà être en base : `make fixtures-load-dev`. Les comptes reçoivent d'abord leurs
 * ingrédients (l'amorçage d'ING-008, comme à l'activation).
 * Développement seulement (groupe `demo`), idempotente : rien n'est recréé si le compte a déjà des recettes.
 */
class DemoRepasFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * Recettes du compte utilisateur : nom => [type, personnes, [[ingrédient, quantité, unité], …]]. Les ingrédients sont ceux « de base » du
     * catalogue, les seuls qu'un compte reçoit à l'amorçage : un ingrédient « spécifique » n'existerait pas chez le compte de démonstration.
     *
     * @var array<string, array{string, int, list<array{string, float, string}>}>
     */
    private const array USER_RECIPES = [
        'Pâtes carbonara' => ['plat', 4, [['pate', 500, 'gramme'], ['oeuf', 4, 'unite'], ['creme-fraiche-epaisse', 250, 'gramme'], ['lardon', 250, 'gramme'], ['parmesan', 100, 'gramme']]],
        'Fajitas' => ['plat', 4, [['tomate', 2, 'unite'], ['mais', 50, 'gramme'], ['creme-fraiche-epaisse', 100, 'gramme'], ['viande-hachee', 250, 'gramme'], ['emmental-rape', 150, 'gramme'], ['avocat', 2, 'unite']]],
        'Gratin de courgettes' => ['plat', 4, [['courgette', 600, 'gramme'], ['beurre', 30, 'gramme'], ['farine', 30, 'gramme'], ['lait', 500, 'millilitre'], ['sel', 5, 'gramme'], ['poivre', 2, 'gramme'], ['emmental-rape', 100, 'gramme'], ['lardon', 150, 'gramme']]],
        'Omelette au fromage' => ['plat', 4, [['oeuf', 8, 'unite'], ['emmental-rape', 100, 'gramme']]],
        'Chili con carne' => ['plat', 4, [['steack-hache', 400, 'gramme'], ['haricot', 250, 'gramme'], ['oignon', 1, 'unite'], ['beurre', 30, 'gramme']]],
        'Salade caesar' => ['entree', 4, [['poulet', 200, 'gramme'], ['salade-verte', 1, 'unite'], ['parmesan', 50, 'gramme'], ['pain', 1, 'unite']]],
        'Tarte tatin' => ['dessert', 4, [['beurre', 100, 'gramme'], ['pomme', 8, 'unite'], ['pate-feuilletee', 1, 'unite'], ['sucre', 100, 'gramme'], ['sucre-vanille', 2, 'sachet']]],
        'Œufs à la coque' => ['entree', 2, [['oeuf', 4, 'unite'], ['pain', 1, 'unite']]],
    ];

    /** Recettes du compte administrateur. */
    private const array ADMIN_RECIPES = ['Omelette au fromage', 'Tarte tatin'];

    /** Le nom du lancement écrit dans les alias des copies d'ingrédients des comptes de démonstration. */
    private const string SEED_SOURCE = 'fixtures-demo';

    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function getDependencies(): array
    {
        return [DemoUserFixture::class];
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        if ($manager->find(IngredientEntity::class, 'pate') === null) {
            throw new LogicException("Données de référence absentes (rayons, unités, ingrédients…) : lance d'abord `make fixtures-load-dev`.");
        }

        $user = $this->getReference(DemoUserFixture::USER_EMAIL, UserEntity::class);
        $admin = $this->getReference(DemoUserFixture::ADMIN_EMAIL, UserEntity::class);

        // Comme à l'activation d'un compte (ADR 0028, amendement) : chaque compte de démonstration reçoit les ingrédients « de base » du propriétaire
        // du catalogue ; les recettes ci-dessous les désignent par leur slug de catalogue, que l'écriture résout vers la copie du compte (alias)
        foreach ([$user, $admin] as $account) {
            $this->commandBus->dispatch(new SeedUserIngredientsCommand($account->getId(), self::SEED_SOURCE));
        }

        if ($manager->getRepository(RecipeEntity::class)->findOneBy(['authorId' => $user->getId()]) === null) {
            $recipeIds = $this->createRecipes($user->getId(), array_keys(self::USER_RECIPES));
            $this->createUserLists($manager, $user, $recipeIds);
        }

        if ($manager->getRepository(RecipeEntity::class)->findOneBy(['authorId' => $admin->getId()]) === null) {
            $this->createRecipes($admin->getId(), self::ADMIN_RECIPES);
        }
    }

    /**
     * @param list<string> $names
     * @return array<string, string> identifiant de chaque recette créée, par nom
     */
    private function createRecipes(string $authorId, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            [$type, $people, $rows] = self::USER_RECIPES[$name];
            $ids[$name] = UuidGenerator::new();
            $this->commandBus->dispatch(new CreateRecipeCommand(
                id: $ids[$name],
                name: $name,
                serving: $people,
                authorId: $authorId,
                rows: new Tab(
                    array_map(static fn(array $row) => new CreateRecipeRowSubCommand($row[0], $row[2], $row[1]), $rows),
                    CreateRecipeRowSubCommand::class,
                ),
                typeSlug: $type,
            ));
        }

        return $ids;
    }

    /**
     * Une liste « Dîner entre amis » (mise en pause par la création de la suivante) et la liste active de la semaine,
     * avec des repas planifiés et quelques lignes déjà cochées.
     *
     * @param array<string, string> $recipeIds
     */
    private function createUserLists(ObjectManager $manager, UserEntity $user, array $recipeIds): void
    {
        $friends = UuidGenerator::new();
        $this->commandBus->dispatch(new CreateShoppingListCommand($friends, $user->getId()));
        $this->commandBus->dispatch(new UpdateShoppingListCommand($friends, 'Dîner entre amis'));
        foreach (['Pâtes carbonara', 'Tarte tatin'] as $name) {
            $this->commandBus->dispatch(new PlannedMealCommand($user->getId(), $recipeIds[$name], 6));
        }
        $this->tick($manager, $friends, 3);

        $week = UuidGenerator::new();
        $this->commandBus->dispatch(new CreateShoppingListCommand($week, $user->getId()));
        foreach (['Gratin de courgettes', 'Fajitas', 'Omelette au fromage'] as $name) {
            $this->commandBus->dispatch(new PlannedMealCommand($user->getId(), $recipeIds[$name], $user->getDefaultServing()));
        }
        $this->tick($manager, $week, 4);
    }

    /** Coche les premières lignes (au plus la moitié : cocher la dernière terminerait la liste). */
    private function tick(ObjectManager $manager, string $shoppingListId, int $count): void
    {
        $rows = $manager->getRepository(ShoppingListRowEntity::class)->findBy(['shoppingListId' => $shoppingListId], ['id' => 'ASC']);
        foreach (array_slice($rows, 0, min($count, intdiv(count($rows), 2))) as $row) {
            $this->commandBus->dispatch(new TickLineOnShoppingListCommand($row->getId()));
        }
    }
}
