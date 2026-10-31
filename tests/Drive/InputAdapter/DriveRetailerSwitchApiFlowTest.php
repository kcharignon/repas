<?php

namespace Repas\Tests\Drive\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Drive\Infrastructure\Http\RequiresDrive;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * L'interrupteur par enseigne (`DRIVE_RETAILERS`, DRV-702) avec les vrais bus, la vraie base et les listeners : une enseigne éteinte
 * n'existe plus pour l'utilisateur (`404 DRIVE_RETAILER_DISABLED`, `retailers` vide), ses données restent en base et reparaissent quand
 * elle est rallumée, et rouvrir une liste envoyée reste permis quelle que soit l'enseigne.
 *
 * `ROUTES_WITH_A_RETAILER` est la liste explicite de toutes les routes du drive qui reçoivent une enseigne : une route nouvelle du
 * contexte `Drive` qui n'y est pas (ni dans `ROUTES_WITHOUT_A_RETAILER`) fait échouer `testEveryDriveRouteIsListed`, elle ne se glisse pas.
 */
class DriveRetailerSwitchApiFlowTest extends DatabaseTestCase
{
    /**
     * Route => [méthode, adresse avec ses paramètres, corps JSON ou null]. L'enseigne est dans le corps (écritures) ou dans la requête (lectures).
     *
     * @var array<string, array{string, string, ?array<string, mixed>}>
     */
    private const array ROUTES_WITH_A_RETAILER = [
        'api_save_drive_settings' => ['PUT', '/api/v1/drive/settings', ['retailer' => 'leclerc', 'store' => ['id' => '123456', 'baseUrl' => 'https://exemple.leclercdrive.fr/magasin-123456-123456']]],
        'api_list_choices' => ['GET', '/api/v1/drive/choices?retailer=leclerc', null],
        'api_save_product_choices' => ['PUT', '/api/v1/drive/choices', ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [['ingredientSlug' => 'farine', 'action' => 'HAVE_AT_HOME']]]],
        'api_replace_ingredient_preference' => ['PUT', '/api/v1/drive/choices/farine', ['retailer' => 'leclerc', 'storeId' => '123456', 'haveAtHome' => true, 'accepted' => [], 'rejected' => []]],
        'api_forget_choice' => ['DELETE', '/api/v1/drive/choices/farine?retailer=leclerc&storeId=123456', null],
        'api_get_drive_plan' => ['GET', '/api/v1/drive/plans/{list}?retailer=leclerc', null],
        'api_get_drive_estimate' => ['GET', '/api/v1/drive/estimates/{list}?retailer=leclerc', null],
        // DRV-703 : le drive habituel, la dernière enseigne et le retrait d'un drive
        'api_set_default_retailer' => ['PUT', '/api/v1/drive/default-retailer', ['retailer' => 'leclerc']],
        'api_remember_last_retailer' => ['PUT', '/api/v1/drive/last-retailer', ['retailer' => 'leclerc']],
        'api_remove_drive_settings' => ['DELETE', '/api/v1/drive/settings?retailer=leclerc', null],
    ];

    /**
     * Routes du drive qui ne reçoivent aucune enseigne, et pourquoi :
     * - `GET /drive/settings` lit toutes les enseignes d'un coup (`retailers` ne liste que les allumées) et répond `enabled: false` ;
     * - `POST /shopping-lists/{id}/send` envoie une liste, qui ne porte pas l'enseigne (ADR 0027 § 9) ;
     * - `POST /shopping-lists/{id}/reopen` (DRV-613) n'appelle rien de l'enseigne : une coupure ne fige pas les listes ;
     * - `POST /drive/plans/{id}/basket-lines` (DRV-745) coche des lignes d'une liste envoyée : elle ne porte pas l'enseigne, seule la liste compte.
     *
     * @var list<string>
     */
    private const array ROUTES_WITHOUT_A_RETAILER = ['api_get_drive_settings', 'api_send_shopping_list', 'api_reopen_shopping_list', 'api_mark_lines_in_basket'];

    private KernelBrowser $client;
    private ControlledDriveFeature $feature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testEveryDriveRouteIsListed(): void
    {
        // Given
        /** @var list<string> $driveRoutes */
        $driveRoutes = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (str_starts_with($controller, 'Repas\\Drive\\Infrastructure\\Http\\') || str_starts_with($route->getPath(), '/api/v1/drive/') || in_array($name, self::ROUTES_WITHOUT_A_RETAILER, true)) {
                $driveRoutes[] = (string) $name;
            }
        }

        // Then : chaque route du drive a été décidée, avec ou sans enseigne ; et chaque entrée des listes désigne une route qui existe
        /** @var list<string> $listed */
        $listed = [...array_map(strval(...), array_keys(self::ROUTES_WITH_A_RETAILER)), ...self::ROUTES_WITHOUT_A_RETAILER];
        sort($driveRoutes);
        sort($listed);
        $this->assertSame($listed, $driveRoutes, 'Une route du drive n\'est pas dans les listes de DriveRetailerSwitchApiFlowTest (avec ou sans enseigne), ou une entrée ne désigne plus de route.');
        // Toute route avec enseigne est derrière l'interrupteur global : l'enseigne ne se juge qu'après lui
        foreach (array_keys(self::ROUTES_WITH_A_RETAILER) as $name) {
            $controller = (string) static::getContainer()->get(RouterInterface::class)->getRouteCollection()->get($name)->getDefault('_controller');
            $this->assertNotSame([], new ReflectionClass(explode('::', $controller)[0])->getAttributes(RequiresDrive::class), "$name doit porter #[RequiresDrive]");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routesWithARetailer(): iterable
    {
        foreach (array_keys(self::ROUTES_WITH_A_RETAILER) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('routesWithARetailer')]
    public function testARouteWithAnEnabledRetailerAnswersAndWithADisabledOneAnswers404(string $route): void
    {
        // Given : un utilisateur avec un magasin, un ingrédient et une liste envoyée ; toutes les enseignes allumées
        $user = $this->userWithAStoreAndASentList($listId);
        $this->client->disableReboot();
        $feature = $this->feature(true);
        // et une préférence enregistrée (oublier une préférence qui n'existe pas serait un autre 404)
        $this->call('api_save_product_choices', $user, $listId);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When : l'enseigne est allumée
        $this->call($route, $user, $listId);

        // Then : la route répond (plan et estimation : 200 ; écritures : 204 ; jamais le 404 de l'enseigne)
        $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 204], $this->client->getResponse()->getContent());

        // When : l'enseigne est éteinte
        $feature->switchRetailers([]);
        $this->call($route, $user, $listId);

        // Then
        $this->assertSame([404, ['error' => ['message' => 'DRIVE_RETAILER_DISABLED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
    }

    public function testTheGlobalSwitchStillAnswersItsOwnCodeWhateverTheRetailers(): void
    {
        // Given : le drive coupé ET l'enseigne éteinte
        $user = $this->userWithAStoreAndASentList($listId);
        $this->client->disableReboot();
        $this->feature(false, []);

        // When / Then : le code du global ne change pas (l'application le connaît), il passe avant celui de l'enseigne
        foreach (array_keys(self::ROUTES_WITH_A_RETAILER) as $route) {
            $this->call($route, $user, $listId);
            $this->assertSame([404, ['error' => ['message' => 'DRIVE_DISABLED']]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()], $route);
        }
    }

    public function testNoRetailerIsListedInTheSettingsWhenNoneIsEnabledButTheSwitchStaysOn(): void
    {
        // Given
        $user = $this->userWithAStoreAndASentList($listId);
        $this->client->disableReboot();
        $feature = $this->feature(true, []);

        // When
        $this->request('GET', '/api/v1/drive/settings', $user);

        // Then : la fonction est allumée, aucune enseigne n'est proposée
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->jsonBody()['enabled']);
        $this->assertSame([], $this->jsonBody()['retailers']);

        // When : le drive coupé, rien d'autre ne change
        $feature->switchEnabled(false);
        $this->request('GET', '/api/v1/drive/settings', $user);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->jsonBody()['enabled']);
        $this->assertSame([], $this->jsonBody()['retailers']);
    }

    public function testTheDataOfADisabledRetailerStaysAndComesBackWhenItIsEnabledAgain(): void
    {
        // Given : un magasin et une préférence enregistrés, puis l'enseigne éteinte
        $user = $this->userWithAStoreAndASentList($listId);
        $this->client->disableReboot();
        $feature = $this->feature(true);
        $this->call('api_save_product_choices', $user, $listId);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $before = $this->rows();
        $feature->switchRetailers([]);

        // When : l'utilisateur essaie d'écrire, d'oublier, de remplacer pendant que l'enseigne est éteinte
        foreach (['api_save_drive_settings', 'api_save_product_choices', 'api_replace_ingredient_preference', 'api_forget_choice', 'api_set_default_retailer', 'api_remember_last_retailer', 'api_remove_drive_settings'] as $route) {
            $this->call($route, $user, $listId);
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), $route);
        }

        // Then : rien n'a bougé en base
        $this->assertSame($before, $this->rows());
        $this->assertSame(1, $before['settings']);
        $this->assertSame(1, $before['choices']);

        // When : l'enseigne est rallumée
        $feature->switchRetailers(null);
        $this->request('GET', '/api/v1/drive/choices?retailer=leclerc', $user);

        // Then : le magasin et la préférence sont retrouvés
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([['farine', true]], array_map(static fn(array $choice): array => [$choice['ingredientSlug'], $choice['haveAtHome']], $this->jsonBody()['member']));
        $this->request('GET', '/api/v1/drive/settings', $user);
        $this->assertSame('123456', $this->jsonBody()['store']['id']);
    }

    public function testReopeningASentListStaysAllowedWhateverTheRetailers(): void
    {
        // Given : une liste envoyée, aucune enseigne allumée
        $user = $this->userWithAStoreAndASentList($listId);
        $this->client->disableReboot();
        $this->feature(true, []);

        // When
        $this->request('POST', "/api/v1/shopping-lists/$listId/reopen", $user);

        // Then : 204, la liste est de nouveau active
        $this->assertSame(204, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(ShoppingListStatus::ACTIVE->value, $this->entityManager->getConnection()->fetchOne('SELECT status FROM shopping_list WHERE id = ?', [$listId]));
    }

    /**
     * Pose les interrupteurs du test (avant la première requête : un service déjà utilisé ne se remplace plus) ; ils se modifient ensuite.
     *
     * @param list<string>|null $retailers
     */
    private function feature(bool $enabled = true, ?array $retailers = null): ControlledDriveFeature
    {
        $this->feature = new ControlledDriveFeature($enabled, $retailers);
        static::getContainer()->set(DriveFeature::class, $this->feature);

        return $this->feature;
    }

    /**
     * Joue la route de ROUTES_WITH_A_RETAILER, la liste de l'utilisateur à la place de `{list}`.
     */
    private function call(string $route, User $user, string $listId): void
    {
        [$method, $uri, $body] = self::ROUTES_WITH_A_RETAILER[$route];
        $this->request($method, str_replace('{list}', $listId, $uri), $user, $body);
    }

    /**
     * @return array{settings: int, choices: int, preferences: int}
     */
    private function rows(): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            'settings' => (int) $connection->fetchOne('SELECT COUNT(*) FROM drive_settings'),
            'choices' => (int) $connection->fetchOne('SELECT COUNT(*) FROM drive_choice'),
            'preferences' => (int) $connection->fetchOne('SELECT COUNT(*) FROM drive_retailer_preference'),
        ];
    }

    /**
     * Un utilisateur avec un magasin chez l'enseigne, un ingrédient « farine » et une liste envoyée qui le porte.
     *
     * @param-out string $listId
     */
    private function userWithAStoreAndASentList(&$listId): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->withRetailer(Retailer::default())->build());
        new DataPersister(static::getContainer())->saveOwnedIngredient($user, 'farine');
        $list = new ShoppingListBuilder()->withOwner($user)->addIngredient(new IngredientBuilder()->isFlour()->withSlug('farine')->withCreator($user))->withStatus(ShoppingListStatus::SENT)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);
        $listId = $list->getId();

        return $user;
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
