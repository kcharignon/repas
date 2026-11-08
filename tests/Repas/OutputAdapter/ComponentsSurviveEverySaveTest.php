<?php

namespace Repas\Tests\Repas\OutputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\ChangeRecipeFavorite\ChangeRecipeFavoriteCommand;
use Repas\Repas\Application\Command\RemoveRecipeType\RemoveRecipeTypeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * LE garde-fou des recettes utilisées (ADR 0040 décision 25) : enregistrer une recette n'efface jamais ses composants, quel que soit le cas d'usage qui
 * la charge puis l'enregistre. Chaque cas d'usage qui écrit une recette est rejoué, avec les vrais bus et la vraie base, sur une recette qui en
 * utilise une autre ; ses arêtes (mêmes identifiants, mêmes multiplicateurs, mêmes positions) et son `component_count` sont les mêmes après.
 *
 * COMP-002 y ajoute le `PUT` d'un ancien client (`components` absent ou `null`, avec ou sans `If-Match`), le changement de type, de nom et de choix du secret :
 * une écriture qui n'envoie PAS `components` ne touche jamais aux composants. Celles qui les envoient (`[]` retire tout, une liste remplace) sont éprouvées
 * dans `RecipeComponentsApiFlowTest`. Une nouvelle écriture d'une recette est un nouveau cas ci-dessous.
 */
class ComponentsSurviveEverySaveTest extends DatabaseTestCase
{
    private DataPersister $persister;
    private User $author;
    private Recipe $pie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->author = new UserBuilder()->build();
        $this->persister->saveUser($this->author);

