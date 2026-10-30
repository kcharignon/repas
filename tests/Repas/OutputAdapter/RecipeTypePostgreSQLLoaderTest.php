<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\RecipeTypeLoader;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeCountDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

class RecipeTypePostgreSQLLoaderTest extends DatabaseTestCase
{
    private RecipeTypeRepository $recipeTypeRepository;
    private RecipeTypeLoader $recipeTypeLoader;
    private DataPersister $persister;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeTypeRepository = static::getContainer()->get(RecipeTypeRepository::class);
        $this->recipeTypeLoader = static::getContainer()->get(RecipeTypeLoader::class);
        $this->persister = new DataPersister(static::getContainer());
        $this->user = new UserBuilder()->build();
        $this->persister->saveUser($this->user);
    }

    public function testListReturnsNothingWhenThereIsNone(): void
    {
        // When
        $result = $this->recipeTypeLoader->list($this->user->getId());

        // Then
        $this->assertSame([], $result);
    }

    public function testListReturnsDTOsOfCreatedRecipeTypes(): void
    {
        // Given
        $this->recipeTypeRepository->save(
            new RecipeTypeBuilder()->withSlug('zzz-gouter')->withName('Zzz gouter')->withImage('images/zzz.svg')->withOrder(9001)->build()
        );

        // When
        $result = $this->recipeTypeLoader->list($this->user->getId());

        // Then
        $this->assertContainsOnlyInstancesOf(RecipeTypeCountDTO::class, $result);
        $this->assertEquals(
            new RecipeTypeCountDTO('zzz-gouter', 'Zzz gouter', 'images/zzz.svg', 9001, 0),
            $this->findBySlug($result, 'zzz-gouter'),
        );
    }

    public function testGetReturnsTheRecipeType(): void
    {
        // Given
        $this->recipeTypeRepository->save(new RecipeTypeBuilder()->withSlug('zzz-brunch')->withName('zzz brunch')->withImage('file://images/zzz.svg')->withOrder(7)->build());

        // When
        $result = $this->recipeTypeLoader->get('zzz-brunch');

        // Then
        $this->assertEquals(new RecipeTypeDTO('zzz-brunch', 'zzz brunch', 'file://images/zzz.svg', 7), $result);
    }

    public function testGetFailsForAnUnknownRecipeType(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::typeNotFound('inconnu'));

        // When
        $this->recipeTypeLoader->get('inconnu');
    }

    public function testListIsOrderedBySequence(): void
    {
        // Given
        $this->recipeTypeRepository->save(new RecipeTypeBuilder()->withSlug('aaa-second')->withName('Aaa second')->withOrder(9002)->build());
        $this->recipeTypeRepository->save(new RecipeTypeBuilder()->withSlug('zzz-first')->withName('Zzz first')->withOrder(9001)->build());

        // When
        $result = $this->recipeTypeLoader->list($this->user->getId());

        // Then
        $slugs = $this->slugs($result);
        $this->assertLessThan(array_search('aaa-second', $slugs), array_search('zzz-first', $slugs));
    }

    public function testListBreaksTiesOnSequenceBySlug(): void
    {
        // Given
        $this->recipeTypeRepository->save(new RecipeTypeBuilder()->withSlug('zzz-tie')->withName('Zzz tie')->withOrder(9003)->build());
        $this->recipeTypeRepository->save(new RecipeTypeBuilder()->withSlug('aaa-tie')->withName('Aaa tie')->withOrder(9003)->build());

        // When
        $result = $this->recipeTypeLoader->list($this->user->getId());

        // Then
        $slugs = $this->slugs($result);
        $this->assertLessThan(array_search('zzz-tie', $slugs), array_search('aaa-tie', $slugs));
    }

    public function testListCountsTheRecipesOfThePersonOnlyAndKeepsEmptyTypesAtZero(): void
    {
        // Given
        $other = new UserBuilder()->build();
        $this->persister->saveUser($other);
        $this->recipeTypeRepository->save(new RecipeTypeBuilder()->withSlug('zzz-un')->withName('Zzz un')->withOrder(9001)->build());
        $this->recipeTypeRepository->save(new RecipeTypeBuilder()->withSlug('zzz-deux')->withName('Zzz deux')->withOrder(9002)->build());
        $this->recipeTypeRepository->save(new RecipeTypeBuilder()->withSlug('zzz-vide')->withName('Zzz vide')->withOrder(9003)->build());
        $this->savedRecipe($this->user, 'Recette un a', 'zzz-un');
        $this->savedRecipe($this->user, 'Recette un b', 'zzz-un');
        $original = $this->savedRecipe($other, 'Recette copiee', 'zzz-deux');
        $this->savedRecipe($this->user, 'Recette copiee', 'zzz-deux', $original->getId());
        $this->savedRecipe($other, 'Recette d\'une autre', 'zzz-un');
        $this->savedRecipe($other, 'Autre encore', 'zzz-vide');

        // When
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $result = $this->recipeTypeLoader->list($this->user->getId());

        // Then
        $this->assertSame(1, $counter->count());
        $this->assertSame(2, $this->findBySlug($result, 'zzz-un')?->recipeCount);
        $this->assertSame(1, $this->findBySlug($result, 'zzz-deux')?->recipeCount);
        $this->assertSame(0, $this->findBySlug($result, 'zzz-vide')?->recipeCount);
        $this->assertSame(['zzz-un', 'zzz-deux', 'zzz-vide'], $this->slugs($result));
    }

    private function savedRecipe(User $author, string $name, string $typeSlug, ?string $originalId = null): \Repas\Repas\Domain\Model\Recipe
    {
        $recipe = new RecipeBuilder()->withAuthor($author)->withName($name)->withRecipeType(new RecipeTypeBuilder()->withSlug($typeSlug)->withName($typeSlug)->withOrder(['zzz-un' => 9001, 'zzz-deux' => 9002, 'zzz-vide' => 9003][$typeSlug]))->withOriginalId($originalId)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    /**
     * @param list<RecipeTypeCountDTO> $recipeTypes
     * @return list<string>
     */
    private function slugs(array $recipeTypes): array
    {
        return array_map(static fn(RecipeTypeCountDTO $recipeType): string => $recipeType->slug, $recipeTypes);
    }

    /**
     * @param list<RecipeTypeCountDTO> $recipeTypes
     */
    private function findBySlug(array $recipeTypes, string $slug): ?RecipeTypeCountDTO
    {
        foreach ($recipeTypes as $recipeType) {
            if ($recipeType->slug === $slug) {
                return $recipeType;
            }
        }

        return null;
    }
}
