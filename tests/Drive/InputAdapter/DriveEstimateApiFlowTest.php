<?php

namespace Repas\Tests\Drive\InputAdapter;


use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Estimation du coût avec les vrais bus, la vraie base, le voter et les listeners : les préférences et les prix de l'agent
 * (`PUT /drive/choices`) se retrouvent dans l'estimation d'une vraie liste. Les tests unitaires du controller doublent le bus ;
 * celui-ci prouve le câblage.
 */
class DriveEstimateApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testTheEstimateReflectsThePreferencesAndThePricesPostedByTheAgent(): void
    {
        // Given : du lait (1 l, produit de 50 cl à 0,95 €), de la farine (produit sans prix) et des œufs (aucun produit)
        $user = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        $listId = $this->savedList($user, ShoppingListStatus::ACTIVE);
        $this->request('PUT', '/api/v1/drive/choices', $user, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555111', 'label' => 'Lait demi-écrémé 50 cl', 'content' => ['value' => 50, 'unit' => 'cl'], 'price' => ['cents' => 95, 'perMeasure' => ['cents' => 190, 'unit' => 'l']]]],
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => ['retailerProductId' => '555222', 'label' => 'Lait entier 1 l', 'content' => ['value' => 1, 'unit' => 'l'], 'price' => ['cents' => 150]]],
            ['ingredientSlug' => 'farine', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555333', 'label' => 'Farine de blé T55 1 kg', 'content' => ['value' => 1, 'unit' => 'kg']]],
        ]], JSON_THROW_ON_ERROR));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('GET', "/api/v1/drive/estimates/$listId", $user);

        // Then : seul le produit de rang 1 compte, 2 x 0,95 € ; la farine n'a pas de prix, les œufs pas de produit
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $estimate = $this->jsonBody();
        $this->assertSame(['shoppingListId', 'retailer', 'store', 'estimatedTotalCents', 'estimatedLines', 'unestimatedLines', 'coverage', 'oldestPriceAt', 'newestPriceAt', 'lines', 'disclaimer'], array_keys($estimate));
        $this->assertSame([$listId, 'leclerc', 190, 1, 2, 0.3333], [$estimate['shoppingListId'], $estimate['retailer'], $estimate['estimatedTotalCents'], $estimate['estimatedLines'], $estimate['unestimatedLines'], $estimate['coverage']]);
        $this->assertSame("Estimation d'après les derniers prix vus ; le prix réel est celui du drive.", $estimate['disclaimer']);
        $this->assertSame($estimate['oldestPriceAt'], $estimate['newestPriceAt']);
        $this->assertEqualsWithDelta(time(), new DateTimeImmutable($estimate['newestPriceAt'])->getTimestamp(), 60);
        $this->assertSame(['ingredientSlug', 'ingredientName', 'productLabel', 'packs', 'unitPriceCents', 'subtotalCents', 'priceSeenAt', 'status'], array_keys($estimate['lines'][0]));
        $this->assertSame(['farine', 'lait', 'oeuf'], array_column($estimate['lines'], 'ingredientSlug'));
        $this->assertSame(['NO_PRICE', 'ESTIMATED', 'NO_PRODUCT'], array_column($estimate['lines'], 'status'));
        $this->assertSame(['Lait demi-écrémé 50 cl', 2, 95, 190], [$estimate['lines'][1]['productLabel'], $estimate['lines'][1]['packs'], $estimate['lines'][1]['unitPriceCents'], $estimate['lines'][1]['subtotalCents']]);
        $this->assertSame(['Farine de blé T55 1 kg', null, null, null], [$estimate['lines'][0]['productLabel'], $estimate['lines'][0]['unitPriceCents'], $estimate['lines'][0]['subtotalCents'], $estimate['lines'][0]['priceSeenAt']]);
    }

    public function testAHaveAtHomeIngredientIsLeftOutOfTheTotalAndOfTheCoverageAndTheListCanBeSent(): void
    {
        // Given : le lait est « j'en ai toujours », la liste est envoyée au drive
        $user = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        $this->request('PUT', '/api/v1/drive/choices', $user, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'HAVE_AT_HOME'],
        ]], JSON_THROW_ON_ERROR));

        // When
        $this->request('GET', "/api/v1/drive/estimates/$listId", $user);

        // Then : 2 lignes à acheter, aucune estimée
        $estimate = $this->jsonBody();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['NO_PRODUCT', 'HAVE_AT_HOME', 'NO_PRODUCT'], array_column($estimate['lines'], 'status'));
        $this->assertSame([0, 0, 2, 0, null, null], [$estimate['estimatedTotalCents'], $estimate['estimatedLines'], $estimate['unestimatedLines'], $estimate['coverage'], $estimate['oldestPriceAt'], $estimate['newestPriceAt']]);
    }

    public function testRefusesTheListsThatTheUserCannotEstimate(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $others = $this->savedList($other, ShoppingListStatus::ACTIVE);
        $own = $this->savedList($user, ShoppingListStatus::ACTIVE);

        // When / Then : pas de magasin
        $this->request('GET', "/api/v1/drive/estimates/$own", $user);
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_NOT_CONFIGURED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // Given
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());

        // When / Then : liste d'un autre, liste inconnue, sans jeton
        $this->request('GET', "/api/v1/drive/estimates/$others", $user);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/drive/estimates/liste-inconnue', $user);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', "/api/v1/drive/estimates/$own");
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAdministratorPassesTheVoterButNeverGetsTheEstimateOfAnotherUser(): void
    {
        // Given : un administrateur qui a un magasin, la liste d'un autre utilisateur
        $admin = new UserBuilder()->isAdmin()->build();
        static::getContainer()->get(UserRepository::class)->save($admin);
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($admin->getId())->build());
        $others = $this->savedList($this->savedUser(), ShoppingListStatus::ACTIVE);

        // When
        $this->request('GET', "/api/v1/drive/estimates/$others", $admin);

        // Then : le voter laisse passer l'administrateur, le loader refuse, et la réponse ne porte rien de la liste
        $this->assertSame([403, ['error' => ['message' => 'SHOPPING_LIST_FORBIDDEN']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function savedList(User $owner, ShoppingListStatus $status): string
    {
        $persister = new DataPersister(static::getContainer());
        $builder = new ShoppingListBuilder()->withOwner($owner)->withStatus($status);
        foreach ([new IngredientBuilder()->isMilk()->build(), new IngredientBuilder()->isFlour()->build(), new IngredientBuilder()->isEgg()->build()] as $ingredient) {
            $persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
        }
        $list = $builder->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    private function request(string $method, string $uri, User $user, ?string $content = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $content, server: [
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
}