        // Une tarte (deux lignes) qui utilise la pâte (deux fois) et la crème (une demi-fois)
        $dough = $this->saved($this->recipe('Pâte', 'zzz farine'));
        $cream = $this->saved($this->recipe('Crème', 'zzz lait'));
        $this->pie = $this->saved($this->recipe('Tarte', 'zzz sucre')->withComponent($dough, 2.0)->withComponent($cream, 0.5));
    }

    /**
     * @return iterable<string, array{string}> le nom d'un cas, rejoué par `command()`
     */
    public static function writes(): iterable
    {
        yield 'mettre la recette en favoris' => ['favorite'];
        yield 'la retirer des favoris' => ['unfavorite'];
        yield 'modifier la recette (nom, personnes, lignes) sans toucher aux composants' => ['update'];
        yield "le `PUT` d'un ancien client : `components` absent, avec `If-Match`" => ['old-client'];
        yield '`components: null` explicite (comme absent)' => ['null-components'];
        yield 'changer le type de la recette' => ['type-changed'];
        yield 'renvoyer le choix « secret » actuel (faux)' => ['same-secret'];
        yield 'la rendre secrète (elle les utilise toujours)' => ['secret'];
        yield 'supprimer le type de la recette (les recettes passent dans un autre type)' => ['type-removed'];
    }

    #[DataProvider('writes')]
    public function testTheComponentsAreIntactAfterTheWrite(string $write): void
    {
        // Given
        $before = $this->state();
        $this->assertCount(2, $before['edges']);

        // When
        static::getContainer()->get(CommandBusInterface::class)->dispatch($this->command($write));

        // Then : les mêmes arêtes (mêmes identifiants, multiplicateurs et positions) et le même compte
        $this->assertSame($before, $this->state());
        // et la recette les relit comme avant
        $this->entityManager->clear();
        $this->assertSame(array_column($before['edges'], 'component'), static::getContainer()->get(RecipeRepository::class)->findOneById($this->pie->getId())->componentIds());
    }

    public function testTheWriteCasesAreAllRunByTheTest(): void
    {
        // Given : un cas de plus dans `writes()` sans sa commande dans `command()` serait une erreur de ce test, pas un oubli silencieux
        foreach (self::writes() as [$write]) {
            $this->command($write);
            $this->addToAssertionCount(1);
        }
    }

    private function command(string $write): object
    {
        return match ($write) {
            'favorite' => new ChangeRecipeFavoriteCommand($this->pie->getId(), $this->author->getId(), true),
            'unfavorite' => new ChangeRecipeFavoriteCommand($this->pie->getId(), $this->author->getId(), false),
            'update' => new UpdateRecipeCommand(
                $this->pie->getId(),
                'Tarte renommée',
                8,
                Tab::fromArray(new UpdateRecipeRowSubCommand($this->ownIngredientSlug(), 'gramme', 300.0)),
                'dessert',
                userId: $this->author->getId(),
            ),
            'old-client' => new UpdateRecipeCommand(
                $this->pie->getId(),
                $this->pie->getName(),
                $this->pie->getServing(),
                Tab::fromArray(new UpdateRecipeRowSubCommand($this->ownIngredientSlug(), 'gramme', 250.0)),
                $this->pie->getType()->getSlug(),
                expectedVersion: $this->pie->getVersion(),
                userId: $this->author->getId(),
            ),
            'null-components' => new UpdateRecipeCommand(
                $this->pie->getId(),
                'Tarte renommée',
                $this->pie->getServing(),
                Tab::fromArray(new UpdateRecipeRowSubCommand($this->ownIngredientSlug(), 'gramme', 250.0)),
                $this->pie->getType()->getSlug(),
                userId: $this->author->getId(),
                components: null,
            ),
            'type-changed' => new UpdateRecipeCommand(
                $this->pie->getId(),
                $this->pie->getName(),
                $this->pie->getServing(),
                Tab::fromArray(new UpdateRecipeRowSubCommand($this->ownIngredientSlug(), 'gramme', 250.0)),
                'plat',
                userId: $this->author->getId(),
            ),
            'same-secret' => new UpdateRecipeCommand(
                $this->pie->getId(),
                $this->pie->getName(),
                $this->pie->getServing(),
                Tab::fromArray(new UpdateRecipeRowSubCommand($this->ownIngredientSlug(), 'gramme', 250.0)),
                $this->pie->getType()->getSlug(),
                secret: false,
                userId: $this->author->getId(),
            ),
            'secret' => new UpdateRecipeCommand(
                $this->pie->getId(),
                $this->pie->getName(),
                $this->pie->getServing(),
                Tab::fromArray(new UpdateRecipeRowSubCommand($this->ownIngredientSlug(), 'gramme', 100.0)),
                $this->pie->getType()->getSlug(),
                secret: true,
                userId: $this->author->getId(),
            ),
            'type-removed' => new RemoveRecipeTypeCommand($this->pie->getType()->getSlug(), $this->pie->getType()->getSlug() === 'dessert' ? 'plat' : 'dessert'),
            default => throw new \LogicException("Cas d'écriture inconnu : $write"),
        };
    }

    /**
     * Le slug de l'ingrédient de la ligne propre de la tarte (le builder y ajoute l'identifiant de son créateur).
     */
    private function ownIngredientSlug(): string
    {
        return $this->pie->getRows()->reset()->getIngredient()->getSlug();
    }

    /**
     * @return array{edges: list<array<string, mixed>>, count: int}
     */
    private function state(): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            'edges' => $connection->fetchAllAssociative('SELECT id, recipe, component, multiplier, position FROM recipe_component WHERE recipe = ? ORDER BY position', [$this->pie->getId()]),
            'count' => (int) $connection->fetchOne('SELECT component_count FROM recipe WHERE id = ?', [$this->pie->getId()]),
        ];
    }

    private function recipe(string $name, string $ingredient): RecipeBuilder
    {
        return new RecipeBuilder()
            ->withAuthor($this->author)
            ->withName($name)
            ->withRecipeType(new RecipeTypeBuilder()->isDessert())
            ->addRow(new RecipeRowBuilder()
                ->withIngredient(new IngredientBuilder()->withName($ingredient)->withCreator($this->author, true)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo()))
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(250));
    }

    private function saved(RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }
}
