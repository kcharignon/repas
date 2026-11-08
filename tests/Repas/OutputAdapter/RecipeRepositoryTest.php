<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Infrastructure\Entity\RecipeRow as RecipeRowEntity;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Chaque test crée ses auteurs, recettes et ingrédients (la base de test est vide au départ).
 */
class RecipeRepositoryTest extends DatabaseTestCase
{
    private RecipeRepository $recipeRepository;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeRepository = static::getContainer()->get(RecipeRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testRowsKeepTheirOrderAfterSavingAndAfterReordering(): void
    {
        // Arrange : trois lignes dans un ordre qui n'est ni alphabétique ni celui des identifiants
        $user = $this->savedUser();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $rows = $recipe->getRows()->toArray();
        $this->persister->saveRecipeDependencies($recipe);
        $this->recipeRepository->save($recipe);
        $slugs = static fn(Recipe $loaded): array => array_map(static fn($row) => $row->getIngredient()->getSlug(), array_values($loaded->getRows()->toArray()));
        $expected = array_map(static fn($row) => $row->getIngredient()->getSlug(), array_values($rows));

        // Assert : l'ordre enregistré est l'ordre relu
        $this->assertGreaterThan(2, count($expected));
        $this->assertSame($expected, $slugs($this->recipeRepository->findOneById($recipe->getId())));

        // Arrange : la recette est modifiée, ses lignes passent dans l'ordre inverse
        $recipe->setRows(Tab::fromArray(...array_reverse(array_values($rows))));

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $this->assertSame(array_reverse($expected), $slugs($this->recipeRepository->findOneById($recipe->getId())));
    }

    public function testCRUD(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($user)->build();
        $this->persister->saveRecipeDependencies($recipe);

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $recipeLoaded = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($recipe, $recipeLoaded);

        // Arrange
        $recipe->setServing(10);
        $recipe->setName("nouveau petit nom");
        $recipe->setType(new RecipeTypeBuilder()->isDessert()->build());
        $recipe->setRows(Tab::fromArray(new RecipeRowBuilder()->withRecipeId($recipe->getId())->build()));
        $this->persister->saveRecipeDependencies($recipe);

        // Act
        $this->recipeRepository->save($recipe);

        // Assert
        $recipeLoaded = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($recipe, $recipeLoaded);
    }

    public function testFindByAuthor(): void
    {
        // Arrange
        $author = $this->savedUser();
        $first = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author));
        $second = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author));
        $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($this->savedUser()));

        // Act
        $recipes = $this->recipeRepository->findByAuthor($author->getId());

        // Assert
        $this->assertEqualsCanonicalizing([$first->getId(), $second->getId()], $this->idsOf($recipes));
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }

    public function testIsIngredientUsedTellsIfAnyRecipeUsesTheIngredient(): void
    {
        // Arrange : les pâtes sont dans une recette, le lait dans aucune
        $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->savedUser()));
        $milk = new IngredientBuilder()->isMilk()->build();
        $this->persister->saveIngredient($milk);
        $pasta = new IngredientBuilder()->isPasta()->build();

        // Act & Assert
        $this->assertTrue($this->recipeRepository->isIngredientUsed($pasta->getSlug()));
        $this->assertFalse($this->recipeRepository->isIngredientUsed($milk->getSlug()));
        $this->assertFalse($this->recipeRepository->isIngredientUsed('inconnu'));
    }

    public function testDelete(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($user));

        // Act
        $this->recipeRepository->delete($recipe);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear(); // Nettoie le cache Doctrine

        // Assert
        $this->expectException(\Exception::class); // Adapte si une exception spécifique est levée
        $this->recipeRepository->findOneById($recipe->getId());
    }

    public function testEverySaveOfAnExistingRecipeIncreasesItsVersion(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($user));
        $this->assertSame(1, $this->recipeRepository->findOneById($recipe->getId())->getVersion());

        // Act : un champ de la recette change
        $recipe->setName('Autre nom');
        $this->recipeRepository->save($recipe);

        // Assert
        $this->assertSame(2, $this->recipeRepository->findOneById($recipe->getId())->getVersion());

        // Act : seules les lignes changent, la ligne de la recette elle-même n'a pas bougé
        $recipe->setRows(Tab::fromArray(...array_reverse(array_values($recipe->getRows()->toArray()))));
        $this->recipeRepository->save($recipe);

        // Assert
        $this->assertSame(3, $this->recipeRepository->findOneById($recipe->getId())->getVersion());
    }

    public function testSavingARecipeChangedByAnotherRequestIsRefusedAndWritesNothing(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($user));
        $loaded = $this->recipeRepository->findOneById($recipe->getId());
        // Une autre requête enregistre la recette après notre lecture (hors de l'identity map de celle-ci)
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE recipe SET version = version + 1, name = :name WHERE id = :id',
            ['name' => "Nom de l'autre", 'id' => $recipe->getId()],
        );
        $loaded->setName('Mon nom');

        // Assert
        $this->expectExceptionObject(RecipeException::concurrentModification($recipe->getId()));

        // Act
        try {
            $this->recipeRepository->save($loaded);
        } finally {
            $this->entityManager->clear();
            $after = $this->recipeRepository->findOneById($recipe->getId());
            $this->assertSame("Nom de l'autre", $after->getName());
            $this->assertSame(2, $after->getVersion());
        }
    }

    public function testDeletingARecipeChangedByAnotherRequestIsRefusedAndDeletesNothing(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($user));
        $loaded = $this->recipeRepository->findOneById($recipe->getId());
        $this->entityManager->getConnection()->executeStatement('UPDATE recipe SET version = version + 1 WHERE id = :id', ['id' => $recipe->getId()]);

        // Assert
        $this->expectExceptionObject(RecipeException::concurrentModification($recipe->getId()));

        // Act
        try {
            $this->recipeRepository->delete($loaded);
        } finally {
            $this->entityManager->clear();
            $this->assertSame($recipe->getRows()->count(), $this->recipeRepository->findOneById($recipe->getId())->getRows()->count());
        }
    }

    public function testTwoCopiesOfTheSameRecipeInOneRequestDoNotBlockEachOther(): void
    {
        // Arrange : deux chargements dans la même requête, comme un handler et le listener qui recharge l'agrégat
        $user = $this->savedUser();
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($user));
        $first = $this->recipeRepository->findOneById($recipe->getId());
        $second = $this->recipeRepository->findOneById($recipe->getId());
        $first->setName('Premier');
        $second->setServing(9);

        // Act
        $this->recipeRepository->save($first);
        $this->recipeRepository->save($second);

        // Assert : le dernier enregistrement gagne, la version a augmenté deux fois
        $loaded = $this->recipeRepository->findOneById($recipe->getId());
        $this->assertSame(3, $loaded->getVersion());
        $this->assertSame(9, $loaded->getServing());
    }

    public function testDeleteRemovesTheRowsOfTheRecipeToo(): void
    {
        // Arrange
        $user = $this->savedUser();
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($user));
        $otherRecipe = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($user));
        $rowsOf = fn(string $recipeId): int => (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(rr.id)')
            ->from(RecipeRowEntity::class, 'rr')
            ->where('rr.recipeId = :recipeId')
            ->setParameter('recipeId', $recipeId)
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertGreaterThan(0, $rowsOf($recipe->getId()));

        // Act
        $this->recipeRepository->delete($recipe);

        // Assert : plus aucune ligne orpheline, celles des autres recettes sont intactes
        $this->assertSame(0, $rowsOf($recipe->getId()));
        $this->assertSame($otherRecipe->getRows()->count(), $rowsOf($otherRecipe->getId()));
    }

    public function testFindBy(): void
    {
        // Arrange
        $author = $this->savedUser();
        $small = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withName('zzz pates test')->withServing(2)->withAuthor($author));
        $big = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withName('zzz pates test')->withServing(8)->withAuthor($author));
        $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author));
        $criteria = ['name' => 'zzz pates test'];
        $orderBy = ['serving' => 'DESC'];

        // Act
        $recipes = $this->recipeRepository->findBy($criteria, $orderBy);

        // Assert
        $this->assertSame([$big->getId(), $small->getId()], $this->idsOf($recipes));
        RepasAssert::assertTabType(Tab::newEmptyTyped(Recipe::class), $recipes);
    }


    // ---- Les recettes utilisées dans d'autres recettes (ADR 0040, COMP-001) ----

    public function testTheComponentsOfANewRecipeAreWrittenAndReadInTheOrderOfTheirPositions(): void
    {
        // Arrange : une tarte qui utilise la crème (une demi-fois) puis la pâte (deux fois)
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $cream = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Crème'));

        // Act
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($cream, 0.5)->withComponent($dough, 2.0));

        // Assert : dans l'ordre des positions, avec leur multiplicateur, et le compte tenu
        $loaded = $this->recipeRepository->findOneById($pie->getId());
        $this->assertSame([$cream->getId(), $dough->getId()], $loaded->componentIds());
        $this->assertSame([0.5, 2.0], array_values($loaded->getComponents()->map(static fn($component) => $component->getMultiplier())->toArray()));
        $this->assertSame([0, 1], array_values($loaded->getComponents()->map(static fn($component) => $component->getPosition())->toArray()));
        $this->assertSame([$pie->getId(), $pie->getId()], array_values($loaded->getComponents()->map(static fn($component) => $component->getRecipeId())->toArray()));
        $this->assertTrue($loaded->hasComponents());
        $this->assertFalse($loaded->areComponentsChanged(), 'un modèle qui vient d\'être lu n\'a rien à écrire');
        $this->assertSame(2, $this->componentCount($pie->getId()));
        // Les recettes utilisées n'ont pas de composant, ni de compte
        $this->assertSame([false, 0], [$this->recipeRepository->findOneById($dough->getId())->hasComponents(), $this->componentCount($dough->getId())]);
    }

    public function testSavingALoadedRecipeThatDidNotTouchItsComponentsNeverErasesThem(): void
    {
        // Arrange
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($dough, 2.0));
        $edgesBefore = $this->edges($pie->getId());
        $this->assertCount(1, $edgesBefore);

        // Act : tout ce qui n'est pas les composants (nom, personnes, type, secret, favori, lignes), chacun enregistré après un chargement neuf
        foreach ([
            fn(Recipe $recipe) => $recipe->setName('Autre nom'),
            fn(Recipe $recipe) => $recipe->setServing(9),
            fn(Recipe $recipe) => $recipe->setType(new RecipeTypeBuilder()->isDessert()->build()),
            fn(Recipe $recipe) => $recipe->changeFavorite(true, $recipe->getAuthorId()),
            fn(Recipe $recipe) => $recipe->changeSecret(true, $recipe->getAuthorId()),
            fn(Recipe $recipe) => $recipe->setRows(Tab::fromArray(...array_reverse(array_values($recipe->getRows()->toArray())))),
            static fn(Recipe $recipe) => null,
        ] as $change) {
            $this->entityManager->clear();
            $loaded = $this->recipeRepository->findOneById($pie->getId());
            $this->persister->saveRecipeDependencies($loaded);
            $change($loaded);
            $this->recipeRepository->save($loaded);

            // Assert : les mêmes arêtes (mêmes identifiants) et le même compte
            $this->assertSame($edgesBefore, $this->edges($pie->getId()));
            $this->assertSame(1, $this->componentCount($pie->getId()));
        }
    }

    public function testSavingAModelLoadedWithoutItsEdgesNeverErasesThemEither(): void
    {
        // Arrange : `findByIds` ne lit pas les arêtes
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($dough, 2.0));
        $edgesBefore = $this->edges($pie->getId());
        $this->entityManager->clear();
        $withoutEdges = $this->recipeRepository->findByIds([$pie->getId()])[$pie->getId()];
        $this->assertSame([true, []], [$withoutEdges->hasComponents(), $withoutEdges->componentIds()]);

        // Act
        $withoutEdges->setName('Un autre nom');
        $this->recipeRepository->save($withoutEdges);

        // Assert
        $this->assertSame($edgesBefore, $this->edges($pie->getId()));
        $this->assertSame(1, $this->componentCount($pie->getId()));
    }

    public function testSavingWithTheFlagReplacesTheComponentsAndKeepsTheCountRight(): void
    {
        // Arrange : une tarte qui utilise la pâte et la crème
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $cream = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Crème'));
        $glaze = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Glaçage'));
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($dough, 2.0)->withComponent($cream, 1.0));
        $this->entityManager->clear();
        $loaded = $this->recipeRepository->findOneById($pie->getId());
        $doughEdge = $loaded->getComponents()->reset();

        // Act : la pâte reste (même arête, nouveau multiplicateur, passée en dernier), la crème part, le glaçage arrive
        $loaded->setComponents(Tab::fromArray(
            RecipeComponent::create(UuidGenerator::new(), $pie->getId(), $glaze->getId(), 1.0),
            RecipeComponent::create($doughEdge->getId(), $pie->getId(), $dough->getId(), 3.0),
        ));
        $this->recipeRepository->save($loaded);

        // Assert
        $this->entityManager->clear();
        $after = $this->recipeRepository->findOneById($pie->getId());
        $this->assertSame([$glaze->getId(), $dough->getId()], $after->componentIds());
        $this->assertSame([1.0, 3.0], array_values($after->getComponents()->map(static fn($component) => $component->getMultiplier())->toArray()));
        $this->assertSame($doughEdge->getId(), $after->getComponents()[1]->getId(), 'l\'arête de la pâte garde son identifiant');
        $this->assertSame(2, $this->componentCount($pie->getId()));
        $this->assertCount(2, $this->edges($pie->getId()));

        // Act : tout retirer
        $after->setComponents(Tab::newEmptyTyped(RecipeComponent::class));
        $this->recipeRepository->save($after);

        // Assert
        $this->entityManager->clear();
        $this->assertFalse($this->recipeRepository->findOneById($pie->getId())->hasComponents());
        $this->assertSame([], $this->edges($pie->getId()));
        $this->assertSame(0, $this->componentCount($pie->getId()));
    }

    public function testReplacingTheEdgeOfAKnownPairUnderAnotherIdentifierDoesNotMeetTheUniqueIndex(): void
    {
        // Arrange
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($dough, 2.0));
        $this->entityManager->clear();
        $loaded = $this->recipeRepository->findOneById($pie->getId());

        // Act : la même paire (tarte, pâte) sous une autre arête
        $loaded->setComponents(Tab::fromArray(RecipeComponent::create('another-edge-id', $pie->getId(), $dough->getId(), 5.0)));
        $this->recipeRepository->save($loaded);

        // Assert : une seule ligne, la nouvelle
        $edges = $this->edges($pie->getId());
        $this->assertCount(1, $edges);
        $this->assertSame(['another-edge-id', 5.0], [$edges[0]['id'], (float) $edges[0]['multiplier']]);
    }

    public function testAnEdgeToARecipeThatNoLongerExistsIsReadWithoutError(): void
    {
        // Arrange : aucune clé étrangère, la recette utilisée a disparu
        $author = $this->savedUser();
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent(new RecipeBuilder()->withId('gone-recipe-id'), 2.0));
        $this->entityManager->clear();

        // Act
        $loaded = $this->recipeRepository->findOneById($pie->getId());

        // Assert : l'arête est là, le modèle ne porte que des identifiants
        $this->assertSame(['gone-recipe-id'], $loaded->componentIds());
        $this->assertSame([], $this->recipeRepository->findByIds(['gone-recipe-id']));
    }

    public function testARecipeWithoutComponentReadsNoEdgeAndSavingItWritesNone(): void
    {
        // Arrange
        $recipe = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->savedUser()));
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);

        // Act : lire puis enregistrer
        $counter->reset();
        $loaded = $this->recipeRepository->findOneById($recipe->getId());
        $loaded->setName('Autre nom');
        $this->recipeRepository->save($loaded);

        // Assert : zéro composant, zéro requête de plus
        $this->assertSame([], array_filter(array_keys($counter->byTable()), static fn(string $key): bool => str_contains($key, 'recipe_component')));
    }

    public function testARecipeWithComponentsReadsItsEdgesInOneQuery(): void
    {
        // Arrange
        $author = $this->savedUser();
        $first = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $second = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Crème'));
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($first)->withComponent($second));
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // Act
        $this->recipeRepository->findOneById($pie->getId());

        // Assert : une requête pour toutes les arêtes, jamais une par composant
        $this->assertSame(1, $counter->byTable()['SELECT recipe_component']);
    }

    public function testFindByIdsReadsSeveralRecipesWithTheirRowsAndWithoutTheirComponents(): void
    {
        // Arrange
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $cream = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Crème')->withRecipeType(new RecipeTypeBuilder()->isDessert()));
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($dough));
        $this->entityManager->clear();

        // Act : dans l'ordre demandé, un identifiant inconnu et un doublon
        $recipes = $this->recipeRepository->findByIds([$pie->getId(), 'unknown-id', $cream->getId(), $dough->getId(), $pie->getId()]);

        // Assert
        $this->assertSame([$pie->getId(), $cream->getId(), $dough->getId()], array_keys($recipes));
        foreach ([$pie, $cream, $dough] as $expected) {
            RepasAssert::assertRecipe($expected, $recipes[$expected->getId()]);
        }
        // Les arêtes ne sont pas lues, la recette sait pourtant qu'elle en a
        $this->assertSame([true, [], 1], [$recipes[$pie->getId()]->hasComponents(), $recipes[$pie->getId()]->componentIds(), $recipes[$pie->getId()]->getComponentCount()]);
        $this->assertFalse($recipes[$pie->getId()]->areComponentsChanged());
        $this->assertFalse($recipes[$dough->getId()]->hasComponents());
        $this->assertSame([], $this->recipeRepository->findByIds([]));
    }

    public function testFindByIdsReadsInTheSameNumberOfQueriesWhateverTheNumberOfRecipes(): void
    {
        // Arrange : dix recettes d'ingrédients tous différents
        $author = $this->savedUser();
        $ids = [];
        foreach (range(1, 10) as $index) {
            $ids[] = $this->savedRecipe(new RecipeBuilder()->withAuthor($author)->withName("Recette $index")
                ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->withName("zzz ingrédient $index")->withCreator($author, true))->withUnit(new UnitBuilder()->isGramme())->withQuantity($index))
                ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->withName("zzz autre ingrédient $index")->withCreator($author, true))->withUnit(new UnitBuilder()->isUnite())->withQuantity($index)))->getId();
        }
        $counter = static::getContainer()->get(QueryCounter::class);
        $measure = function (array $wanted) use ($counter): array {
            $this->entityManager->clear();
            $counter->reset();
            $recipes = $this->recipeRepository->findByIds($wanted);
            $this->assertCount(count($wanted), $recipes);

            return [$counter->count(), $counter->byTable()];
        };

        // Act
        [$one, $oneByTable] = $measure(array_slice($ids, 0, 1));
        [$five] = $measure(array_slice($ids, 0, 5));
        [$ten, $tenByTable] = $measure($ids);

        // Assert : une lecture par table, jamais une par recette
        $this->assertSame($one, $five);
        $this->assertSame($one, $ten);
        $this->assertSame($oneByTable, $tenByTable);
        $this->assertSame(1, $tenByTable['SELECT recipe']);
        $this->assertSame(1, $tenByTable['SELECT recipe_row']);
        $this->assertSame(1, $tenByTable['SELECT ingredient']);
        $this->assertSame(1, $tenByTable['SELECT recipe_type']);
    }

    public function testTheThreePredicatesOfTheUseOfARecipe(): void
    {
        // Arrange : une tarte ordinaire qui utilise la pâte, une tourte secrète qui utilise la pâte et un glaçage secret, une recette isolée
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $glaze = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Glaçage')->asSecret());
        $lonely = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Seule'));
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($dough));
        $secretPie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withName('Tourte')->asSecret()->withComponent($glaze));

        // Act & Assert : « sert-elle quelque part ? »
        $this->assertTrue($this->recipeRepository->isUsedAsComponent($dough->getId()));
        $this->assertTrue($this->recipeRepository->isUsedAsComponent($glaze->getId()));
        $this->assertFalse($this->recipeRepository->isUsedAsComponent($lonely->getId()));
        $this->assertFalse($this->recipeRepository->isUsedAsComponent($pie->getId()), 'une recette composée ne sert pas, elle utilise');
        $this->assertFalse($this->recipeRepository->isUsedAsComponent('unknown-id'));

        // « une recette ORDINAIRE l'utilise-t-elle ? » : la pâte oui (la tarte est ordinaire), le glaçage non (seule la tourte secrète l'utilise)
        $this->assertTrue($this->recipeRepository->isUsedByOrdinaryRecipe($dough->getId()));
        $this->assertFalse($this->recipeRepository->isUsedByOrdinaryRecipe($glaze->getId()));
        $this->assertFalse($this->recipeRepository->isUsedByOrdinaryRecipe($lonely->getId()));

        // « utilise-t-elle une recette SECRÈTE ? »
        $this->assertFalse($this->recipeRepository->hasSecretComponent($pie->getId()));
        $this->assertTrue($this->recipeRepository->hasSecretComponent($secretPie->getId()));
        $this->assertFalse($this->recipeRepository->hasSecretComponent($dough->getId()), 'une recette sans composant');
        $this->assertFalse($this->recipeRepository->hasSecretComponent('unknown-id'));
    }

    public function testTheUsableCopiesOfSeveralRecipesAreFoundInOneQueryAndSkipTheOnesThatCannotServe(): void
    {
        // Arrange : trois recettes de l'auteur ; le copieur a copié la première trois fois (la plus ancienne est secrète, la suivante a des composants, la
        // dernière est utilisable), la deuxième une fois (utilisable), la troisième jamais ; un autre compte a copié la troisième
        $author = $this->savedUser();
        $copier = $this->savedUser();
        $other = $this->savedUser();
        $first = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Première'));
        $second = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Deuxième'));
        $third = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withName('Troisième'));
        $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withId('00000000-0000-4000-8000-000000000001')->withAuthor($copier)->withOriginalId($first->getId())->asSecret());
        $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withId('00000000-0000-4000-8000-000000000002')->withAuthor($copier)->withOriginalId($first->getId())->withComponent($second));
        $usableCopy = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withId('00000000-0000-4000-8000-000000000003')->withAuthor($copier)->withOriginalId($first->getId()));
        $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withId('00000000-0000-4000-8000-000000000004')->withAuthor($copier)->withOriginalId($first->getId()));
        $secondCopy = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($copier)->withOriginalId($second->getId()));
        $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($other)->withOriginalId($third->getId()));
        $this->entityManager->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // Act
        $copies = $this->recipeRepository->findUsableCopyIdsOf([$first->getId(), $second->getId(), $third->getId(), 'unknown-id'], $copier->getId());

        // Assert : la copie utilisable la plus ancienne (ni la secrète, ni celle qui a des composants), une par recette, rien pour les autres, en UNE requête
        $this->assertSame([$first->getId() => $usableCopy->getId(), $second->getId() => $secondCopy->getId()], $copies);
        $this->assertSame(1, $counter->count());
        $this->assertSame([], $this->recipeRepository->findUsableCopyIdsOf([], $copier->getId()));
        $this->assertSame([], $this->recipeRepository->findUsableCopyIdsOf([$third->getId()], $copier->getId()), 'la copie d\'un autre compte ne compte pas');
    }

    public function testAnEdgeWhoseComposedRecipeNoLongerExistsDoesNotCountAsAnOrdinaryUse(): void
    {
        // Arrange : la recette composée a disparu (aucune clé étrangère), l'arête reste
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $this->entityManager->getConnection()->executeStatement("INSERT INTO recipe_component (id, recipe, component, multiplier, position) VALUES ('orphan-edge', 'gone-recipe-id', ?, 1, 0)", [$dough->getId()]);

        // Act & Assert : l'arête sert encore de preuve d'usage (à corriger), mais elle ne bloque pas le secret d'une recette ordinaire disparue
        $this->assertTrue($this->recipeRepository->isUsedAsComponent($dough->getId()));
        $this->assertFalse($this->recipeRepository->isUsedByOrdinaryRecipe($dough->getId()));
    }

    public function testDeletingAComposedRecipeDeletesItsEdgesAndNeverTheRecipesItUsed(): void
    {
        // Arrange
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $pie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($dough));
        $otherPie = $this->savedRecipe(new RecipeBuilder()->isSoftBoiledEggs()->withAuthor($author)->withName('Autre tarte')->withComponent($dough));
        $this->entityManager->clear();

        // Act
        $this->recipeRepository->delete($this->recipeRepository->findOneById($pie->getId()));

        // Assert : ses arêtes ont disparu, celles de l'autre tarte et les deux recettes utilisées sont intactes
        $this->assertSame([], $this->edges($pie->getId()));
        $this->assertCount(1, $this->edges($otherPie->getId()));
        $this->entityManager->clear();
        $this->assertSame($dough->getId(), $this->recipeRepository->findOneById($dough->getId())->getId());
        $this->assertTrue($this->recipeRepository->isUsedAsComponent($dough->getId()));
    }

    public function testDeletingAnAuthorsRecipesDeletesTheirEdgesButNotTheOthers(): void
    {
        // Arrange : une tarte composée de deux auteurs
        $leaving = $this->savedUser();
        $staying = $this->savedUser();
        $leavingDough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($leaving)->withName('Pâte')->withId('leaving-dough'));
        $leavingPie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($leaving)->withId('leaving-pie')->withComponent($leavingDough));
        $stayingDough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($staying)->withName('Pâte')->withId('staying-dough'));
        $stayingPie = $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($staying)->withId('staying-pie')->withComponent($stayingDough));

        // Act
        $this->recipeRepository->deleteByAuthor($leaving->getId());

        // Assert : plus rien de l'auteur, ni recettes, ni lignes, ni arêtes ; l'autre compte est intact
        $this->assertSame([], $this->edges($leavingPie->getId()));
        $this->assertSame('0', (string) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM recipe WHERE author = ?', [$leaving->getId()]));
        $this->assertSame('0', (string) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM recipe_row WHERE recipe IN ('leaving-dough', 'leaving-pie')"));
        $this->assertCount(1, $this->edges($stayingPie->getId()));
        $this->entityManager->clear();
        $this->assertSame(['staying-dough'], $this->recipeRepository->findOneById($stayingPie->getId())->componentIds());
        $this->assertGreaterThan(0, $this->recipeRepository->findOneById($stayingDough->getId())->getRows()->count());
    }

    public function testDeletingAnAuthorsRecipesIsOneInstructionWhetherOrNotTheyHaveComponents(): void
    {
        // Arrange
        $author = $this->savedUser();
        $dough = $this->savedRecipe(new RecipeBuilder()->isBasqueCake()->withAuthor($author)->withName('Pâte'));
        $this->savedRecipe(new RecipeBuilder()->isPastaCarbonara()->withAuthor($author)->withComponent($dough));
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // Act
        $this->recipeRepository->deleteByAuthor($author->getId());

        // Assert : les lignes ET les arêtes dans une instruction, puis les recettes : deux, comme avant la table des composants
        $this->assertSame(2, $counter->count());
    }

    /**
     * @return list<array<string, mixed>> les arêtes de la recette composée, dans l'ordre de leurs positions
     */
    private function edges(string $recipeId): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, recipe, component, multiplier, position FROM recipe_component WHERE recipe = ? ORDER BY position, id', [$recipeId]);
    }

    private function componentCount(string $recipeId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT component_count FROM recipe WHERE id = ?', [$recipeId]);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * @param Tab<Recipe> $recipes
     * @return list<string>
     */
    private function idsOf(Tab $recipes): array
    {
        return array_values(array_map(static fn(Recipe $recipe): string => $recipe->getId(), $recipes->toArray()));
    }
}
