<?php

namespace Repas\Tests\Helper;


use DateTimeImmutable;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\User;

/**
 * Mesure le nombre de requêtes SQL d'un use case d'écriture, de bout en bout : bus de commandes, handler, repositories
 * et listeners d'événements réels, sur la vraie base de test (voir ADR 0010 et roadmap, phase 8).
 *
 * La mesure part d'un état « requête HTTP neuve » : les données sont créées par le scénario, puis l'identity map de
 * Doctrine est vidée avant de compter.
 */
abstract class WriteBudgetTestCase extends DatabaseTestCase
{
    public DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister = new DataPersister(static::getContainer());
    }

    /**
     * @param callable(self): object $arrange crée les données du scénario et renvoie la commande à exécuter
     * @return array{int, array<string, int>} nombre de requêtes, et leur répartition par type et par table
     */
    public function measure(callable $arrange): array
    {
        $container = static::getContainer();

        $command = $arrange($this);

        $this->entityManager->clear();
        $counter = $container->get(QueryCounter::class);
        $counter->reset();

        $container->get(CommandBusInterface::class)->dispatch($command);

        return [$counter->count(), $counter->byTable()];
    }

    public function savedUser(UserBuilder $builder = new UserBuilder()): User
    {
        $user = $builder->build();
        $this->persister->saveUser($user);

        return $user;
    }

    /**
     * Lien de confirmation d'adresse déjà envoyé à `$user` (émis `$minutesAgo` minutes avant maintenant).
     */
    public function savedEmailVerificationToken(User $user, string $secret, int $minutesAgo = 0): void
    {
        static::getContainer()->get(EmailVerificationTokenRepository::class)->add(
            EmailVerificationToken::issue(UuidGenerator::new(), $user->getId(), EmailVerificationToken::hash($secret), new DateTimeImmutable("-{$minutesAgo} minutes")),
        );
    }

    public function savedRecipe(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * Liste de courses enregistrée avec un repas par recette et les lignes de courses correspondantes (comme après
     * `PlannedMeal`, sans conversion d'unité) ; `$configure` peut régler le builder (statut, nom…).
     *
     * @param list<Recipe> $recipes
     * @param (callable(ShoppingListBuilder): ShoppingListBuilder)|null $configure
     * @param (callable(ShoppingList): void)|null $beforeSave modifie la liste construite avant son enregistrement
     */
    public function savedShoppingList(User $owner, array $recipes, ?callable $configure = null, ?callable $beforeSave = null): ShoppingList
    {
        $builder = new ShoppingListBuilder()->withOwner($owner);
        foreach ($recipes as $recipe) {
            $builder->addRecipe($recipe);
        }
        if ($configure !== null) {
            $builder = $configure($builder);
        }
        $shoppingList = $builder->build();
        foreach ($recipes as $recipe) {
            foreach ($recipe->getRows() as $row) {
                $shoppingList->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $row->getQuantity());
            }
        }
        if ($beforeSave !== null) {
            $beforeSave($shoppingList);
        }
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);

        return $shoppingList;
    }

    /**
     * Les trois recettes d'une liste « typique » : carbonara, œufs à la coque et gâteau basque, avec les conversions
     * d'unité (unité d'achat <-> unité de la recette) nécessaires pour planifier leurs repas.
     *
     * @return list<Recipe>
     */
    public function typicalRecipes(User $author): array
    {
        $recipes = [
            $this->savedRecipe($author, new RecipeBuilder()->isPastaCarbonara()),
            $this->savedRecipe($author, new RecipeBuilder()->isSoftBoiledEggs()),
            $this->savedRecipe($author, new RecipeBuilder()->isBasqueCake()),
        ];
        $this->savedConversionsFor($recipes);

        return $recipes;
    }

    /**
     * @param list<Recipe> $recipes
     */
    public function savedConversionsFor(array $recipes): void
    {
        $saved = [];
        foreach ($recipes as $recipe) {
            foreach ($recipe->getRows() as $row) {
                $ingredient = $row->getIngredient();
                $purchaseUnit = $ingredient->getDefaultPurchaseUnit();
                $key = $ingredient->getSlug() . '|' . $row->getUnit()->getSlug();
                if ($row->getUnit()->isEqual($purchaseUnit) || isset($saved[$key])) {
                    continue;
                }
                $saved[$key] = true;
                $this->persister->saveConversion(
                    new ConversionBuilder()
                        ->withIngredient($ingredient)
                        ->withStartUnit($purchaseUnit)
                        ->withEndUnit($row->getUnit())
                        ->withCoefficient(100.0)
                        ->build()
                );
            }
        }
    }
}
