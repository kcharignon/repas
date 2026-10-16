<?php

namespace Repas\Tests\User\InputAdapter;


use Doctrine\DBAL\Connection;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Gestion des comptes par un administrateur (ADR 0021) avec les vrais bus, la vraie base et les vrais jetons : activer,
 * désactiver (plus aucun moyen de se reconnecter), supprimer (plus aucune trace, hors les copies faites par d'autres).
 */
class AccountAdministrationFlowTest extends DatabaseTestCase
{
    private const string PASSWORD = 'test';

    private KernelBrowser $client;
    private DataPersister $persister;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->admin = $this->savedUser(new UserBuilder()->withEmail('admin@example.com')->isAdmin());
    }

    public function testADisabledAccountCannotComeBackByAnyMeans(): void
    {
        // Given : le compte est connecté (jeton d'accès + cookie de rafraîchissement) et a un lien de confirmation en cours
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com'));
        $jwt = $this->login('target@example.com');
        $refreshSecret = $this->refreshSecret();
        $this->savedEmailVerificationToken($target, 'le-secret');
        $this->assertSame(200, $this->statusOf('GET', '/api/v1/me', $jwt));
        $this->assertSame(1, $this->rowCount('refresh_token', 'user_id', $target->getId()));

        // When : l'administrateur le désactive
        $this->request('POST', '/api/v1/users/' . $target->getId() . '/disable', $this->login('admin@example.com'));

        // Then : le jeton d'accès encore valable est refusé tout de suite
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(401, $this->statusOf('GET', '/api/v1/me', $jwt));
        // Then : le cookie ne s'échange plus
        $this->setRefreshSecret($refreshSecret);
        $this->client->request('POST', '/api/auth/refresh');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        // Then : le bon mot de passe ne connecte plus, sans dire pourquoi
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => 'target@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid credentials.']], $this->jsonBody());
        // Then : plus de connexion ni de lien en attente, et le lien ne réactive rien
        $this->assertSame(0, $this->rowCount('refresh_token', 'user_id', $target->getId()));
        $this->assertNull(static::getContainer()->get(EmailVerificationTokenRepository::class)->findOneByUserId($target->getId()));
        $this->assertSame(UserStatus::DISABLED, $this->userStatus($target));
    }

    public function testAPendingAccountActivatedByAnAdministratorCanLogIn(): void
    {
        // Given : un compte en attente ne peut pas se connecter
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com')->isPending());
        $this->savedEmailVerificationToken($target, 'le-secret');
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => 'target@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'EMAIL_NOT_VERIFIED']], $this->jsonBody());

        // When
        $this->request('POST', '/api/v1/users/' . $target->getId() . '/activate', $this->login('admin@example.com'));

        // Then : il se connecte, et son ancien lien n'existe plus
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(200, $this->statusOf('GET', '/api/v1/me', $this->login('target@example.com')));
        $this->assertNull(static::getContainer()->get(EmailVerificationTokenRepository::class)->findOneByUserId($target->getId()));
    }

    public function testAPendingAccountCanBeDisabledDirectly(): void
    {
        // Given
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com')->isPending());

        // When
        $this->request('POST', '/api/v1/users/' . $target->getId() . '/disable', $this->login('admin@example.com'));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(UserStatus::DISABLED, $this->userStatus($target));
    }

    public function testTheAdministrationListShowsTheStatusOfEachAccount(): void
    {
        // Given
        $this->savedUser(new UserBuilder()->withEmail('pending@example.com')->isPending());
        $this->savedUser(new UserBuilder()->withEmail('disabled@example.com')->isDisabled());

        // When
        $this->request('GET', '/api/v1/users', $this->login('admin@example.com'));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $statuses = array_column($this->jsonBody()['member'], 'status', 'email');
        $this->assertSame(['admin@example.com' => 'ACTIVE', 'disabled@example.com' => 'DISABLED', 'pending@example.com' => 'PENDING'], $statuses);
    }

    /**
    #[\PHPUnit\Framework\Attributes\DataProvider('impossibleTransitions')]
    public function testATransitionTheAccountCannotMakeIsAConflict(UserBuilder $target, string $action, UserStatus $unchanged): void
    {
        // Given
        $user = $this->savedUser($target->withEmail('target@example.com'));

        // When
        $this->request('POST', '/api/v1/users/' . $user->getId() . '/' . $action, $this->login('admin@example.com'));

        // Then
        $this->assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame($unchanged, $this->userStatus($user));
    }

    /**
     * @return iterable<string, array{UserBuilder, string, UserStatus}>
     */
    public static function impossibleTransitions(): iterable
    {
        yield 'activer un compte actif' => [new UserBuilder(), 'activate', UserStatus::ACTIVE];
        yield 'activer un compte désactivé' => [new UserBuilder()->isDisabled(), 'activate', UserStatus::DISABLED];
        yield 'désactiver un compte désactivé' => [new UserBuilder()->isDisabled(), 'disable', UserStatus::DISABLED];
    }

    public function testAnAdministratorCannotDisableOrDeleteHisOwnAccount(): void
    {
        // Given
        $token = $this->login('admin@example.com');

        foreach ([['POST', '/disable'], ['DELETE', '']] as [$method, $suffix]) {
            // When
            $this->request($method, '/api/v1/users/' . $this->admin->getId() . $suffix, $token);

            // Then
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $method);
            $this->assertSame(['error' => ['message' => 'CANNOT_MANAGE_OWN_ACCOUNT']], $this->jsonBody());
        }
        $this->assertSame(UserStatus::ACTIVE, $this->userStatus($this->admin));
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        // Given
        $token = $this->login('admin@example.com');

        foreach ([['POST', '/activate'], ['POST', '/disable'], ['DELETE', '']] as [$method, $suffix]) {
            // When
            $this->request($method, '/api/v1/users/unknown-id' . $suffix, $token);

            // Then
            $this->assertSame(404, $this->client->getResponse()->getStatusCode(), $method . $suffix);
        }
    }

    public function testAnOrdinaryUserCannotManageAccounts(): void
    {
        // Given
        $this->savedUser(new UserBuilder()->withEmail('ordinary@example.com'));
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com'));
        $token = $this->login('ordinary@example.com');

        foreach ([['POST', '/activate'], ['POST', '/disable'], ['DELETE', '']] as [$method, $suffix]) {
            // When
            $this->request($method, '/api/v1/users/' . $target->getId() . $suffix, $token);

            // Then
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), $method . $suffix);
        }
        $this->assertSame(UserStatus::ACTIVE, $this->userStatus($target));
    }

    public function testADeletedAccountLeavesNothingBehindExceptTheCopiesOthersMadeOfItsRecipes(): void
    {
        // Given : le compte à supprimer a une recette, un ingrédient personnalisé (et sa conversion) et une liste qui planifie la recette
        $leaving = $this->savedUser(new UserBuilder()->withEmail('leaving@example.com'));
        $butter = $this->savedCustomButter($leaving);
        $leavingRecipe = $this->savedRecipe($leaving, new RecipeBuilder()->isPastaCarbonara()->withName('Carbonara du départ'));
        $leavingList = $this->savedListPlanning($leaving, $leavingRecipe);
        $conversion = new ConversionBuilder()->isBlockToGrammeForButter()->withIngredient($butter)->build();
        $this->persister->saveConversion($conversion);
        $this->login('leaving@example.com');

        // Given : un autre compte en a copié la recette, et a ses propres données
        $staying = $this->savedUser(new UserBuilder()->withEmail('staying@example.com'));
        $copy = $this->savedRecipe($staying, new RecipeBuilder()->isPastaCarbonara()->withName('Carbonara du départ')->withOriginalId($leavingRecipe->getId()));
        $stayingRecipe = $this->savedRecipe($staying, new RecipeBuilder()->isSoftBoiledEggs());
        $stayingList = $this->savedListPlanning($staying, $stayingRecipe);

        // When
        $this->request('DELETE', '/api/v1/users/' . $leaving->getId(), $this->login('admin@example.com'));

        // Then : le compte et tout ce qu'il possédait ont disparu
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rowCount('user', 'id', $leaving->getId()));
        $this->assertSame(0, $this->rowCount('refresh_token', 'user_id', $leaving->getId()));
        $this->assertSame(0, $this->rowCount('recipe', 'id', $leavingRecipe->getId()));
        $this->assertSame(0, $this->rowCount('recipe_row', 'recipe', $leavingRecipe->getId()));
        $this->assertSame(0, $this->rowCount('shopping_list', 'id', $leavingList->getId()));
        foreach (['meal', 'shopping_list_row', 'shopping_list_ingredient'] as $table) {
            $this->assertSame(0, $this->rowCount($table, 'shopping_list', $leavingList->getId()), $table);
        }
        $this->assertSame(0, $this->rowCount('ingredient', 'slug', $butter->getSlug()));
        $this->assertSame(0, $this->rowCount('conversion', 'id', $conversion->getId()));
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => 'leaving@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());

        // Then : la copie reste, comme si elle était l'originale, et ce que l'autre compte possède n'a pas bougé
        $this->assertSame(1, $this->rowCount('recipe', 'id', $copy->getId()));
        $this->assertNull($this->connection()->fetchOne('SELECT orignal FROM recipe WHERE id = :id', ['id' => $copy->getId()]) ?: null);
        $this->assertSame(count($copy->getRows()), $this->rowCount('recipe_row', 'recipe', $copy->getId()));
        $this->assertSame(1, $this->rowCount('recipe', 'id', $stayingRecipe->getId()));
        $this->assertSame(1, $this->rowCount('shopping_list', 'id', $stayingList->getId()));
        $this->assertSame(count($stayingList->getMeals()), $this->rowCount('meal', 'shopping_list', $stayingList->getId()));

        // Then : un troisième compte la voit désormais parmi les recettes originales des autres
        $this->request('GET', '/api/v1/recipes/other-people', $this->login('admin@example.com'));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertContains($copy->getId(), array_column($this->jsonBody()['member'], 'id'));
    }

    public function testNothingIsDeletedWhenTheErasureFailsHalfway(): void
    {
        // Given : un compte avec des données, et un écouteur qui échoue après la purge
        $leaving = $this->savedUser(new UserBuilder()->withEmail('leaving@example.com'));
        $recipe = $this->savedRecipe($leaving, new RecipeBuilder()->isPastaCarbonara());
        $list = $this->savedListPlanning($leaving, $recipe);
        $token = $this->login('admin@example.com');
        $this->client->disableReboot();
        static::getContainer()->get('event_dispatcher')->addListener(
            UserDeletedEvent::class,
            static function (): never { throw new \RuntimeException('Échec de la suppression.'); },
            -100,
        );

        // When
        $this->request('DELETE', '/api/v1/users/' . $leaving->getId(), $token);

        // Then : la requête échoue et rien n'a été supprimé, ni le compte ni ses données
        $this->assertSame(500, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->rowCount('user', 'id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('recipe', 'id', $recipe->getId()));
        $this->assertSame(1, $this->rowCount('shopping_list', 'id', $list->getId()));
        $this->assertGreaterThan(0, $this->rowCount('meal', 'shopping_list', $list->getId()));
    }

    private function savedUser(UserBuilder $builder): User
    {
        $user = $builder->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function savedCustomButter(User $creator): Ingredient
    {
        $butter = new IngredientBuilder()->isButter()->withCreator($creator, true)->build();
        $this->persister->saveIngredient($butter);

        return $butter;
    }

    private function savedListPlanning(User $owner, Recipe $recipe): ShoppingList
    {
        $shoppingList = new ShoppingListBuilder()->withOwner($owner)->addRecipe($recipe)->build();
        foreach ($recipe->getRows() as $row) {
            $shoppingList->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $row->getQuantity());
        }
        static::getContainer()->get(ShoppingListRepository::class)->save($shoppingList);

        return $shoppingList;
    }

    private function savedEmailVerificationToken(User $user, string $secret): void
    {
        static::getContainer()->get(EmailVerificationTokenRepository::class)->add(
            EmailVerificationToken::issue(UuidGenerator::new(), $user->getId(), EmailVerificationToken::hash($secret), new \DateTimeImmutable()),
        );
    }

    private function userStatus(User $user): UserStatus
    {
        $this->entityManager->clear();

        return static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->getStatus();
    }

    private function login(string $email): string
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => $email, 'password' => self::PASSWORD], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $email);

        return $this->jsonBody()['token'];
    }

    private function request(string $method, string $uri, string $token): void
    {
        $this->client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    private function statusOf(string $method, string $uri, string $token): int
    {
        $this->request($method, $uri, $token);

        return $this->client->getResponse()->getStatusCode();
    }

    private function refreshSecret(): string
    {
        return $this->client->getCookieJar()->get('repas_refresh', '/api/auth')->getValue();
    }

    private function setRefreshSecret(string $secret): void
    {
        $this->client->getCookieJar()->set(new Cookie('repas_refresh', $secret, null, '/api/auth', 'localhost'));
    }

    private function rowCount(string $table, string $column, string $value): int
    {
        return (int) $this->connection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = :value', $this->connection()->quoteSingleIdentifier($table), $column),
            ['value' => $value],
        );
    }

    private function connection(): Connection
    {
        return static::getContainer()->get('doctrine')->getConnection();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
