<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\DTO\IngredientDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class RemoveIngredientViewControllerTest extends QueryBusWebTestCase
{
    public function testRemovesTheIngredientThenRedirectsToItsDepartment(): void
    {
        // Given
        $user = $this->savedUser();
        $ingredient = new IngredientBuilder()->withName('zzz sel')->withCreator($user, true)->withDepartment(new DepartmentBuilder()->isCereal())->build();
        $this->persister->saveIngredient($ingredient);
        $this->queryBus->willReturn(GetIngredientQuery::class, new IngredientDTOBuilder()->withSlug($ingredient->getSlug())->withDepartment('cereale', 'céréale')->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('GET', '/ingredient/' . $ingredient->getSlug() . '/delete');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/department/cereale'));
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetIngredientQuery::class, $query);
        $this->assertSame([$ingredient->getSlug(), $user->getId(), false], [$query->slug, $query->viewerId, $query->viewerIsAdmin]);
        $this->expectExceptionObject(IngredientException::notFound($ingredient->getSlug()));
        static::getContainer()->get(IngredientRepository::class)->findOneBySlug($ingredient->getSlug());
    }

    public function testKeepsAnIngredientUsedInARecipeAndRedirectsWithAnError(): void
    {
        // Given
        $user = $this->savedUser();
        $ingredient = new IngredientBuilder()->withName('zzz sel')->withCreator($user, true)->withDepartment(new DepartmentBuilder()->isCereal())
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())->build();
        $recipe = new RecipeBuilder()->withAuthor($user)->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isGramme())->withQuantity(5))->build();
        $this->persister->saveRecipe($recipe);
        $this->queryBus->willReturn(GetIngredientQuery::class, new IngredientDTOBuilder()->withSlug($ingredient->getSlug())->withDepartment('cereale', 'céréale')->build());
        $this->client->loginUser($user);

        // When
        $this->client->request('GET', '/ingredient/' . $ingredient->getSlug() . '/delete');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/department/cereale'));
        $this->assertSame($ingredient->getSlug(), static::getContainer()->get(IngredientRepository::class)->findOneBySlug($ingredient->getSlug())->getSlug());
    }

    public function testRefusesAnIngredientOfAnotherUser(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->withName('zzz sel')->withCreator($this->savedUser(), true)->build();
        $this->persister->saveIngredient($ingredient);
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/ingredient/' . $ingredient->getSlug() . '/delete');

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }
}
