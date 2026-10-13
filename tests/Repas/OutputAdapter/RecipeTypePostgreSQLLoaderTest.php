<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\RecipeTypeLoader;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\DatabaseTestCase;

class RecipeTypePostgreSQLLoaderTest extends DatabaseTestCase
{
    private RecipeTypeRepository $recipeTypeRepository;
    private RecipeTypeLoader $recipeTypeLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeTypeRepository = static::getContainer()->get(RecipeTypeRepository::class);
        $this->recipeTypeLoader = static::getContainer()->get(RecipeTypeLoader::class);
    }

    public function testListReturnsNothingWhenThereIsNone(): void
    {
        // When
        $result = $this->recipeTypeLoader->list();

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
        $result = $this->recipeTypeLoader->list();

        // Then
        $this->assertContainsOnlyInstancesOf(RecipeTypeDTO::class, $result);
        $this->assertEquals(
            new RecipeTypeDTO('zzz-gouter', 'Zzz gouter', 'images/zzz.svg', 9001),
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
        $result = $this->recipeTypeLoader->list();

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
        $result = $this->recipeTypeLoader->list();

        // Then
        $slugs = $this->slugs($result);
        $this->assertLessThan(array_search('zzz-tie', $slugs), array_search('aaa-tie', $slugs));
    }

    /**
     * @param list<RecipeTypeDTO> $recipeTypes
     * @return list<string>
     */
    private function slugs(array $recipeTypes): array
    {
        return array_map(static fn(RecipeTypeDTO $recipeType): string => $recipeType->slug, $recipeTypes);
    }

    /**
     * @param list<RecipeTypeDTO> $recipeTypes
     */
    private function findBySlug(array $recipeTypes, string $slug): ?RecipeTypeDTO
    {
        foreach ($recipeTypes as $recipeType) {
            if ($recipeType->slug === $slug) {
                return $recipeType;
            }
        }

        return null;
    }
}
