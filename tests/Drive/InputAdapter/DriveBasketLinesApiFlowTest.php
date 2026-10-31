<?php

namespace Repas\Tests\Drive\InputAdapter;

use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveFeature;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListStatus;
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

/**
 * Les lignes cochées et le drive (DRV-745) avec les vrais bus, la vraie base, le voter et les listeners : une ligne déjà cochée ne part pas
 * (plan, estimation, envoi), un article mis au panier coche sa ligne (`POST /drive/plans/{id}/basket-lines`), et la réouverture garde toutes
 * les coches. Les tests unitaires doublent le bus ; celui-ci prouve le câblage.
 */
class DriveBasketLinesApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testALineIsCheckedWhenTheDriveHasItInTheBasketAndNothingElseChanges(): void
    {
        // Given : une liste envoyée de lait et d'œufs, au repos
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        [$status, $version] = $this->statusAndVersion($listId);

        // When : le drive a mis le lait au panier
        $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait']], JSON_THROW_ON_ERROR));

        // Then : seule la ligne du lait est cochée, la liste reste envoyée à la même version
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertSame(['lait'], $this->checkedSlugs($listId));
        $this->assertSame([$status, $version], $this->statusAndVersion($listId));
    }

    public function testTheLastCheckedLineDoesNotEndTheList(): void
    {
        // Given
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        [$status, $version] = $this->statusAndVersion($listId);

        // When : tout est au panier
        $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait', 'oeuf']], JSON_THROW_ON_ERROR));

        // Then : « Terminer la liste » reste un geste explicite : la liste est toujours envoyée, à la même version
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['lait', 'oeuf'], $this->checkedSlugs($listId));
        $this->assertSame([$status, $version], $this->statusAndVersion($listId));
        $this->assertSame('SENT', $status);
    }

    public function testIsIdempotentAndIgnoresAnIngredientThatIsNotInTheList(): void
    {
        // Given
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        [$status, $version] = $this->statusAndVersion($listId);

        // When : le même lot deux fois, avec un slug que la liste ne connaît pas
        foreach ([1, 2] as $_) {
            $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait', 'farine']], JSON_THROW_ON_ERROR));
            $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        }

        // Then : jamais de décochage, jamais d'erreur
        $this->assertSame(['lait'], $this->checkedSlugs($listId));
        $this->assertSame([$status, $version], $this->statusAndVersion($listId));
    }

    public function testAnOldSlugChecksTheLineOfTheCurrentIngredient(): void
    {
        // Given : le client garde l'ancien slug du lait (ingrédient fusionné ou copie d'un ancien commun)
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        new DataPersister(static::getContainer())->saveIngredientAlias(IngredientAlias::create($user->getId(), 'ancien-lait', 'lait', AliasKind::MERGE, 'fusion', new DateTimeImmutable('2026-10-21 03:04:05')));

        // When
        $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['ancien-lait']], JSON_THROW_ON_ERROR));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['lait'], $this->checkedSlugs($listId));
    }

    public function testReopeningKeepsTheLinesCheckedBeforeTheSendingAndTheOnesTheDriveChecked(): void
    {
        // Given : une liste active de lait et d'œufs dont l'œuf est déjà coché, envoyée au drive
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::ACTIVE);
        $this->tick($listId, 'oeuf');
        $this->request('POST', "/api/v1/shopping-lists/$listId/send", $user);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());

        // When : le plan ne montre que le lait, le drive le met au panier, puis la liste est rouverte
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);
        $this->assertSame(['lait'], array_column($this->jsonBody()['lines'], 'ingredientSlug'));
        $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait']], JSON_THROW_ON_ERROR));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', "/api/v1/shopping-lists/$listId/reopen", $user);

        // Then : la liste est de nouveau active et rien n'est décoché (aucun article manquant ici)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('ACTIVE', $this->statusAndVersion($listId)[0]);
        $this->assertSame(['lait', 'oeuf'], $this->checkedSlugs($listId));
    }

    public function testAnArticleThatStayedOutOfTheBasketIsTheOneLeftUncheckedAfterReopening(): void
    {
        // Given
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::SENT);

        // When : seul le lait est au panier, la liste est rouverte
        $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait']], JSON_THROW_ON_ERROR));
        $this->request('POST', "/api/v1/shopping-lists/$listId/reopen", $user);

        // Then : les œufs, manquants au drive, sont les seuls à acheter
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['lait'], $this->checkedSlugs($listId));
    }

    public function testThePlanAndTheEstimateLeaveOutTheLinesAlreadyChecked(): void
    {
        // Given : une liste envoyée de lait et d'œufs, l'œuf coché ; un magasin enregistré
        $user = $this->savedUser();
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($user->getId())->build());
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        $this->tick($listId, 'oeuf');

        // When
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);
        $plan = $this->jsonBody();
        $this->request('GET', "/api/v1/drive/estimates/$listId", $user);
        $estimate = $this->jsonBody();

        // Then : la ligne cochée n'est ni dans le plan ni dans l'estimation, les comptes suivent
        $this->assertSame(['lait'], array_column($plan['lines'], 'ingredientSlug'));
        $this->assertSame(['known' => 0, 'new' => 1, 'haveAtHome' => 0], $plan['counts']);
        $this->assertSame(['lait'], array_column($estimate['lines'], 'ingredientSlug'));
        $this->assertSame(1, $estimate['estimatedLines'] + $estimate['unestimatedLines']);

        // When : le drive met le lait au panier : il ne reste plus rien à envoyer
        $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait']], JSON_THROW_ON_ERROR));
        $this->request('GET', "/api/v1/drive/plans/$listId", $user);

        // Then
        $this->assertSame([], $this->jsonBody()['lines']);
        $this->assertSame(['known' => 0, 'new' => 0, 'haveAtHome' => 0], $this->jsonBody()['counts']);
    }

    public function testSendingIsRefusedWhenEveryLineIsAlreadyChecked(): void
    {
        // Given : une liste active dont toutes les lignes sont déjà cochées
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::ACTIVE);
        $this->tick($listId);

        // When
        $this->request('POST', "/api/v1/shopping-lists/$listId/send", $user);

        // Then : rien à acheter, rien à envoyer ; la liste reste active
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'SHOPPING_LIST_EMPTY']], $this->jsonBody());
        $this->assertSame('ACTIVE', $this->statusAndVersion($listId)[0]);
    }

    public function testSendingIsAcceptedWhenOneLineRemainsToBuy(): void
    {
        // Given : le lait est coché, les œufs non
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::ACTIVE);
        $this->tick($listId, 'lait');

        // When
        $this->request('POST', "/api/v1/shopping-lists/$listId/send", $user);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('SENT', $this->statusAndVersion($listId)[0]);
    }

    public function testAListThatIsNotSentAnymoreIsRefusedWithAConflictAndNothingIsChecked(): void
    {
        // Given : la liste a été rouverte entre-temps (active), ou terminée
        $user = $this->savedUser();
        $active = $this->savedList($user, ShoppingListStatus::ACTIVE);
        $completed = $this->savedList($user, ShoppingListStatus::COMPLETED);

        // When / Then
        foreach ([$active, $completed] as $listId) {
            $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait']], JSON_THROW_ON_ERROR));
            $this->assertSame(409, $this->client->getResponse()->getStatusCode());
            $this->assertSame(['error' => ['message' => 'SHOPPING_LIST_NOT_SENT']], $this->jsonBody());
            $this->assertSame([], $this->checkedSlugs($listId));
        }
    }

    public function testTheListOfAnotherUserIsRefusedEvenToAnAdministrator(): void
    {
        // Given : la liste envoyée d'Alice ; Bob, utilisateur, et Admin, administrateur
        $alice = $this->savedUser();
        $bob = $this->savedUser();
        $admin = new UserBuilder()->isAdmin()->build();
        static::getContainer()->get(UserRepository::class)->save($admin);
        $listId = $this->savedList($alice, ShoppingListStatus::SENT);

        // When / Then : `403` pour l'un comme pour l'autre (le voter laisse passer l'administrateur, le cas d'usage ne le suit pas), rien n'est coché
        foreach ([$bob, $admin] as $other) {
            $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $other, json_encode(['ingredientSlugs' => ['lait']], JSON_THROW_ON_ERROR));
            $this->assertSame(403, $this->client->getResponse()->getStatusCode());
            $this->assertSame([], $this->checkedSlugs($listId));
        }
    }

    public function testAnUnknownListIsNotFound(): void
    {
        // When
        $this->request('POST', '/api/v1/drive/plans/inconnue/basket-lines', $this->savedUser(), json_encode(['ingredientSlugs' => ['lait']], JSON_THROW_ON_ERROR));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnInvalidBodyIsRefusedWithoutWritingAnything(): void
    {
        // Given
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::SENT);

        // When / Then
        $bodies = [
            'sans la clé' => [],
            'vide' => ['ingredientSlugs' => []],
            'pas une liste' => ['ingredientSlugs' => 'lait'],
            'un doublon' => ['ingredientSlugs' => ['lait', 'lait']],
            'un slug mal formé' => ['ingredientSlugs' => ['Lait Entier']],
            'un slug trop long' => ['ingredientSlugs' => [str_repeat('a', 256)]],
            'pas un texte' => ['ingredientSlugs' => [12]],
            'trop de slugs' => ['ingredientSlugs' => array_map(static fn(int $n): string => "ingredient-$n", range(1, 1001))],
        ];
        foreach ($bodies as $label => $body) {
            $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode($body, JSON_THROW_ON_ERROR));
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $label);
        }
        $this->assertSame([], $this->checkedSlugs($listId));
    }

    public function testAThousandSlugsAreAccepted(): void
    {
        // Given
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::SENT);

        // When : la borne est 1 000, atteinte
        $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait', ...array_map(static fn(int $n): string => "ingredient-$n", range(1, 999))]], JSON_THROW_ON_ERROR));

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['lait'], $this->checkedSlugs($listId));
    }

    public function testAnswers404DriveDisabledWhenTheSwitchIsOffAndRefusesAnAnonymousCall(): void
    {
        // Given
        $user = $this->savedUser();
        $listId = $this->savedList($user, ShoppingListStatus::SENT);
        static::getContainer()->set(DriveFeature::class, new ControlledDriveFeature(false));
        $this->client->disableReboot();

        // When
        $this->request('POST', "/api/v1/drive/plans/$listId/basket-lines", $user, json_encode(['ingredientSlugs' => ['lait']], JSON_THROW_ON_ERROR));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'DRIVE_DISABLED']], $this->jsonBody());
        $this->assertSame([], $this->checkedSlugs($listId));

        // When : sans jeton
        $this->client->request('POST', "/api/v1/drive/plans/$listId/basket-lines", content: '{"ingredientSlugs":["lait"]}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    /**
     * Une liste de lait et d'œufs (deux lignes non cochées) au statut demandé.
     */
    private function savedList(User $owner, ShoppingListStatus $status): string
    {
        $persister = new DataPersister(static::getContainer());
        $builder = new ShoppingListBuilder()->withOwner($owner)->withStatus($status);
        foreach ([new IngredientBuilder()->isMilk()->withCreator($owner)->build(), new IngredientBuilder()->isEgg()->withCreator($owner)->build()] as $ingredient) {
            $persister->saveIngredient($ingredient);
            $builder->addIngredient($ingredient);
        }
        $list = $builder->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    /**
     * Coche en SQL les lignes de la liste (toutes, ou celles de cet ingrédient) : c'est l'état d'avant l'envoi. L'identity map est vidée pour que
     * la requête suivante relise les lignes en base.
     */
    private function tick(string $listId, ?string $ingredientSlug = null): void
    {
        $this->connection()->executeStatement(
            'UPDATE shopping_list_row SET checked = true WHERE shopping_list = ?' . ($ingredientSlug === null ? '' : ' AND ingredient = ?'),
            $ingredientSlug === null ? [$listId] : [$listId, $ingredientSlug],
        );
        $this->entityManager->clear();
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * @return list<string> les slugs des lignes cochées de la liste, triés (lus en SQL : ni identity map, ni modèle)
     */
    private function checkedSlugs(string $listId): array
    {
        return $this->connection()->fetchFirstColumn('SELECT ingredient FROM shopping_list_row WHERE shopping_list = ? AND checked = true ORDER BY ingredient', [$listId]);
    }

    /**
     * @return array{string, int}
     */
    private function statusAndVersion(string $listId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT status, version FROM shopping_list WHERE id = ?', [$listId]);

        return [$row['status'], (int) $row['version']];
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
