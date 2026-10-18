<?php

namespace Repas\Tests\Drive\InputAdapter;


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
 * Plan d'envoi avec les vrais bus, la vraie base, le voter et les listeners : les préférences de l'agent (`PUT /drive/choices`)
 * se retrouvent dans le plan d'une vraie liste envoyée. Les tests unitaires du controller doublent le bus ; celui-ci prouve le
 * câblage.
 */
class DrivePlanApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testThePlanOfASentListReflectsThePreferencesOfTheUser(): void
    {
        // Given : du lait (litre) et des œufs (unité), le lait connu avec un produit de 50 cl et un refus
        $user = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        $this->request('PUT', '/api/v1/drive/choices', $user, json_encode(['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => 'lait', 'action' => 'ADD_PRODUCT', 'placement' => 'PRIMARY', 'product' => ['retailerProductId' => '555111', 'label' => 'Lait demi-écrémé 50 cl', 'content' => ['value' => 50, 'unit' => 'cl']]],
            ['ingredientSlug' => 'lait', 'action' => 'REJECT_PRODUCT', 'product' => ['retailerProductId' => '999000', 'label' => 'Lait entier']],
        ]], JSON_THROW_ON_ERROR));

        // When
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $plan = $this->jsonBody();
        $this->assertSame(['shoppingListId', 'listVersion', 'retailer', 'store', 'generatedAt', 'counts', 'lines'], array_keys($plan));
        $this->assertSame(['known' => 1, 'new' => 1, 'haveAtHome' => 0], $plan['counts']);
        $this->assertSame(['ingredientSlug', 'ingredientName', 'needed', 'status', 'products', 'rejected', 'searchQuery'], array_keys($plan['lines'][0]));
        $this->assertSame(['lait', 'oeuf'], array_column($plan['lines'], 'ingredientSlug'));
        $this->assertSame(['KNOWN', 'NEW'], array_column($plan['lines'], 'status'));
        $this->assertSame(['quantity' => 1, 'unitSymbol' => 'l', 'dimension' => 'VOLUME', 'baseFactor' => 1000], $plan['lines'][0]['needed']);
        $this->assertSame(
            [['rank' => 1, 'retailerProductId' => '555111', 'ean' => null, 'label' => 'Lait demi-écrémé 50 cl', 'content' => ['value' => 50, 'unit' => 'cl'], 'packs' => 2, 'packsStatus' => 'COMPUTED']],
            $plan['lines'][0]['products'],
        );
        $this->assertSame([['retailerProductId' => '999000', 'label' => 'Lait entier']], $plan['lines'][0]['rejected']);
    }

    public function testRefusesTheListsThatTheUserCannotSendToTheDrive(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $sent = $this->savedList($other, ShoppingListStatus::SENT);
        $active = $this->savedList($user, ShoppingListStatus::ACTIVE);

        // When / Then : pas de magasin
        $this->request('GET', '/api/v1/drive/plans/' . $this->savedList($user, ShoppingListStatus::SENT), $user);
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_NOT_CONFIGURED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // Given
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());

        // When / Then : liste non envoyée, liste d'un autre, liste inconnue
        $this->request('GET', "/api/v1/drive/plans/$active", $user);
        $this->assertSame([409, ['error' => ['message' => 'SHOPPING_LIST_NOT_SENT']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('GET', "/api/v1/drive/plans/$sent", $user);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/drive/plans/liste-inconnue', $user);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
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
        foreach ([new IngredientBuilder()->isMilk()->build(), new IngredientBuilder()->isEgg()->build()] as $ingredient) {
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
