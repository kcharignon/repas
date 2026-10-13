<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\DTO\IngredientDTOBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Domain\Model\User;

class UpdateIngredientViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsPrefilledFromTheIngredientDTO(): void
    {
        // Given
        $user = $this->savedUser();
        $ingredient = $this->savedIngredientOf($user);
        $this->queryBus->willReturn(GetIngredientQuery::class, $this->dtoOf($ingredient));
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/ingredient/' . $ingredient->getSlug());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetIngredientQuery::class, $query);
        $this->assertSame([$ingredient->getSlug(), $user->getId(), false], [$query->slug, $query->viewerId, $query->viewerIsAdmin]);
        $this->assertSame('Modification de zzz beurre', trim($crawler->filter('h1')->text()));
        $this->assertSame('zzz beurre', $crawler->filter('input[name="update_ingredient[name]"]')->attr('value'));
        $this->assertSame('cereale', $crawler->filter('select[name="update_ingredient[department]"] option[selected]')->attr('value'));
        $this->assertSame('gramme', $crawler->filter('select[name="update_ingredient[defaultCookingUnit]"] option[selected]')->attr('value'));
        $this->assertSame('plaquette', $crawler->filter('select[name="update_ingredient[defaultPurchaseUnit]"] option[selected]')->attr('value'));
        $this->assertSame('250', $crawler->filter('input[name="update_ingredient[coefficient]"]')->attr('value'));
        $this->assertStringContainsString('1 plaquette contient combien de gramme ?', $crawler->filter('#coefficient-label')->text());
        $this->assertCount(0, $crawler->filter('#coefficient-group[style*="display: none"]'));
    }

    public function testCoefficientIsHiddenWhenBothUnitsAreTheSame(): void
    {
        // Given
        $user = $this->savedUser();
        $ingredient = new IngredientBuilder()->withName('zzz sel')->withCreator($user, true)
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())->build();
        $this->persister->saveIngredient($ingredient);
        $this->queryBus->willReturn(GetIngredientQuery::class, new IngredientDTOBuilder()->withSlug($ingredient->getSlug())->withName('zzz sel')->build());
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/ingredient/' . $ingredient->getSlug());

        // Then
        $this->assertCount(1, $crawler->filter('#coefficient-group[style*="display: none"]'));
    }

    public function testValidSubmissionUpdatesTheIngredientThenRedirectsToItsDepartment(): void
    {
        // Given
        $user = $this->savedUser();
        $ingredient = $this->savedIngredientOf($user);
        $this->queryBus->willReturn(GetIngredientQuery::class, $this->dtoOf($ingredient));
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/ingredient/' . $ingredient->getSlug(), [
            'update_ingredient' => [
                'name' => 'zzz beurre salé',
                'department' => 'cereale',
                'defaultCookingUnit' => 'gramme',
                'defaultPurchaseUnit' => 'plaquette',
                'coefficient' => '200',
                '_token' => 'csrf-token',
            ],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/department/cereale'));
        $this->assertSame('zzz beurre salé', static::getContainer()->get(IngredientRepository::class)->findOneBySlug($ingredient->getSlug())->getName());
    }

    public function testRefusesAnIngredientOfAnotherUser(): void
    {
        // Given
        $ingredient = $this->savedIngredientOf($this->savedUser());
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/ingredient/' . $ingredient->getSlug());

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    private function savedIngredientOf(User $user): Ingredient
    {
        $ingredient = new IngredientBuilder()->withName('zzz beurre')->withCreator($user, true)->withDepartment(new DepartmentBuilder()->isCereal())
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isBlock())->build();
        $this->persister->saveIngredient($ingredient);
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($ingredient)
            ->withStartUnit(new UnitBuilder()->isBlock()->build())->withEndUnit(new UnitBuilder()->isGramme()->build())->withCoefficient(250)->build());

        return $ingredient;
    }

    private function dtoOf(Ingredient $ingredient): IngredientDTO
    {
        return new IngredientDTOBuilder()->withSlug($ingredient->getSlug())->withName('zzz beurre')->withDepartment('cereale', 'céréale')
            ->withCookingUnit('gramme', 'gramme')->withPurchaseUnit('plaquette', 'plaquette', '')->withCoefficient(250)
            ->withCreatorId($ingredient->getCreator()->getId())->editable()->build();
    }
}
