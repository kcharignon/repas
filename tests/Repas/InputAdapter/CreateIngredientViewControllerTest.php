<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\Builder\DTO\DepartmentDTOBuilder;
use Repas\Tests\Helper\Builder\DTO\UnitDTOBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class CreateIngredientViewControllerTest extends QueryBusWebTestCase
{
    public function testRedirectsAnAnonymousVisitorToTheLogin(): void
    {
        // When
        $this->client->request('GET', '/ingredient');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirection());
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }

    public function testRefusesAnAnonymousSubmission(): void
    {
        // When
        $this->client->request('POST', '/ingredient', [
            'create_ingredient' => ['name' => 'zzz sel', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirection());
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location'));
    }
    public function testValidSubmissionCreatesAPersonalIngredientThenRedirectsToItsDepartment(): void
    {
        // Given
        $user = $this->savedUser();
        $this->offerOptions();
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/ingredient', ['create_ingredient' => $this->payload('zzz sel')], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/department/cereale'));
        $created = static::getContainer()->get(IngredientRepository::class)->findOneBySlug(StringTool::slugify('zzz sel' . $user->getId()));
        $this->assertSame($user->getId(), $created->getCreatorId());
    }

    public function testADuplicateNameShowsAnErrorAndKeepsTheFirstIngredient(): void
    {
        // Given
        $user = $this->savedUser();
        $first = new IngredientBuilder()->withName('zzz sel')->withCreator($user, true)->withImage('images/premier.png')->build();
        $this->persister->saveIngredient($first);
        $this->offerOptions();
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('POST', '/ingredient', ['create_ingredient' => $this->payload('zzz sel')], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Un ingrédient de ce nom existe déjà.', $crawler->text());
        $this->assertSame('images/premier.png', static::getContainer()->get(IngredientRepository::class)->findOneBySlug($first->getSlug())->getImage());
    }

    private function offerOptions(): void
    {
        // Les listes de choix viennent du bus de lecture doublé ; le handler lit les vrais référentiels
        $this->persister->saveUnit(new UnitBuilder()->isGramme()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isCereal()->build());
        $this->willOfferFormOptions(
            units: [new UnitDTOBuilder()->withSlug('gramme')->withName('gramme')->build()],
            departments: [new DepartmentDTOBuilder()->withSlug('cereale')->withName('céréale')->build()],
        );
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $name): array
    {
        return ['name' => $name, 'department' => 'cereale', 'defaultCookingUnit' => 'gramme', 'defaultPurchaseUnit' => 'gramme', '_token' => 'csrf-token'];
    }

}
