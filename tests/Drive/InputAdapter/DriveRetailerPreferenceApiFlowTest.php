<?php

namespace Repas\Tests\Drive\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Application\DeleteUser\DeleteUserCommand;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Le drive habituel, la dernière enseigne utilisée, le retrait d'un drive et la sélection de l'enseigne (DRV-703) avec les vrais bus, la
 * vraie base et les listeners. Les deux enseignes du registre sont allumées (la production n'allume que la première, `DRIVE_RETAILERS`).
 *
 * Tous les identifiants sont inventés (UUID de test, magasin `123456`, hôtes factices ou adresse constante d'enseigne).
 */
class DriveRetailerPreferenceApiFlowTest extends DatabaseTestCase
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

    public function testWithoutAnyPreferenceTheSelectionIsTheOneOfBeforeWhenASingleRetailerIsEnabled(): void
    {
        // Given : la production d'aujourd'hui, une seule enseigne allumée, et aucune ligne de préférence
        $this->feature->switchRetailers(['leclerc']);
        $user = $this->savedUser();
        $listId = $this->savedSentList($user);

        // When / Then : aucun magasin, `NONE`, l'enseigne en vigueur est Leclerc comme avant, le plan répond `409 DRIVE_NOT_CONFIGURED`
        $this->request('GET', '/api/v1/drive/settings', $user);
        $body = $this->jsonBody();
        $this->assertSame(['mode' => 'NONE', 'retailer' => null, 'candidates' => []], $body['selection']);
        $this->assertSame(['leclerc', null, null, null], [$body['retailer'], $body['store'], $body['defaultRetailer'], $body['lastUsedRetailer']]);
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_NOT_CONFIGURED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('GET', "/api/v1/drive/estimates/$listId", $user);
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_NOT_CONFIGURED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // When / Then : un magasin enregistré, `AUTO`, le plan et l'estimation sans enseigne sont ceux de Leclerc
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->request('GET', '/api/v1/drive/settings', $user);
        $this->assertSame(['mode' => 'AUTO', 'retailer' => 'leclerc', 'candidates' => ['leclerc']], $this->jsonBody()['selection']);
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);
        $this->assertSame([200, 'leclerc'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer']]);
        $this->request('GET', "/api/v1/drive/estimates/$listId", $user);
        $this->assertSame([200, 'leclerc'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer']]);
        $this->assertSame(0, $this->preferenceRows());
    }

    public function testTheWholeJourneyOfAPersonWithTwoDrives(): void
    {
        // Given : un magasin chez chaque enseigne, Leclerc enregistré d'abord
        $user = $this->savedUser();
        $listId = $this->savedSentList($user);
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->age('leclerc', $user, '-2 days');

        // When / Then : sans préférence on demande, la première configurée est présélectionnée (R4)
        $this->request('GET', '/api/v1/drive/settings', $user);
        $body = $this->jsonBody();
        $this->assertSame(['mode' => 'ASK', 'retailer' => 'leclerc', 'candidates' => ['leclerc', 'auchan']], $body['selection']);
        $this->assertSame([null, null], [$body['defaultRetailer'], $body['lastUsedRetailer']]);
        $this->assertSame([false, false], array_column($body['retailers'], 'isDefault'));

        // When / Then : la dernière enseigne ouverte est présélectionnée
        $this->request('PUT', '/api/v1/drive/last-retailer', $user, ['retailer' => 'auchan']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->request('GET', '/api/v1/drive/settings', $user);
        $body = $this->jsonBody();
        $this->assertSame(['mode' => 'ASK', 'retailer' => 'auchan', 'candidates' => ['leclerc', 'auchan']], $body['selection']);
        $this->assertSame([null, 'auchan', 'auchan'], [$body['defaultRetailer'], $body['lastUsedRetailer'], $body['retailer']]);
        $this->assertSame(self::AUCHAN_STORE, $body['store']['id']);

        // When / Then : le drive habituel est présélectionné avant la dernière utilisée, mais on demande toujours (R3 et R4, décision du 2026-10-30)
        $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => 'leclerc']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->request('GET', '/api/v1/drive/settings', $user);
        $body = $this->jsonBody();
        $this->assertSame(['mode' => 'ASK', 'retailer' => 'leclerc', 'candidates' => ['leclerc', 'auchan']], $body['selection']);
        $this->assertSame(['leclerc', 'auchan', 'leclerc'], [$body['defaultRetailer'], $body['lastUsedRetailer'], $body['retailer']]);
        $this->assertSame([true, false], array_column($body['retailers'], 'isDefault'));

        // When / Then : le plan et l'estimation sans enseigne suivent la sélection, avec `?retailer=` ils suivent la demande
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);
        $this->assertSame([200, 'leclerc', '123456'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer'], $this->jsonBody()['store']['id']]);
        $this->request('GET', "/api/v1/drive/plans/$listId?retailer=auchan", $user);
        $this->assertSame([200, 'auchan', self::AUCHAN_STORE], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer'], $this->jsonBody()['store']['id']]);
        $this->request('GET', "/api/v1/drive/estimates/$listId", $user);
        $this->assertSame([200, 'leclerc'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer']]);
        $this->request('GET', "/api/v1/drive/estimates/$listId?retailer=auchan", $user);
        $this->assertSame([200, 'auchan'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer']]);

        // When / Then : sans drive habituel on demande de nouveau, la dernière utilisée reste là ; le plan suit la présélection
        $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => null]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->request('GET', '/api/v1/drive/settings', $user);
        $body = $this->jsonBody();
        $this->assertSame(['mode' => 'ASK', 'retailer' => 'auchan', 'candidates' => ['leclerc', 'auchan']], $body['selection']);
        $this->assertSame([null, 'auchan'], [$body['defaultRetailer'], $body['lastUsedRetailer']]);
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);
        $this->assertSame([200, 'auchan'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer']]);
        $this->assertSame(1, $this->preferenceRows());
    }

    public function testADriveWithoutAStoreCannotBeTheDefaultNorTheLastRetailer(): void
    {
        // Given : un magasin chez Leclerc seulement
        $user = $this->savedUser();
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);

        // When / Then : `422 DRIVE_RETAILER_NOT_CONFIGURED` pour chacune des deux écritures, rien n'est écrit
        $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => 'auchan']);
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_RETAILER_NOT_CONFIGURED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('PUT', '/api/v1/drive/last-retailer', $user, ['retailer' => 'auchan']);
        $this->assertSame([422, ['error' => ['message' => 'DRIVE_RETAILER_NOT_CONFIGURED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertSame(0, $this->preferenceRows());

        // When / Then : une autre personne n'a pas non plus le magasin de la première
        $other = $this->savedUser();
        $this->request('PUT', '/api/v1/drive/default-retailer', $other, ['retailer' => 'leclerc']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->preferenceRows());
    }

    public function testTheUserNeverReadsNorWritesThePreferenceOfAnother(): void
    {
        // Given : Alice a deux drives et un drive habituel
        $alice = $this->savedUser();
        $mallory = $this->savedUser();
        foreach ([$alice, $mallory] as $user) {
            $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
            $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        }
        $this->request('PUT', '/api/v1/drive/default-retailer', $alice, ['retailer' => 'auchan']);

        // When : Mallory désigne Alice dans le corps de sa demande
        $this->request('PUT', '/api/v1/drive/default-retailer', $mallory, ['userId' => $alice->getId(), 'retailer' => 'leclerc']);
        $this->request('GET', '/api/v1/drive/settings', $alice);

        // Then : le drive habituel d'Alice n'a pas bougé, Mallory a le sien
        $this->assertSame('auchan', $this->jsonBody()['defaultRetailer']);
        $this->request('GET', '/api/v1/drive/settings', $mallory);
        $this->assertSame('leclerc', $this->jsonBody()['defaultRetailer']);
        $this->assertSame(2, $this->preferenceRows());
    }

    public function testRemovingADriveKeepsItsChoicesAndProductsAndClearsWhatPointedToIt(): void
    {
        // Given : deux drives, des choix chez Auchan, Auchan est à la fois le drive habituel et la dernière enseigne
        $user = $this->savedUser();
        $listId = $this->savedSentList($user);
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->request('PUT', '/api/v1/drive/choices', $user, ['retailer' => 'auchan', 'storeId' => self::AUCHAN_STORE, 'choices' => [[
            'ingredientSlug' => 'lait',
            'action' => 'ADD_PRODUCT',
            'placement' => 'PRIMARY',
            'product' => ['retailerProductId' => self::AUCHAN_PRODUCT, 'label' => 'Lait demi-écrémé 1 l', 'offerId' => self::AUCHAN_OFFER],
        ]]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => 'auchan']);
        $this->request('PUT', '/api/v1/drive/last-retailer', $user, ['retailer' => 'auchan']);

        // When
        $this->request('DELETE', '/api/v1/drive/settings?retailer=auchan', $user);

        // Then : le magasin n'est plus enregistré, le drive habituel et la dernière enseigne qui le désignaient sont effacés
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->request('GET', '/api/v1/drive/settings', $user);
        $body = $this->jsonBody();
        $this->assertSame([null, null], [$body['defaultRetailer'], $body['lastUsedRetailer']]);
        $this->assertSame(['mode' => 'AUTO', 'retailer' => 'leclerc', 'candidates' => ['leclerc']], $body['selection']);
        $this->assertSame([[ 'leclerc', true, false], ['auchan', false, false]], array_map(static fn(array $retailer): array => [$retailer['retailer'], $retailer['configured'], $retailer['isDefault']], $body['retailers']));
        $this->assertSame([0, 1], [(int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM drive_settings WHERE retailer = 'auchan'"), (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM drive_settings WHERE retailer = 'leclerc'")]);
        // et le plan sans enseigne est celui de la seule enseigne qui reste
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);
        $this->assertSame([200, 'leclerc'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer']]);

        // Then : ses choix et ses produits sont gardés ; la plan d'Auchan refuse (plus de magasin) mais les lignes sont là
        $this->assertSame([1, 1, 1], [
            (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM drive_choice WHERE retailer = 'auchan'"),
            (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'),
            (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM drive_product WHERE retailer = 'auchan'"),
        ]);
        $this->request('GET', "/api/v1/drive/plans/$listId?retailer=auchan", $user);
        $this->assertSame([409, ['error' => ['message' => 'DRIVE_NOT_CONFIGURED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);

        // When : la personne remet son magasin Auchan, ses choix reparaissent
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->request('GET', '/api/v1/drive/choices?retailer=auchan', $user);

        // Then
        $this->assertSame([self::AUCHAN_PRODUCT], array_map(static fn(array $accepted): string => $accepted['product']['retailerProductId'], $this->jsonBody()['member'][0]['accepted']));
    }

    public function testRemovingADriveThatIsNotThePreferredOneKeepsThePreference(): void
    {
        // Given : le drive habituel est Leclerc, la dernière enseigne Auchan
        $user = $this->savedUser();
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => 'leclerc']);
        $this->request('PUT', '/api/v1/drive/last-retailer', $user, ['retailer' => 'auchan']);

        // When : on retire Auchan
        $this->request('DELETE', '/api/v1/drive/settings?retailer=auchan', $user);

        // Then : seul ce qui désignait Auchan est effacé
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/drive/settings', $user);
        $this->assertSame(['leclerc', null], [$this->jsonBody()['defaultRetailer'], $this->jsonBody()['lastUsedRetailer']]);
    }

    public function testRemovingADriveThatWasNeverConfiguredIsNotFoundAndAnotherPersonsDriveIsUntouched(): void
    {
        // Given : Alice a un magasin Leclerc, pas Bob
        $alice = $this->savedUser();
        $bob = $this->savedUser();
        $this->saveSettings($alice, 'leclerc', '123456', self::LECLERC_URL);

        // When / Then : Bob ne retire pas le magasin d'Alice, il n'en a pas
        $this->request('DELETE', '/api/v1/drive/settings?retailer=leclerc', $bob);
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_SETTINGS_NOT_FOUND']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_settings'));

        // When / Then : Alice le retire une fois, la seconde fois il n'existe plus
        $this->request('DELETE', '/api/v1/drive/settings?retailer=leclerc', $alice);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/drive/settings?retailer=leclerc', $alice);
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_SETTINGS_NOT_FOUND']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
    }

    public function testADisabledDefaultRetailerIsIgnoredNeverAnErrorAndComesBackWhenEnabledAgain(): void
    {
        // Given : deux drives, Auchan est le drive habituel
        $user = $this->savedUser();
        $listId = $this->savedSentList($user);
        $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
        $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
        $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => 'auchan']);
        $this->request('PUT', '/api/v1/drive/last-retailer', $user, ['retailer' => 'auchan']);

        // When : Auchan est éteinte (`DRIVE_RETAILERS=leclerc`)
        $this->feature->switchRetailers(['leclerc']);
        $this->request('GET', '/api/v1/drive/settings', $user);

        // Then : la sélection retombe sur la seule enseigne allumée, la préférence n'est pas montrée mais reste enregistrée
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();
        $this->assertSame(['mode' => 'AUTO', 'retailer' => 'leclerc', 'candidates' => ['leclerc']], $body['selection']);
        $this->assertSame([null, null, 'leclerc'], [$body['defaultRetailer'], $body['lastUsedRetailer'], $body['retailer']]);
        $this->assertSame(['leclerc'], array_column($body['retailers'], 'retailer'));
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);
        $this->assertSame([200, 'leclerc'], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()['retailer']]);
        $this->assertSame('auchan', $this->entityManager->getConnection()->fetchOne("SELECT default_retailer FROM drive_retailer_preference WHERE user_id = ?", [$user->getId()]));

        // When : écrire pour une enseigne éteinte répond `404 DRIVE_RETAILER_DISABLED`, rien ne bouge
        $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => 'auchan']);
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_RETAILER_DISABLED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->request('PUT', '/api/v1/drive/last-retailer', $user, ['retailer' => 'auchan']);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/drive/settings?retailer=auchan', $user);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM drive_settings WHERE retailer = 'auchan'"));

        // When : on efface le drive habituel d'une enseigne éteinte en l'effaçant (null), puis Auchan est rallumée
        $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => null]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->feature->switchRetailers(['leclerc', 'auchan']);
        $this->request('GET', '/api/v1/drive/settings', $user);

        // Then : plus de drive habituel, la dernière enseigne est retrouvée
        $body = $this->jsonBody();
        $this->assertSame([null, 'auchan', 'ASK', 'auchan'], [$body['defaultRetailer'], $body['lastUsedRetailer'], $body['selection']['mode'], $body['selection']['retailer']]);
    }

    public function testTheDeletionOfTheAccountErasesThePreferenceAndOnlyHis(): void
    {
        // Given : deux comptes, chacun avec ses deux drives, son drive habituel et sa dernière enseigne
        $departing = $this->savedUser();
        $staying = $this->savedUser();
        foreach ([$departing, $staying] as $user) {
            $this->saveSettings($user, 'leclerc', '123456', self::LECLERC_URL);
            $this->saveSettings($user, 'auchan', self::AUCHAN_STORE, self::AUCHAN_URL);
            $this->request('PUT', '/api/v1/drive/default-retailer', $user, ['retailer' => 'leclerc']);
            $this->request('PUT', '/api/v1/drive/last-retailer', $user, ['retailer' => 'auchan']);
        }
        $this->assertSame(2, $this->preferenceRows());

        // When : l'administrateur supprime le compte (la vraie suppression, puis la purge du contexte)
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new DeleteUserCommand($departing->getId(), $staying->getId()));

        // Then : sa ligne de préférence a disparu avec ses réglages, celle de l'autre compte reste
        $connection = $this->entityManager->getConnection();
        $this->assertSame([], $connection->fetchFirstColumn('SELECT user_id FROM drive_retailer_preference WHERE user_id = ?', [$departing->getId()]));
        $this->assertSame([], $connection->fetchFirstColumn('SELECT user_id FROM drive_settings WHERE user_id = ?', [$departing->getId()]));
        $this->assertSame([$staying->getId()], $connection->fetchFirstColumn('SELECT user_id FROM drive_retailer_preference'));
    }

    /**
     * Antidate l'enregistrement du réglage d'une enseigne (le test les crée dans la même seconde).
     */
    private function age(string $retailer, User $user, string $modifier): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE drive_settings SET updated_at = updated_at + CAST(:shift AS INTERVAL) WHERE user_id = :user AND retailer = :retailer',
            ['shift' => $modifier, 'user' => $user->getId(), 'retailer' => $retailer],
        );
    }

    private function preferenceRows(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM drive_retailer_preference');
    }

    private function saveSettings(User $user, string $retailer, string $storeId, string $baseUrl): void
    {
        $this->request('PUT', '/api/v1/drive/settings', $user, ['retailer' => $retailer, 'store' => ['id' => $storeId, 'label' => 'Ville-Test', 'baseUrl' => $baseUrl]]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    /**
     * Une liste envoyée au drive de lait, à l'utilisateur (son ingrédient `lait`).
     */
    private function savedSentList(User $owner): string
    {
        $ingredient = new IngredientBuilder()->isMilk()->withCreator($owner)->build();
        new DataPersister(static::getContainer())->saveIngredient($ingredient);
        $list = new ShoppingListBuilder()->withOwner($owner)->withStatus(ShoppingListStatus::SENT)->addIngredient($ingredient)->build();
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
