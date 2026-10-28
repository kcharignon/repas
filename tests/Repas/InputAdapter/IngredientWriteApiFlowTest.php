<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Parcours des endpoints d'écriture des ingrédients et des conversions avec les vrais bus, la vraie base et les
 * listeners (voir `ShoppingListWriteApiFlowTest`).
 */
class IngredientWriteApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        $this->persister->saveUnit(new UnitBuilder()->isGramme()->build());
        $this->persister->saveUnit(new UnitBuilder()->isKilo()->build());
        $this->persister->saveUnit(new UnitBuilder()->isBox()->build());
    }

    public function testAUserCreatesAPersonalIngredientAndHisStatisticsFollow(): void
    {
        // Given
        $user = $this->savedUser();

        // When
        $this->request('POST', '/api/v1/ingredients', $user, $this->body('Praliné'));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $slug = basename($this->client->getResponse()->headers->get('Location'));
        $ingredient = $this->ingredients()->findOneBySlug($slug);
        $this->assertSame([$user->getId(), 'Praliné'], [$ingredient->getCreatorId(), $ingredient->getName()]);
        $this->assertSame(1, static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->getStatistics()['ingredients']);
    }

    public function testAnAdministratorCreatesAnIngredientOfHisOwnAndHisStatisticsFollow(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);

        // When
        $this->request('POST', '/api/v1/ingredients', $admin, $this->body('Praliné'));

        // Then : plus de commun, l'ingrédient est à lui (le parcours complet est dans `IngredientNoNewCommonApiFlowTest`)
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame($admin->getId(), $this->ingredients()->findOneBySlug(basename($this->client->getResponse()->headers->get('Location')))->getCreatorId());
        $this->assertSame(1, static::getContainer()->get(UserRepository::class)->findOneById($admin->getId())->getStatistics()['ingredients']);
    }

    public function testCreatingTheSameIngredientTwiceIsAConflict(): void
    {
        // Given
        $user = $this->savedUser();
        $this->request('POST', '/api/v1/ingredients', $user, $this->body('Praliné'));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('POST', '/api/v1/ingredients', $user, $this->body('Praliné'));

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
    }

    public function testDifferentUnitsWithoutAnyConversionNeedACoefficientAndNothingIsCreated(): void
    {
        // When
        $this->request('POST', '/api/v1/ingredients', $this->savedUser(), ['defaultPurchaseUnitSlug' => 'boite', 'coefficient' => null] + $this->body('Praliné'));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => IngredientException::coefficientRequired()->getMessage()]], $this->jsonBody());
        $created = $this->entityManager->createQueryBuilder()->select('COUNT(i.slug)')->from(IngredientEntity::class, 'i')
            ->where('i.name = :name')->setParameter('name', 'Praliné')->getQuery()->getSingleScalarResult();
        $this->assertSame(0, (int) $created, 'la transaction du bus annule l\'ingrédient déjà enregistré');
    }

    public function testDifferentUnitsWithACoefficientCreateTheConversionToo(): void
    {
        // When
        $this->request('POST', '/api/v1/ingredients', $this->savedUser(), ['defaultPurchaseUnitSlug' => 'boite', 'coefficient' => 400] + $this->body('Praliné'));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $ingredient = $this->ingredients()->findOneBySlug(basename($this->client->getResponse()->headers->get('Location')));
        $conversions = static::getContainer()->get(ConversionRepository::class)->findByIngredient($ingredient);
        $this->assertSame([['boite', 'gramme', 400.0]], array_map(fn($conversion) => [$conversion->getStartUnit()->getSlug(), $conversion->getEndUnit()->getSlug(), $conversion->getCoefficient()], array_values($conversions->toArray())));
    }

    public function testAnUnknownDepartmentIsNotFound(): void
    {
        // When
        $this->request('POST', '/api/v1/ingredients', $this->savedUser(), ['departmentSlug' => 'inconnu'] + $this->body('Praliné'));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheOwnerReplacesHisIngredientAndKeepsItsImage(): void
    {
        // Given
        $owner = $this->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->withImage('images/farine.png')->build();
        $this->persister->saveIngredient($ingredient);

        // When
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $owner, ['name' => 'Farine complète', 'defaultPurchaseUnitSlug' => 'gramme'] + $this->body('x'));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $updated = $this->ingredients()->findOneBySlug($ingredient->getSlug());
        $this->assertSame(['Farine complète', 'images/farine.png'], [$updated->getName(), $updated->getImage()]);
    }

    public function testAnOldClientSendingLowercaseNamesGetsTheCapitalizedNameWithoutAnyError(): void
    {
        // Given : l'ancien site envoie les noms tels qu'ils sont saisis, en minuscules
        $owner = $this->savedUser();

        // When : création
        $this->request('POST', '/api/v1/ingredients', $owner, $this->body('œufs fermiers'));

        // Then : succès ordinaire, le nom enregistré est le nom corrigé (la suite de la casse est conservée)
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $slug = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame('Œufs fermiers', $this->ingredients()->findOneBySlug($slug)->getName());

        // When : modification
        $this->request('PUT', '/api/v1/ingredients/' . $slug, $owner, $this->body('œufs fermiers BIO'));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Œufs fermiers BIO', $this->ingredients()->findOneBySlug($slug)->getName());

        // When : la lecture renvoie le nom enregistré
        $this->request('GET', '/api/v1/ingredients/' . $slug, $owner);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('Œufs fermiers BIO', $this->jsonBody()['name']);
    }

    public function testAnotherUserCannotUpdateOrDeleteTheIngredient(): void
    {
        // Given
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($this->savedUser(), true)->build();
        $this->persister->saveIngredient($ingredient);
        $intruder = $this->savedUser();

        // When & Then
        $this->request('PUT', '/api/v1/ingredients/' . $ingredient->getSlug(), $intruder, $this->body('Farine'));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/ingredients/' . $ingredient->getSlug(), $intruder);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame($ingredient->getName(), $this->ingredients()->findOneBySlug($ingredient->getSlug())->getName());
    }

    public function testDeletesAnIngredientNobodyUses(): void
    {
        // Given
        $owner = $this->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $this->persister->saveIngredient($ingredient);

        // When
        $this->request('DELETE', '/api/v1/ingredients/' . $ingredient->getSlug(), $owner);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->expectExceptionObject(IngredientException::notFound($ingredient->getSlug()));
        $this->ingredients()->findOneBySlug($ingredient->getSlug());
    }

    public function testAnIngredientUsedInARecipeCannotBeDeleted(): void
    {
        // Given
        $owner = $this->savedUser();
        $ingredient = new IngredientBuilder()->isFlour()->withCreator($owner, true)->build();
        $this->persister->saveRecipe(new RecipeBuilder()->withAuthor($owner)->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isGramme())->withQuantity(5))->build());

        // When
        $this->request('DELETE', '/api/v1/ingredients/' . $ingredient->getSlug(), $owner);

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertSame($ingredient->getSlug(), $this->ingredients()->findOneBySlug($ingredient->getSlug())->getSlug());
    }

    public function testAnAdministratorManagesConversionsThroughTheirLifecycle(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);

        // When
        $this->request('POST', '/api/v1/conversions', $admin, ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 1000]);

        // Then : créée, relue par son Location (vrai bus de lecture), puis remplacée
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $location = $this->client->getResponse()->headers->get('Location');
        $this->request('GET', $location, $admin);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([1000, 'kilo', 'gramme', null], [$this->jsonBody()['coefficient'], $this->jsonBody()['startUnitSlug'], $this->jsonBody()['endUnitSlug'], $this->jsonBody()['ingredientSlug']]);

        $this->request('PUT', $location, $admin, ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 1001]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', $location, $admin);
        $this->assertEquals(1001, $this->jsonBody()['coefficient']);
    }

    public function testAnUnknownConversionIsNotFound(): void
    {
        // When
        $this->request('PUT', '/api/v1/conversions/unknown', $this->savedUser(admin: true), ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 2]);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAUserCannotReadNorWriteConversions(): void
    {
        // Given
        $conversion = new ConversionBuilder()->isKiloToGramme()->build();
        $this->persister->saveConversion($conversion);
        $user = $this->savedUser();

        // When & Then
        $this->request('GET', '/api/v1/conversions/' . $conversion->getId(), $user);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/conversions', $user, ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 2]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $name): array
    {
        return ['name' => $name, 'departmentSlug' => 'boulangerie', 'defaultCookingUnitSlug' => 'gramme', 'defaultPurchaseUnitSlug' => 'gramme'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, User $user, ?array $body = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function ingredients(): IngredientRepository
    {
        $this->entityManager->clear();

        return static::getContainer()->get(IngredientRepository::class);
    }
}
