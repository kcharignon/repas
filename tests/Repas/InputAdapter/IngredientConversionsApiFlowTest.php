<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Application\Interface\CommandBusInterface;
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
 * Le formulaire de conversions de chaque ingrédient (`/api/v1/ingredients/{slug}/conversions`) avec les vrais bus, la vraie base et le vrai JWT :
 * l'utilisateur ajoute, change et retire ses conversions propres, vers n'importe quelle unité ; l'unité de ses recettes continue de se convertir
 * en son unité d'achat ; une conversion universelle n'est jamais touchée ; personne, un administrateur compris, ne touche à l'ingrédient d'un
 * autre ; et la recette dit quelle ligne ne se convertit pas, puis la corrige-t-on.
 */
class IngredientConversionsApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $user;
    private Ingredient $flour;
    private Conversion $spoonToGramme;
    private Conversion $kiloToGramme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        foreach ([new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->withName('tasse'), new UnitBuilder()->isGlass()] as $unit) {
            $this->persister->saveUnit($unit->build());
        }
        $this->user = $this->savedUser();

        // La farine : cuisinée à la cuillère à soupe, achetée au kilo ; « 1 cuillère à soupe = 10 g » est ce qui la relie au kilo (avec l'universelle kilo > gramme)
        $this->flour = new IngredientBuilder()->isFlour()->withCreator($this->user, true)->withDefaultCookingUnit(new UnitBuilder()->isSoupSpoon())->build();
        $this->spoonToGramme = new ConversionBuilder()->withIngredient($this->flour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build();
        $this->kiloToGramme = new ConversionBuilder()->isKiloToGramme()->build();
        $this->persister->saveConversion($this->spoonToGramme);
        $this->persister->saveConversion($this->kiloToGramme);
    }

    public function testTheOwnerAddsChangesAndRemovesHisConversionsAndReadsThem(): void
    {
        // Given
        $uri = '/api/v1/ingredients/' . $this->flour->getSlug() . '/conversions';
        $this->request('GET', $uri);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();
        $this->assertSame([1, 1, 1, true, 'cuillere-a-soupe', 'kilo'], [count($body['member']), count($body['common']), $body['totalItems'], $body['convertible'], $body['cookingUnitSlug'], $body['purchaseUnitSlug']]);

        // When : une conversion vers une autre unité (la tasse), puis une autre (le verre se relie à la tasse), sans limite
        $this->request('POST', $uri, ['startUnitSlug' => 'tasse', 'endUnitSlug' => 'gramme', 'coefficient' => 125]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $cupLocation = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith($uri . '/', $cupLocation);
        $this->request('POST', $uri, ['startUnitSlug' => 'verre', 'endUnitSlug' => 'tasse', 'coefficient' => 2]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // Then : trois conversions propres, triées par unité de départ, l'universelle montrée à part ; les unités des recettes suivent
        $this->request('GET', $uri);
        $own = array_map(static fn(array $c): array => [$c['startUnitSlug'], $c['endUnitSlug'], $c['coefficient']], $this->jsonBody()['member']);
        $this->assertSame([['cuillere-a-soupe', 'gramme', 10], ['tasse', 'gramme', 125], ['verre', 'tasse', 2]], $own);
        $this->assertSame([['kilo', 'gramme', 1000]], array_map(static fn(array $c): array => [$c['startUnitSlug'], $c['endUnitSlug'], $c['coefficient']], $this->jsonBody()['common']));
        $this->assertSame(['cuillere-a-soupe', 'gramme', 'kilo', 'tasse', 'verre'], $this->compatibleUnitSlugs());

        // When : la tasse change de valeur
        $this->request('PUT', $cupLocation, ['startUnitSlug' => 'tasse', 'endUnitSlug' => 'gramme', 'coefficient' => 130]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then
        $this->assertSame(130.0, $this->conversions()->findById(basename($cupLocation))->getCoefficient());

        // When : elle est retirée (la cuillère n'en dépend pas)
        $this->request('DELETE', $cupLocation);

        // Then : le verre ne mène plus à rien, il quitte les unités compatibles
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', $uri);
        $this->assertSame(2, $this->jsonBody()['totalItems']);
        $this->assertSame(['cuillere-a-soupe', 'gramme', 'kilo'], $this->compatibleUnitSlugs());
    }

    public function testTheConversionThatLinksTheRecipeUnitToThePurchaseUnitCannotBeRemovedNorChangedAway(): void
    {
        // Given
        $uri = '/api/v1/ingredients/' . $this->flour->getSlug() . '/conversions/' . $this->spoonToGramme->getId();

        // When
        $this->request('DELETE', $uri);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'CONVERSION_REQUIRED_BY_INGREDIENT']], $this->jsonBody());

        // When : la cuillère se relierait à la tasse, qui ne mène à rien
        $this->request('PUT', $uri, ['startUnitSlug' => 'cuillere-a-soupe', 'endUnitSlug' => 'tasse', 'coefficient' => 10]);

        // Then : rien n'a changé
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'CONVERSION_REQUIRED_BY_INGREDIENT']], $this->jsonBody());
        $kept = $this->conversions()->findById($this->spoonToGramme->getId());
        $this->assertSame(['cuillere-a-soupe', 'gramme', 10.0], [$kept->getStartUnit()->getSlug(), $kept->getEndUnit()->getSlug(), $kept->getCoefficient()]);
    }

    public function testAPairAlreadyOwnedIsAConflictInBothDirectionsAndTwoIdenticalUnitsAreRefused(): void
    {
        // Given
        $uri = '/api/v1/ingredients/' . $this->flour->getSlug() . '/conversions';

        // When / Then
        $this->request('POST', $uri, ['startUnitSlug' => 'cuillere-a-soupe', 'endUnitSlug' => 'gramme', 'coefficient' => 12]);
        $this->assertSame([409, ['error' => ['message' => 'CONVERSION_ALREADY_EXISTS']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('POST', $uri, ['startUnitSlug' => 'gramme', 'endUnitSlug' => 'cuillere-a-soupe', 'coefficient' => 0.1]);
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->request('POST', $uri, ['startUnitSlug' => 'tasse', 'endUnitSlug' => 'tasse', 'coefficient' => 1]);
        $this->assertSame([422, ['error' => ['message' => 'CONVERSION_SAME_UNIT']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('POST', $uri, ['startUnitSlug' => 'tasse', 'endUnitSlug' => 'gramme', 'coefficient' => 0]);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->request('POST', $uri, ['startUnitSlug' => 'inconnue', 'endUnitSlug' => 'gramme', 'coefficient' => 3]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $this->conversions()->findByIngredient($this->flour));
    }

    public function testAUniversalConversionIsNeverTouchedByTheIngredientRoutes(): void
    {
        // Given
        $base = '/api/v1/ingredients/' . $this->flour->getSlug() . '/conversions/' . $this->kiloToGramme->getId();

        // When
        $this->request('PUT', $base, ['startUnitSlug' => 'kilo', 'endUnitSlug' => 'gramme', 'coefficient' => 999]);
        $putStatus = $this->client->getResponse()->getStatusCode();
        $this->request('DELETE', $base);

        // Then : inconnue pour cette route, intacte, universelle
        $this->assertSame([404, 404], [$putStatus, $this->client->getResponse()->getStatusCode()]);
        $universal = $this->conversions()->findById($this->kiloToGramme->getId());
        $this->assertSame([1000.0, null], [$universal->getCoefficient(), $universal->getIngredient()]);
    }

    public function testAnAdministratorHasNoPassThroughOnTheConversionsOfAnotherUser(): void
    {
        // Given
        $admin = $this->savedUser(admin: true);
        $uri = '/api/v1/ingredients/' . $this->flour->getSlug() . '/conversions';

        // When / Then : en lecture l'ingrédient d'un autre n'existe pas, en écriture c'est interdit
        $this->request('GET', $uri, null, $admin);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('POST', $uri, ['startUnitSlug' => 'tasse', 'endUnitSlug' => 'gramme', 'coefficient' => 125], $admin);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('PUT', $uri . '/' . $this->spoonToGramme->getId(), ['startUnitSlug' => 'cuillere-a-soupe', 'endUnitSlug' => 'gramme', 'coefficient' => 99], $admin);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', $uri . '/' . $this->spoonToGramme->getId(), null, $admin);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(10.0, $this->conversions()->findById($this->spoonToGramme->getId())->getCoefficient());
    }

    public function testTheRecipeSaysWhichRowDoesNotConvertAndTheNewConversionFixesIt(): void
    {
        // Given : la recette demande 2 tasses de farine, que rien ne relie au kilo
        $recipe = new RecipeBuilder()->withAuthor($this->user)->addRow(
            new RecipeRowBuilder()->withIngredient($this->flour)->withUnit(new UnitBuilder()->withName('tasse'))->withQuantity(2),
        )->build();
        $this->persister->saveRecipe($recipe);
        $this->request('GET', '/api/v1/recipes/' . $recipe->getId());
        $row = $this->jsonBody()['rows'][0];
        $this->assertSame(['tasse', 'tasse', 'kilo', 'kilo', false], [$row['unitSlug'], $row['unitName'], $row['purchaseUnitSlug'], $row['purchaseUnitName'], $row['convertible']]);

        // When : l'utilisateur dit combien pèse une tasse
        $this->request('POST', '/api/v1/ingredients/' . $this->flour->getSlug() . '/conversions', ['startUnitSlug' => 'tasse', 'endUnitSlug' => 'gramme', 'coefficient' => 125]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // Then : la ligne se convertit (tasse > gramme propre puis kilo > gramme universelle)
        $this->request('GET', '/api/v1/recipes/' . $recipe->getId());
        $this->assertTrue($this->jsonBody()['rows'][0]['convertible']);
    }

    public function testAConversionCreatedByTheFormIsCopiedWithTheIngredientOfACopiedRecipe(): void
    {
        // Given : l'utilisateur ajoute « 1 tasse = 125 g » à sa farine par le formulaire, puis une autre personne copie sa recette
        $recipe = new RecipeBuilder()->withAuthor($this->user)->addRow(
            new RecipeRowBuilder()->withIngredient($this->flour)->withUnit(new UnitBuilder()->withName('tasse'))->withQuantity(2),
        )->build();
        $this->persister->saveRecipe($recipe);
        $this->request('POST', '/api/v1/ingredients/' . $this->flour->getSlug() . '/conversions', ['startUnitSlug' => 'tasse', 'endUnitSlug' => 'gramme', 'coefficient' => 125]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copier = $this->savedUser();

        // When
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new CopyRecipeCommand($recipe->getId(), $copier->getId(), 'copy-id'));
        $this->entityManager->clear();

        // Then : la copie de la farine emporte la conversion, identifiant neuf, et la ligne de la recette copiée se convertit
        /** @var RecipeRow $row */
        $row = static::getContainer()->get(RecipeRepository::class)->findOneById('copy-id')->getRows()->reset();
        $copy = static::getContainer()->get(IngredientRepository::class)->findOneBySlug($row->getIngredient()->getSlug());
        $this->assertSame($copier->getId(), $copy->getCreatorId());
        $copied = static::getContainer()->get(ConversionRepository::class)->findByIngredient($copy);
        $this->assertEqualsCanonicalizing(['cuillere-a-soupe>gramme=10', 'tasse>gramme=125'], array_map(static fn(Conversion $c): string => $c->getStartUnit()->getSlug() . '>' . $c->getEndUnit()->getSlug() . '=' . $c->getCoefficient(), $copied->toArray()));
        $service = static::getContainer()->get(ConversionService::class);
        $service->clear();
        $this->assertEqualsWithDelta(0.25, $service->convertToPurchaseUnit($copy, 2, $row->getUnit()), 1e-12);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, ?array $body = null, ?User $as = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($as ?? $this->user);
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

    private function conversions(): ConversionRepository
    {
        $this->entityManager->clear();

        return static::getContainer()->get(ConversionRepository::class);
    }

    /**
     * @return list<string>
     */
    private function compatibleUnitSlugs(): array
    {
        $this->entityManager->clear();
        $slugs = array_map(
            static fn($unit): string => $unit->getSlug(),
            static::getContainer()->get(IngredientRepository::class)->findOneBySlug($this->flour->getSlug())->getCompatibleUnits()->toArray(),
        );
        sort($slugs);

        return $slugs;
    }
}
