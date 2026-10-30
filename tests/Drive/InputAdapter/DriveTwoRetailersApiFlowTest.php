<?php

namespace Repas\Tests\Drive\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Application\Command\PurgeUserDriveData\PurgeUserDriveDataCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Deux enseignes réelles du registre (Leclerc et Auchan, DRV-716) avec les vrais bus, la vraie base et les listeners : un réglage, des
 * produits et des choix de l'une ne se mélangent jamais à ceux de l'autre, le plan de chacune vient de ses seuls choix, la purge du
 * compte efface les deux, et une enseigne éteinte (`DRIVE_RETAILERS`) n'existe pas pour l'utilisateur.
 *
 * Tous les identifiants sont inventés (UUID de test, magasin `123456`, hôtes factices ou adresse constante d'enseigne).
 */
class DriveTwoRetailersApiFlowTest extends DatabaseTestCase
{
    private const string LECLERC_URL = 'https://exemple.leclercdrive.fr/magasin-123456-123456';
    private const string AUCHAN_URL = 'https://www.auchan.fr/';
    private const string AUCHAN_STORE = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc';
    private const string AUCHAN_PRODUCT = 'c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d';
    private const string AUCHAN_OFFER = '7d9e1f20-4a3b-4c5d-8e6f-0a1b2c3d4e5f';

    private KernelBrowser $client;
    private ControlledDriveFeature $feature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        // Le noyau est gardé entre les requêtes, sinon l'interrupteur posé serait perdu au redémarrage ; les deux enseignes sont allumées
        $this->client->disableReboot();
        $this->feature = new ControlledDriveFeature(true, ['leclerc', 'auchan']);
        static::getContainer()->set(DriveFeature::class, $this->feature);
    }

    public function testTheSettingsOfTheTwoRetailersAreKeptApart(): void
    {
        // Given
        $user = $this->savedUser();

        // When : le magasin de chaque enseigne
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/drive/settings', $user);

        // Then : l'enseigne par défaut garde son magasin au premier plan, chaque enseigne a le sien
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();
        $this->assertSame(['leclerc', '123456'], [$body['retailer'], $body['store']['id']]);
        $this->assertSame(
            [
                ['leclerc', 'Leclerc', true, '123456', self::LECLERC_URL],
                ['auchan', 'Auchan', true, self::AUCHAN_STORE, self::AUCHAN_URL],
            ],
            array_map(static fn(array $retailer): array => [$retailer['retailer'], $retailer['name'], $retailer['configured'], $retailer['store']['id'], $retailer['store']['baseUrl']], $body['retailers']),
        );

        // When : un magasin qui n'a pas la forme de l'enseigne (l'adresse de l'une chez l'autre, un identifiant de l'autre)
        $this->saveSettings($user, 'auchan', '123456', self::AUCHAN_URL);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::LECLERC_URL);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->saveSettings($user, 'leclerc', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // Then : rien n'a bougé
        $this->assertSame(
            [['auchan', self::AUCHAN_STORE], ['leclerc', '123456']],
            $this->entityManager->getConnection()->fetchAllNumeric('SELECT retailer, store_id FROM drive_settings ORDER BY retailer'),
        );
    }

    public function testTheProductsAndTheChoicesOfTheTwoRetailersNeverMixAndEachPlanComesFromItsOwnChoices(): void
    {
        // Given : un utilisateur avec un magasin chez chaque enseigne et une liste envoyée (lait et œufs)
        $user = $this->savedUser();
        $listId = $this->savedSentList($user);
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);

        // When : un produit de lait chez Leclerc, un autre chez Auchan (avec son offre : DRV-721)
        $this->saveChoices($user, 'leclerc', '123456', [$this->add('lait', '555111', 'Lait demi-écrémé 50 cl')]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->saveChoices($user, 'auchan', self::AUCHAN_STORE, [$this->add('lait', self::AUCHAN_PRODUCT, 'Lait demi-écrémé 1 l', self::AUCHAN_OFFER)]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : chaque enseigne ne liste que ses choix
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $user);
        $this->assertSame(['555111'], $this->acceptedProductIds());
        $this->assertArrayNotHasKey('offerId', $this->jsonBody()['member'][0]['accepted'][0]['product']);
        $this->request('GET', '/api/v1/drive/choices?retailer=auchan', $user);
        $this->assertSame([self::AUCHAN_PRODUCT], $this->acceptedProductIds());
        $this->assertSame(self::AUCHAN_OFFER, $this->jsonBody()['member'][0]['accepted'][0]['product']['offerId']);

        // Then : chaque plan se calcule à partir des seuls choix de son enseigne
        $this->request('GET', "/api/v1/drive/plans/$listId?retailer=leclerc", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $plan = $this->jsonBody();
        $this->assertSame(['leclerc', '123456', ['known' => 1, 'new' => 1, 'haveAtHome' => 0]], [$plan['retailer'], $plan['store']['id'], $plan['counts']]);
        $this->assertSame(['555111'], array_column($plan['lines'][0]['products'], 'retailerProductId'));
        $this->assertArrayNotHasKey('offerId', $plan['lines'][0]['products'][0]);

        $this->request('GET', "/api/v1/drive/plans/$listId?retailer=auchan", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $plan = $this->jsonBody();
        $this->assertSame(['auchan', self::AUCHAN_STORE, ['known' => 1, 'new' => 1, 'haveAtHome' => 0]], [$plan['retailer'], $plan['store']['id'], $plan['counts']]);
        $this->assertSame([self::AUCHAN_PRODUCT], array_column($plan['lines'][0]['products'], 'retailerProductId'));
        $this->assertSame(self::AUCHAN_OFFER, $plan['lines'][0]['products'][0]['offerId']);

        // Then : l'estimation d'une enseigne est celle de cette enseigne
        $this->request('GET', "/api/v1/drive/estimates/$listId?retailer=auchan", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('auchan', $this->jsonBody()['retailer']);

        // Then : la fiche d'un produit est rangée sous son enseigne et son magasin
        $this->assertSame(
            [['auchan', self::AUCHAN_STORE, self::AUCHAN_PRODUCT, self::AUCHAN_OFFER], ['leclerc', '123456', '555111', null]],
            $this->entityManager->getConnection()->fetchAllNumeric('SELECT retailer, store_id, retailer_product_id, retailer_offer_id FROM drive_product ORDER BY retailer'),
        );
        $this->assertSame([['auchan', self::AUCHAN_STORE], ['leclerc', '123456']], $this->entityManager->getConnection()->fetchAllNumeric('SELECT retailer, store_id FROM drive_choice ORDER BY retailer'));
    }

    public function testAProductOrAStoreOfTheOtherRetailerIsRefusedAndNothingIsSaved(): void
    {
        // Given
        $user = $this->savedUser();
        $this->savedSentList($user);
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);

        // When / Then : un produit Leclerc (numérique) n'entre pas dans un choix Auchan, ni un produit Auchan (UUID) dans un choix Leclerc
        $this->saveChoices($user, 'auchan', self::AUCHAN_STORE, [$this->add('lait', self::AUCHAN_PRODUCT, 'Lait 1 l'), $this->add('oeuf', '555111', 'Œufs x6')]);
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_CHOICE_INVALID']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->saveChoices($user, 'leclerc', '123456', [$this->add('lait', self::AUCHAN_PRODUCT, 'Lait 1 l')]);
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_CHOICE_INVALID']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        // Un identifiant d'offre qui n'a pas la forme de l'enseigne
        $this->saveChoices($user, 'auchan', self::AUCHAN_STORE, [$this->add('lait', self::AUCHAN_PRODUCT, 'Lait 1 l', '12345')]);
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_CHOICE_INVALID']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // When / Then : le magasin d'une enseigne n'est pas celui de l'autre
        $this->saveChoices($user, 'auchan', '123456', [$this->add('lait', self::AUCHAN_PRODUCT, 'Lait 1 l')]);
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_STORE_MISMATCH']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->saveChoices($user, 'leclerc', self::AUCHAN_STORE, [$this->add('lait', '555111', 'Lait 50 cl')]);
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_STORE_MISMATCH']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // Then : rien n'a été écrit
        $this->assertSame([0, 0], [
            (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_product'),
            (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice'),
        ]);
    }

    public function testForgettingAChoiceOfOneRetailerKeepsTheChoiceOfTheOther(): void
    {
        // Given : le même ingrédient choisi chez les deux enseignes
        $user = $this->savedUser();
        $this->savedSentList($user);
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->saveChoices($user, 'leclerc', '123456', [$this->add('lait', '555111', 'Lait demi-écrémé 50 cl')]);
        $this->saveChoices($user, 'auchan', self::AUCHAN_STORE, [$this->add('lait', self::AUCHAN_PRODUCT, 'Lait demi-écrémé 1 l', self::AUCHAN_OFFER)]);

        // When : la préférence Auchan est oubliée
        $this->request('DELETE', '/api/v1/drive/choices/lait?retailer=auchan&storeId=' . self::AUCHAN_STORE, $user);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/drive/choices?retailer=auchan', $user);
        $this->assertSame([], $this->jsonBody()['member']);
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $user);
        $this->assertSame(['555111'], $this->acceptedProductIds());
    }

    public function testThePurgeOfTheAccountErasesTheDataOfBothRetailersAndKeepsThePublicProducts(): void
    {
        // Given : deux comptes, chacun avec un réglage, des produits et des choix chez les deux enseignes
        $user = $this->savedUser();
        $other = $this->savedUser();
        $this->savedSentList($user);
        new DataPersister(static::getContainer())->saveOwnedIngredient($other, 'lait-autre');
        foreach ([[$user, 'lait'], [$other, 'lait-autre']] as [$account, $slug]) {
            $this->saveSettings($account, 'leclerc', '123456', self::LECLERC_URL);
            $this->saveSettings($account, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
            $this->saveChoices($account, 'leclerc', '123456', [$this->add($slug, '555111', 'Lait demi-écrémé 50 cl')]);
            $this->saveChoices($account, 'auchan', self::AUCHAN_STORE, [$this->add($slug, self::AUCHAN_PRODUCT, 'Lait demi-écrémé 1 l', self::AUCHAN_OFFER)]);
            $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        }
        $this->assertSame(['drive_settings' => 4, 'drive_choice' => 4, 'drive_choice_product' => 4, 'drive_product' => 2], $this->counts());

        // When
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new PurgeUserDriveDataCommand($user->getId()));

        // Then : tout ce qu'il possédait chez les deux enseignes est parti, l'autre compte garde le sien, les produits (faits publics) restent
        $this->assertSame(['drive_settings' => 2, 'drive_choice' => 2, 'drive_choice_product' => 2, 'drive_product' => 2], $this->counts());
        $connection = $this->entityManager->getConnection();
        $this->assertSame([], $connection->fetchFirstColumn('SELECT user_id FROM drive_settings WHERE user_id = ?', [$user->getId()]));
        $this->assertSame([], $connection->fetchFirstColumn('SELECT user_id FROM drive_choice WHERE user_id = ?', [$user->getId()]));
        $this->assertSame(['auchan', 'leclerc'], $connection->fetchFirstColumn('SELECT retailer FROM drive_settings WHERE user_id = ? ORDER BY retailer', [$other->getId()]));
    }

    public function testWithOnlyLeclercEnabledAuchanDoesNotExistForTheUser(): void
    {
        // Given : un compte réglé chez les deux enseignes, puis Auchan éteinte (`DRIVE_RETAILERS=leclerc`)
        $user = $this->savedUser();
        $listId = $this->savedSentList($user);
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->saveChoices($user, 'auchan', self::AUCHAN_STORE, [$this->add('lait', self::AUCHAN_PRODUCT, 'Lait demi-écrémé 1 l', self::AUCHAN_OFFER)]);
        $this->feature->switchRetailers(['leclerc']);
        $before = $this->counts();

        // When / Then : l'enseigne n'apparaît pas dans les réglages
        $this->request('GET', '/api/v1/drive/settings', $user);
        $this->assertSame(['leclerc'], array_column($this->jsonBody()['retailers'], 'retailer'));

        // When / Then : toutes ses routes répondent 404 DRIVE_RETAILER_DISABLED, sans rien écrire
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->assertDisabled();
        $this->saveChoices($user, 'auchan', self::AUCHAN_STORE, [$this->add('lait', self::AUCHAN_PRODUCT, 'Lait 1 l')]);
        $this->assertDisabled();
        $this->request('GET', '/api/v1/drive/choices?retailer=auchan', $user);
        $this->assertDisabled();
        $this->request('GET', "/api/v1/drive/plans/$listId?retailer=auchan", $user);
        $this->assertDisabled();
        $this->request('GET', "/api/v1/drive/estimates/$listId?retailer=auchan", $user);
        $this->assertDisabled();
        $this->request('DELETE', '/api/v1/drive/choices/lait?retailer=auchan&storeId=' . self::AUCHAN_STORE, $user);
        $this->assertDisabled();
        $this->assertSame($before, $this->counts());

        // When / Then : Leclerc continue de répondre
        $this->request('GET', "/api/v1/drive/plans/$listId?retailer=leclerc", $user);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // When : Auchan est rallumée, ses données étaient restées
        $this->feature->switchRetailers(['leclerc', 'auchan']);
        $this->request('GET', '/api/v1/drive/choices?retailer=auchan', $user);

        // Then
        $this->assertSame([self::AUCHAN_PRODUCT], $this->acceptedProductIds());
    }

    private function assertDisabled(): void
    {
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_RETAILER_DISABLED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $connection = $this->entityManager->getConnection();

        return array_combine(
            ['drive_settings', 'drive_choice', 'drive_choice_product', 'drive_product'],
            array_map(static fn(string $table): int => (int) $connection->fetchOne("SELECT COUNT(*) FROM $table"), ['drive_settings', 'drive_choice', 'drive_choice_product', 'drive_product']),
        );
    }

    /**
     * @return list<string>
     */
    private function acceptedProductIds(): array
    {
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return array_map(static fn(array $accepted): string => $accepted['product']['retailerProductId'], $this->jsonBody()['member'][0]['accepted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function add(string $slug, string $productId, string $label, ?string $offerId = null): array
    {
        return [
            'ingredientSlug' => $slug,
            'action' => 'ADD_PRODUCT',
            'placement' => 'PRIMARY',
            'product' => array_filter(['retailerProductId' => $productId, 'label' => $label, 'offerId' => $offerId], static fn($value): bool => $value !== null),
        ];
    }

    private function saveSettings(User $user, string $retailer, string $storeId, string $baseUrl): void
    {
        $this->request('PUT', '/api/v1/drive/settings', $user, ['retailer' => $retailer, 'store' => ['id' => $storeId, 'label' => 'Ville-Test', 'baseUrl' => $baseUrl]]);
    }

    /**
     * @param list<array<string, mixed>> $choices
     */
    private function saveChoices(User $user, string $retailer, string $storeId, array $choices): void
    {
        $this->request('PUT', '/api/v1/drive/choices', $user, ['retailer' => $retailer, 'storeId' => $storeId, 'choices' => $choices]);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    /**
     * Une liste envoyée au drive de lait et d'œufs, à l'utilisateur (ses deux ingrédients, `lait` et `oeuf`).
     */
    private function savedSentList(User $owner): string
    {
        $persister = new DataPersister(static::getContainer());
        $builder = new ShoppingListBuilder()->withOwner($owner)->withStatus(ShoppingListStatus::SENT);
        foreach ([new IngredientBuilder()->isMilk()->withCreator($owner)->build(), new IngredientBuilder()->isEgg()->withCreator($owner)->build()] as $ingredient) {
            $persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
        }
        $list = $builder->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
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
        $content = $this->client->getResponse()->getContent();

        return $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
