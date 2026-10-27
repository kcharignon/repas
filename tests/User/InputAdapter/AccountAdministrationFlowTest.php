<?php

namespace Repas\Tests\User\InputAdapter;


use Doctrine\DBAL\Connection;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Interface\RayonVoteRepository;
use Repas\Drive\Domain\Model\RayonVote;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Domain\Event\UserDeletedEvent;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserAppVersionBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\Builder\UserIdentityBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\UserAppVersionRepository;
use Repas\User\Domain\Interface\UserIdentityRepository;
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
        $this->changeStatus($target, 'DISABLED', $this->login('admin@example.com'));

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
        // Then : plus de connexion ni de lien en attente
        $this->assertSame(0, $this->rowCount('refresh_token', 'user_id', $target->getId()));
        $this->assertNull(static::getContainer()->get(EmailVerificationTokenRepository::class)->findOneByUserId($target->getId()));
        $this->assertSame(UserStatus::DISABLED, $this->userStatus($target));
    }

    public function testADisabledAccountCanBeActivatedAgainAndLogsIn(): void
    {
        // Given
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com')->isDisabled());
        $token = $this->login('admin@example.com');

        // When
        $this->changeStatus($target, 'ACTIVE', $token);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(200, $this->statusOf('GET', '/api/v1/me', $this->login('target@example.com')));
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
        $this->changeStatus($target, 'ACTIVE', $this->login('admin@example.com'));

        // Then : il se connecte, et son ancien lien n'existe plus
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(200, $this->statusOf('GET', '/api/v1/me', $this->login('target@example.com')));
        $this->assertNull(static::getContainer()->get(EmailVerificationTokenRepository::class)->findOneByUserId($target->getId()));
    }

    public function testAnActiveAccountPutBackToPendingMustConfirmItsAddressAgain(): void
    {
        // Given : le compte est connecté
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com'));
        $jwt = $this->login('target@example.com');
        $refreshSecret = $this->refreshSecret();

        // When
        $this->changeStatus($target, 'PENDING', $this->login('admin@example.com'));

        // Then : ni le jeton d'accès, ni le cookie, ni le mot de passe n'ouvrent plus rien ; la confirmation est demandée
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(401, $this->statusOf('GET', '/api/v1/me', $jwt));
        $this->setRefreshSecret($refreshSecret);
        $this->client->request('POST', '/api/auth/refresh');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => 'target@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(UserStatus::PENDING, $this->userStatus($target));
    }

    public function testAPendingAccountCanBeDisabledDirectly(): void
    {
        // Given
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com')->isPending());

        // When
        $this->changeStatus($target, 'DISABLED', $this->login('admin@example.com'));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(UserStatus::DISABLED, $this->userStatus($target));
    }

    public function testSettingTheStatusTheAccountAlreadyHasChangesNothing(): void
    {
        // Given
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com'));

        // When
        $this->changeStatus($target, 'ACTIVE', $this->login('admin@example.com'));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(UserStatus::ACTIVE, $this->userStatus($target));
    }

    public function testAnAdministratorCanBeDisabledByAnotherOneAndEvenByHimself(): void
    {
        // Given
        $other = $this->savedUser(new UserBuilder()->withEmail('other-admin@example.com')->isAdmin());
        $token = $this->login('admin@example.com');
        $otherToken = $this->login('other-admin@example.com');

        // When : un administrateur en désactive un autre
        $this->changeStatus($other, 'DISABLED', $token);

        // Then : l'autre est coupé sur-le-champ
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(401, $this->statusOf('GET', '/api/v1/me', $otherToken));

        // When : l'administrateur se désactive lui-même
        $this->changeStatus($this->admin, 'DISABLED', $token);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(UserStatus::DISABLED, $this->userStatus($this->admin));
        $this->assertSame(401, $this->statusOf('GET', '/api/v1/me', $token));
    }

    public function testTheProtectedAccountIsUntouchable(): void
    {
        // Given : le compte protégé est un administrateur, son propriétaire, connecté
        $protected = $this->savedUser(new UserBuilder()->withEmail(User::PROTECTED_EMAIL)->isAdmin());
        $recipe = $this->savedRecipe($protected, new RecipeBuilder()->isPastaCarbonara());
        $protectedJwt = $this->login(User::PROTECTED_EMAIL);
        $token = $this->login('admin@example.com');

        // When : un autre administrateur le désactive, le remet en attente, le supprime
        foreach (['DISABLED', 'PENDING'] as $status) {
            $this->changeStatus($protected, $status, $token);

            // Then
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $status);
            $this->assertSame(['error' => ['message' => 'PROTECTED_ACCOUNT']], $this->jsonBody());
        }
        $this->request('DELETE', '/api/v1/users/' . $protected->getId(), $token);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'PROTECTED_ACCOUNT']], $this->jsonBody());

        // Then : le compte, ses données et sa connexion sont intacts
        $this->assertSame(UserStatus::ACTIVE, $this->userStatus($protected));
        $this->assertSame(1, $this->rowCount('user', 'id', $protected->getId()));
        $this->assertSame(1, $this->rowCount('recipe', 'id', $recipe->getId()));
        $this->assertSame(1, $this->rowCount('refresh_token', 'user_id', $protected->getId()));
        $this->assertSame(200, $this->statusOf('GET', '/api/v1/me', $protectedJwt));

        // When : on lui redonne le statut actif
        $this->changeStatus($protected, 'ACTIVE', $token);

        // Then : permis, sans effet
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(UserStatus::ACTIVE, $this->userStatus($protected));
    }

    public function testTheProtectedAccountCannotTouchItselfEither(): void
    {
        // Given
        $protected = $this->savedUser(new UserBuilder()->withEmail(User::PROTECTED_EMAIL)->isAdmin());
        $token = $this->login(User::PROTECTED_EMAIL);

        // When
        $this->changeStatus($protected, 'DISABLED', $token);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(UserStatus::ACTIVE, $this->userStatus($protected));
    }

    public function testAnAdministratorCannotDeleteHisOwnAccountButCanDeleteAnotherAdministrator(): void
    {
        // Given
        $other = $this->savedUser(new UserBuilder()->withEmail('other-admin@example.com')->isAdmin());
        $token = $this->login('admin@example.com');

        // When : il supprime son propre compte
        $this->request('DELETE', '/api/v1/users/' . $this->admin->getId(), $token);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'CANNOT_DELETE_OWN_ACCOUNT']], $this->jsonBody());
        $this->assertSame(1, $this->rowCount('user', 'id', $this->admin->getId()));

        // When : il supprime un autre administrateur
        $this->request('DELETE', '/api/v1/users/' . $other->getId(), $token);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rowCount('user', 'id', $other->getId()));
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

    public function testAnUnknownAccountIsNotFound(): void
    {
        // Given
        $token = $this->login('admin@example.com');

        // When
        $this->client->request('PUT', '/api/v1/users/unknown-id/status', content: json_encode(['status' => 'ACTIVE'], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('DELETE', '/api/v1/users/unknown-id', $token);

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnOrdinaryUserCannotManageAccounts(): void
    {
        // Given
        $this->savedUser(new UserBuilder()->withEmail('ordinary@example.com'));
        $target = $this->savedUser(new UserBuilder()->withEmail('target@example.com'));
        $token = $this->login('ordinary@example.com');

        // When
        $this->changeStatus($target, 'DISABLED', $token);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('DELETE', '/api/v1/users/' . $target->getId(), $token);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
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

        // Given : chacun a adopté le commun « beurre » (alias d'ingrédient, ADR 0028)
        $aliases = static::getContainer()->get(IngredientAliasRepository::class);
        $aliases->save(IngredientAlias::create($leaving->getId(), 'beurre', $butter->getSlug(), AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()));
        $aliases->save(IngredientAlias::create($staying->getId(), 'beurre', 'beurre' . $staying->getId(), AliasKind::ADOPTION, 'first-use', new \DateTimeImmutable()));
        // Given : chacun a écarté une paire d'ingrédients par « Pas le même » (préférence privée, ING-006)
        $distinct = static::getContainer()->get(IngredientDistinctRepository::class);
        $distinct->save(IngredientDistinctPair::create($leaving->getId(), 'beurre', 'farine', new \DateTimeImmutable()));
        $distinct->save(IngredientDistinctPair::create($staying->getId(), 'beurre', 'farine', new \DateTimeImmutable()));

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
        $this->assertSame(0, $this->rowCount('ingredient_alias', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('ingredient_alias', 'user_id', $staying->getId()));
        $this->assertSame(0, $this->rowCount('ingredient_distinct', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('ingredient_distinct', 'user_id', $staying->getId()));
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

    public function testADeletedAccountTakesItsDriveSettingsChoicesAndRayonVotesWithItAndLeavesTheOthers(): void
    {
        // Given : chacun a un magasin et des préférences de produits (acceptés et refusés), sur les mêmes produits
        $leaving = $this->savedUser(new UserBuilder()->withEmail('leaving@example.com'));
        $staying = $this->savedUser(new UserBuilder()->withEmail('staying@example.com'));
        $driveSettings = static::getContainer()->get(DriveSettingsRepository::class);
        $driveSettings->save(new DriveSettingsBuilder()->withUserId($leaving->getId())->build());
        $driveSettings->save(new DriveSettingsBuilder()->withUserId($staying->getId())->build());
        $products = [new ProductBuilder()->withRetailerProductId('1')->build(), new ProductBuilder()->withRetailerProductId('2')->build(), new ProductBuilder()->withRetailerProductId('3')->build()];
        static::getContainer()->get(ProductRepository::class)->saveAll($products);
        static::getContainer()->get(ChoiceRepository::class)->saveAll([
            new ChoiceBuilder()->withUserId($leaving->getId())->withIngredientSlug('farine')->withAccepted([$products[0]->getId(), $products[1]->getId()])->withRejected([$products[2]->getId()])->build(),
            new ChoiceBuilder()->withUserId($leaving->getId())->withIngredientSlug('sel')->withAccepted([$products[0]->getId()])->build(),
            new ChoiceBuilder()->withUserId($staying->getId())->withIngredientSlug('farine')->withAccepted([$products[0]->getId()])->withRejected([$products[2]->getId()])->build(),
        ]);
        $rayonVotes = static::getContainer()->get(RayonVoteRepository::class);
        $rayonVotes->record($leaving->getId(), Retailer::LECLERC, [new RayonVote('boulangerie', 4001), new RayonVote('epicerie', 4002)]);
        $rayonVotes->record($staying->getId(), Retailer::LECLERC, [new RayonVote('boulangerie', 4001)]);

        // When
        $this->request('DELETE', '/api/v1/users/' . $leaving->getId(), $this->login('admin@example.com'));

        // Then : ses réglages, ses préférences, leurs lignes et ses votes de rayons ont disparu, aucune ligne orpheline ; le reste est intact
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rowCount('drive_rayon_vote', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('drive_rayon_vote', 'user_id', $staying->getId()));
        $this->assertSame(0, $this->rowCount('drive_settings', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('drive_settings', 'user_id', $staying->getId()));
        $this->assertSame(0, $this->rowCount('drive_choice', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('drive_choice', 'user_id', $staying->getId()));
        $this->assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product'));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM drive_choice_product WHERE choice_id NOT IN (SELECT id FROM drive_choice)'));
        $this->assertSame(3, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM drive_product'));
    }

    public function testADeletedAccountTakesItsGoogleLinkWithItAndLeavesTheOthers(): void
    {
        // Given : chacun a un lien Google (sujets factices)
        $leaving = $this->savedUser(new UserBuilder()->withEmail('leaving@example.com'));
        $staying = $this->savedUser(new UserBuilder()->withEmail('staying@example.com'));
        $identities = static::getContainer()->get(UserIdentityRepository::class);
        $identities->save(new UserIdentityBuilder()->withUser($leaving)->withSubject('sujet-test-depart')->build());
        $identities->save(new UserIdentityBuilder()->withUser($staying)->withSubject('sujet-test-reste')->build());

        // When
        $this->request('DELETE', '/api/v1/users/' . $leaving->getId(), $this->login('admin@example.com'));

        // Then : son lien a disparu avec le compte, aucune ligne ne reste ; celui de l'autre compte est intact
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rowCount('user', 'id', $leaving->getId()));
        $this->assertSame(0, $this->rowCount('user_identity', 'user_id', $leaving->getId()));
        $this->assertSame(0, $this->rowCount('user_identity', 'subject', 'sujet-test-depart'));
        $this->assertSame(1, $this->rowCount('user_identity', 'user_id', $staying->getId()));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM user_identity WHERE user_id NOT IN (SELECT id FROM "user")'));
    }

    public function testADeletedAccountTakesItsAppVersionWithItAndLeavesTheOthers(): void
    {
        // Given : chacun a annoncé la version de son application (DRV-637)
        $leaving = $this->savedUser(new UserBuilder()->withEmail('leaving@example.com'));
        $staying = $this->savedUser(new UserBuilder()->withEmail('staying@example.com'));
        $appVersions = static::getContainer()->get(UserAppVersionRepository::class);
        $appVersions->save(new UserAppVersionBuilder()->withUser($leaving)->withVersion('0.1.8')->build());
        $appVersions->save(new UserAppVersionBuilder()->withUser($staying)->withVersion('0.1.10')->build());

        // When
        $this->request('DELETE', '/api/v1/users/' . $leaving->getId(), $this->login('admin@example.com'));

        // Then : sa ligne a disparu avec le compte ; celle de l'autre compte est intacte
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rowCount('user_app_version', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('user_app_version', 'user_id', $staying->getId()));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM user_app_version WHERE user_id NOT IN (SELECT id FROM "user")'));
    }

    public function testADeletedAccountTakesItsVoiceLinksTokensAndCodesWithItAndLeavesTheOthers(): void
    {
        // Given : chacun a une liaison avec Alexa, ses jetons et un code d'autorisation (valeurs factices)
        $leaving = $this->savedUser(new UserBuilder()->withEmail('leaving@example.com'));
        $staying = $this->savedUser(new UserBuilder()->withEmail('staying@example.com'));
        foreach ([$leaving, $staying] as $i => $user) {
            $this->connection()->insert('voice_link', ['id' => 'link-' . $i, 'user_id' => $user->getId(), 'created_at' => '2026-10-25 10:00:00']);
            $this->connection()->insert('voice_access_grant', ['access_hash' => 'access-' . $i, 'refresh_hash' => 'refresh-' . $i, 'link_id' => 'link-' . $i, 'user_id' => $user->getId(), 'expires_at' => '2026-10-25 11:00:00', 'refresh_expires_at' => '2026-11-25 10:00:00']);
            $this->connection()->insert('voice_authorization_code', ['code_hash' => 'code-' . $i, 'user_id' => $user->getId(), 'client_id' => 'repas-alexa', 'redirect_uri' => 'https://redirect.example.test/callback', 'code_challenge' => 'challenge-' . $i, 'expires_at' => '2026-10-25 10:10:00']);
        }

        // When
        $this->request('DELETE', '/api/v1/users/' . $leaving->getId(), $this->login('admin@example.com'));

        // Then : ses liaisons, ses jetons et ses codes ont disparu avec le compte, aucune ligne orpheline ; ceux de l'autre compte sont intacts
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->rowCount('voice_link', 'user_id', $leaving->getId()));
        $this->assertSame(0, $this->rowCount('voice_access_grant', 'link_id', 'link-0'));
        $this->assertSame(0, $this->rowCount('voice_access_grant', 'user_id', $leaving->getId()));
        $this->assertSame(0, $this->rowCount('voice_authorization_code', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('voice_link', 'user_id', $staying->getId()));
        $this->assertSame(1, $this->rowCount('voice_access_grant', 'link_id', 'link-1'));
        $this->assertSame(1, $this->rowCount('voice_authorization_code', 'user_id', $staying->getId()));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM voice_access_grant WHERE link_id NOT IN (SELECT id FROM voice_link)'));
    }

    public function testNothingIsDeletedWhenTheErasureFailsHalfway(): void
    {
        // Given : un compte avec des données, un lien Google, une version d'application et une liaison Alexa, et un écouteur qui échoue après la purge
        $leaving = $this->savedUser(new UserBuilder()->withEmail('leaving@example.com'));
        $recipe = $this->savedRecipe($leaving, new RecipeBuilder()->isPastaCarbonara());
        $list = $this->savedListPlanning($leaving, $recipe);
        static::getContainer()->get(UserIdentityRepository::class)->save(new UserIdentityBuilder()->withUser($leaving)->build());
        static::getContainer()->get(UserAppVersionRepository::class)->save(new UserAppVersionBuilder()->withUser($leaving)->build());
        $this->connection()->insert('voice_link', ['id' => 'link-leaving', 'user_id' => $leaving->getId(), 'created_at' => '2026-10-25 10:00:00']);
        $token = $this->login('admin@example.com');
        $this->client->disableReboot();
        static::getContainer()->get('event_dispatcher')->addListener(
            UserDeletedEvent::class,
            static function (): never { throw new \RuntimeException('Échec de la suppression.'); },
            -100,
        );

        // When
        $this->request('DELETE', '/api/v1/users/' . $leaving->getId(), $token);

        // Then : la requête échoue et rien n'a été supprimé, ni le compte ni ses données, ni son lien (même transaction)
        $this->assertSame(500, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->rowCount('user', 'id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('user_identity', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('user_app_version', 'user_id', $leaving->getId()));
        $this->assertSame(1, $this->rowCount('voice_link', 'user_id', $leaving->getId()));
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

    private function changeStatus(User $target, string $status, string $token): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/users/' . $target->getId() . '/status',
            content: json_encode(['status' => $status], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
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
