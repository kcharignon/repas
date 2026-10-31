<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Application\Command\PurgeUserData\PurgeUserDataCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Les types de liste de courses (ADR 0036, LST-002) de bout en bout, avec les vrais bus, la vraie base et la vraie sécurité : créer, rattacher
 * des rayons, lire, refuser (plafond, nom déjà pris, type d'un autre), supprimer sans rien perdre.
 */
class ListTypesApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $owner;
    private User $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->owner = $this->savedUser();
        $this->other = $this->savedUser();
        $this->admin = $this->savedUser(admin: true);
        foreach ([new DepartmentBuilder()->isMeat(), new DepartmentBuilder()->isCheese(), new DepartmentBuilder()->isMiscellaneous()] as $department) {
            $this->persister->saveDepartment($department->build());
        }
    }

    public function testAnAccountWithoutTypeHasAnEmptyList(): void
    {
        // When
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['member' => [], 'totalItems' => 0], $this->jsonBody());
    }

    public function testCreatesATypeWithACapitalLetterAndReturnsItsLocation(): void
    {
        // When
        $this->request('POST', '/api/v1/shopping-list-types', $this->owner, ['name' => 'boucherie']);

        // Then
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $location = $response->headers->get('Location');
        $this->assertMatchesRegularExpression('#^/api/v1/shopping-list-types/[0-9a-f-]{36}$#', $location);
        $this->request('GET', $location, $this->owner);
        $this->assertSame(['id' => basename($location), 'name' => 'Boucherie', 'departments' => [], 'ingredients' => []], $this->jsonBody());
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $this->assertSame(['member' => [['id' => basename($location), 'name' => 'Boucherie', 'departmentSlugs' => [], 'ingredientCount' => 0]], 'totalItems' => 1], $this->jsonBody());
    }

    public function testTheListIsSortedByCreationDateAndBelongsToItsOwnerOnly(): void
    {
        // Given : le premier créé est le plus ancien (la date a la précision de la seconde, on la fixe plutôt que d'attendre)
        $this->createdType($this->owner, 'Marché');
        $this->createdType($this->owner, 'Boucherie');
        $this->createdType($this->other, 'Poissonnerie');
        $this->entityManager->getConnection()->executeStatement("UPDATE shopping_list_type SET created_at = '2026-10-31 10:00:00' WHERE name = 'Marché'");
        $this->entityManager->getConnection()->executeStatement("UPDATE shopping_list_type SET created_at = '2026-10-31 11:00:00' WHERE name = 'Boucherie'");

        // When
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $mine = array_column($this->jsonBody()['member'], 'name');
        $this->request('GET', '/api/v1/shopping-list-types', $this->admin);
        $admins = $this->jsonBody()['member'];

        // Then : par date de création, et l'administrateur ne voit pas ceux des autres
        $this->assertSame(['Marché', 'Boucherie'], $mine);
        $this->assertSame([], $admins);
    }

    public function testRefusesTheEleventhTypeAndWritesNothing(): void
    {
        // Given
        foreach (range(1, 10) as $number) {
            $this->createdType($this->owner, "Magasin $number");
        }

        // When
        $this->request('POST', '/api/v1/shopping-list-types', $this->owner, ['name' => 'Onzième']);
        $refusal = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];
        // un autre compte n'est pas concerné
        $this->request('POST', '/api/v1/shopping-list-types', $this->other, ['name' => 'Onzième']);
        $other = $this->client->getResponse()->getStatusCode();

        // Then
        $this->assertSame([422, ['error' => ['message' => 'LIST_TYPE_LIMIT_REACHED']]], $refusal);
        $this->assertSame(201, $other);
        $this->assertSame(10, $this->typeCount($this->owner));
    }

    public function testRefusesTwoTypesOfTheSameNormalizedNameOfTheSameAccountOnly(): void
    {
        // Given
        $this->createdType($this->owner, 'Marché');

        // When
        $this->request('POST', '/api/v1/shopping-list-types', $this->owner, ['name' => 'MARCHE']);
        $refusal = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];
        $this->request('POST', '/api/v1/shopping-list-types', $this->other, ['name' => 'Marché']);

        // Then
        $this->assertSame([409, ['error' => ['message' => 'LIST_TYPE_NAME_ALREADY_USED']]], $refusal);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->typeCount($this->owner));
    }

    public function testRefusesAnInvalidNameWithNothingWritten(): void
    {
        // When & Then
        foreach ([['name' => ''], ['name' => str_repeat('a', 41)], ['name' => '???'], []] as $body) {
            $this->request('POST', '/api/v1/shopping-list-types', $this->owner, $body);
            $this->assertSame(422, $this->client->getResponse()->getStatusCode(), json_encode($body));
        }
        $this->assertSame(0, $this->typeCount($this->owner));
    }

    public function testAttachesDepartmentsAndReadsThemWithTheirNames(): void
    {
        // Given
        $id = $this->createdType($this->owner, 'Boucherie');

        // When
        $this->request('PUT', '/api/v1/shopping-list-types/' . $id, $this->owner, ['name' => 'Boucherie fine', 'departmentSlugs' => ['viande', 'fromage']]);
        $put = $this->client->getResponse()->getStatusCode();
        $this->request('GET', '/api/v1/shopping-list-types/' . $id, $this->owner);
        $detail = $this->jsonBody();
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $list = $this->jsonBody()['member'][0];

        // Then
        $this->assertSame(204, $put);
        $this->assertSame(['Boucherie fine', [['slug' => 'fromage', 'name' => 'fromage'], ['slug' => 'viande', 'name' => 'viande']]], [$detail['name'], $detail['departments']]);
        $this->assertSame(['fromage', 'viande'], $list['departmentSlugs']);
    }

    public function testADepartmentAlreadyTakenByAnotherTypeIsMovedAndTheSetIsReplacedAsAWhole(): void
    {
        // Given
        $butcher = $this->createdType($this->owner, 'Boucherie');
        $market = $this->createdType($this->owner, 'Marché');
        $this->request('PUT', '/api/v1/shopping-list-types/' . $butcher, $this->owner, ['name' => 'Boucherie', 'departmentSlugs' => ['viande', 'fromage']]);

        // When : « Marché » prend « viande », puis « Boucherie » est remplacée par un jeu vide
        $this->request('PUT', '/api/v1/shopping-list-types/' . $market, $this->owner, ['name' => 'Marché', 'departmentSlugs' => ['viande', 'divers']]);
        $afterMove = $this->departmentsByType();
        $this->request('PUT', '/api/v1/shopping-list-types/' . $butcher, $this->owner, ['name' => 'Boucherie', 'departmentSlugs' => []]);
        $afterReplace = $this->departmentsByType();

        // Then
        $this->assertSame(['Boucherie' => ['fromage'], 'Marché' => ['divers', 'viande']], $afterMove);
        $this->assertSame(['Boucherie' => [], 'Marché' => ['divers', 'viande']], $afterReplace);
    }

    public function testAnotherUserKeepsItsOwnDepartmentsWhenTheSameDepartmentIsAttachedByMe(): void
    {
        // Given
        $mine = $this->createdType($this->owner, 'Boucherie');
        $theirs = $this->createdType($this->other, 'Boucherie');
        $this->request('PUT', '/api/v1/shopping-list-types/' . $theirs, $this->other, ['name' => 'Boucherie', 'departmentSlugs' => ['viande']]);

        // When
        $this->request('PUT', '/api/v1/shopping-list-types/' . $mine, $this->owner, ['name' => 'Boucherie', 'departmentSlugs' => ['viande']]);

        // Then
        $this->request('GET', '/api/v1/shopping-list-types/' . $theirs, $this->other);
        $this->assertSame(['viande'], array_column($this->jsonBody()['departments'], 'slug'));
    }

    public function testAnUnknownDepartmentIsNotFoundAndNothingIsWritten(): void
    {
        // Given
        $id = $this->createdType($this->owner, 'Boucherie');
        $this->request('PUT', '/api/v1/shopping-list-types/' . $id, $this->owner, ['name' => 'Boucherie', 'departmentSlugs' => ['viande']]);

        // When
        $this->request('PUT', '/api/v1/shopping-list-types/' . $id, $this->owner, ['name' => 'Charcuterie', 'departmentSlugs' => ['fromage', 'inconnu']]);

        // Then : ni le nom ni les rayons n'ont bougé
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/shopping-list-types/' . $id, $this->owner);
        $this->assertSame(['Boucherie', ['viande']], [$this->jsonBody()['name'], array_column($this->jsonBody()['departments'], 'slug')]);
    }

    public function testRefusesANameTakenByAnotherTypeOfTheAccountButNotItsOwnName(): void
    {
        // Given
        $butcher = $this->createdType($this->owner, 'Boucherie');
        $this->createdType($this->owner, 'Marché');

        // When
        $this->request('PUT', '/api/v1/shopping-list-types/' . $butcher, $this->owner, ['name' => 'marche', 'departmentSlugs' => ['viande']]);
        $refusal = [$this->client->getResponse()->getStatusCode(), $this->jsonBody()];
        $this->request('PUT', '/api/v1/shopping-list-types/' . $butcher, $this->owner, ['name' => 'boucherie', 'departmentSlugs' => ['viande']]);

        // Then
        $this->assertSame([409, ['error' => ['message' => 'LIST_TYPE_NAME_ALREADY_USED']]], $refusal);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/shopping-list-types/' . $butcher, $this->owner);
        $this->assertSame('Boucherie', $this->jsonBody()['name']);
    }

    public function testTheTypeOfAnotherUserIsUnknownToReadAndForbiddenToWriteAdministratorIncluded(): void
    {
        // Given
        $theirs = $this->createdType($this->other, 'Boucherie');
        $unknown = '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34';

        foreach ([$this->owner, $this->admin] as $caller) {
            // When
            $this->request('GET', '/api/v1/shopping-list-types/' . $theirs, $caller);
            $read = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];
            $this->request('GET', '/api/v1/shopping-list-types/' . $unknown, $caller);
            $readUnknown = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];
            $this->request('PUT', '/api/v1/shopping-list-types/' . $theirs, $caller, ['name' => 'Volé', 'departmentSlugs' => ['viande']]);
            $put = $this->client->getResponse()->getStatusCode();
            $this->request('DELETE', '/api/v1/shopping-list-types/' . $theirs, $caller);
            $delete = $this->client->getResponse()->getStatusCode();

            // Then : lire est `404` (la même réponse qu'un identifiant inconnu), écrire est `403`
            $this->assertSame(404, $read[0]);
            $this->assertSame($readUnknown, $read);
            $this->assertSame([403, 403], [$put, $delete]);
        }
        $this->request('GET', '/api/v1/shopping-list-types/' . $theirs, $this->other);
        $this->assertSame(['Boucherie', []], [$this->jsonBody()['name'], $this->jsonBody()['departments']]);
    }

    public function testAnUnknownTypeIsNotFoundToWriteToo(): void
    {
        // When & Then
        $unknown = '3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34';
        $this->request('PUT', '/api/v1/shopping-list-types/' . $unknown, $this->owner, ['name' => 'Boucherie', 'departmentSlugs' => []]);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('DELETE', '/api/v1/shopping-list-types/' . $unknown, $this->owner);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheDetailListsTheProductsKeptApartWithTheirDepartmentAndNeverThoseOfAnotherUser(): void
    {
        // Given
        $id = $this->createdType($this->owner, 'Boucherie');
        $butter = new IngredientBuilder()->isButter()->withCreator($this->owner, true)->withListDestination($id)->build();
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->owner, true)->build();
        $theirs = new IngredientBuilder()->isEgg()->withCreator($this->other, true)->withListDestination($id)->build();
        foreach ([$butter, $flour, $theirs] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }

        // When
        $this->request('GET', '/api/v1/shopping-list-types/' . $id, $this->owner);
        $detail = $this->jsonBody();
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $summary = $this->jsonBody()['member'][0];

        // Then
        $this->assertSame([[
            'slug' => $butter->getSlug(),
            'name' => $butter->getName(),
            'departmentSlug' => $butter->getDepartment()->getSlug(),
            'departmentName' => $butter->getDepartment()->getName(),
        ]], $detail['ingredients']);
        $this->assertSame(1, $summary['ingredientCount']);
    }

    public function testDeletingATypeLosesNothing(): void
    {
        // Given : un type avec deux rayons, un produit à part, une liste en cours avec ses lignes et ses repas, une liste envoyée et une en pause ;
        // à côté, la liste principale en cours, un autre type et le type d'un autre compte
        $id = $this->createdType($this->owner, 'Boucherie');
        $marketId = $this->createdType($this->owner, 'Marché');
        $theirs = $this->createdType($this->other, 'Boucherie');
        $this->request('PUT', '/api/v1/shopping-list-types/' . $id, $this->owner, ['name' => 'Boucherie', 'departmentSlugs' => ['viande', 'fromage']]);
        $this->request('PUT', '/api/v1/shopping-list-types/' . $marketId, $this->owner, ['name' => 'Marché', 'departmentSlugs' => ['divers']]);
        $this->request('PUT', '/api/v1/shopping-list-types/' . $theirs, $this->other, ['name' => 'Boucherie', 'departmentSlugs' => ['viande']]);
        $butter = new IngredientBuilder()->isButter()->withCreator($this->owner, true)->withListDestination($id)->build();
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->owner, true)->withListDestination($marketId)->build();
        $egg = new IngredientBuilder()->isEgg()->withCreator($this->owner, true)->withListDestination('main')->build();
        foreach ([$butter, $flour, $egg] as $ingredient) {
            $this->persister->saveIngredient($ingredient);
        }
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthor($this->owner)->build();
        foreach ($recipe->getRows() as $row) {
            $row->getIngredient()->setCreatorId($this->owner->getId());
        }
        $this->persister->saveRecipe($recipe);

        $active = $this->savedList(ShoppingListBuilder::class, fn(ShoppingListBuilder $b) => $b->withListTypeId($id)->withName('Boucherie')->addRecipe($recipe)->addIngredient($butter));
        $sent = $this->savedList(ShoppingListBuilder::class, fn(ShoppingListBuilder $b) => $b->withListTypeId($id)->withName('Boucherie d\'hier')->withStatus(ShoppingListStatus::SENT)->addIngredient($butter));
        $paused = $this->savedList(ShoppingListBuilder::class, fn(ShoppingListBuilder $b) => $b->withListTypeId($id)->withName('Boucherie ancienne')->withStatus(ShoppingListStatus::PAUSED)->addIngredient($butter));
        $market = $this->savedList(ShoppingListBuilder::class, fn(ShoppingListBuilder $b) => $b->withListTypeId($marketId)->withName('Marché')->addIngredient($flour));
        $main = $this->savedList(ShoppingListBuilder::class, fn(ShoppingListBuilder $b) => $b->withName('Principale')->addIngredient($egg));
        $before = ['active' => $this->snapshot($active), 'sent' => $this->snapshot($sent), 'paused' => $this->snapshot($paused), 'market' => $this->snapshot($market), 'main' => $this->snapshot($main)];

        // When
        $this->request('DELETE', '/api/v1/shopping-list-types/' . $id, $this->owner);

        // Then : le type n'existe plus, ses rayons et le choix de ses produits sont libérés
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/shopping-list-types/' . $id, $this->owner);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $this->assertSame(['Marché'], array_column($this->jsonBody()['member'], 'name'));
        $this->assertSame(['divers'], $this->jsonBody()['member'][0]['departmentSlugs']);
        $this->assertSame(1, $this->jsonBody()['member'][0]['ingredientCount']);
        $destinations = $this->entityManager->getConnection()->fetchAllKeyValue('SELECT slug, list_destination FROM ingredient');
        $this->assertSame([null, $marketId, 'main'], [$destinations[$butter->getSlug()], $destinations[$flour->getSlug()], $destinations[$egg->getSlug()]]);
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM department_list_type WHERE list_type_id = ?", [$id]));

        // Then : ses listes n'ont plus de type, la liste en cours est en pause (version +1), rien d'autre ne change et aucune ligne n'est perdue
        $this->entityManager->clear();
        $after = ['active' => $this->snapshot($active), 'sent' => $this->snapshot($sent), 'paused' => $this->snapshot($paused), 'market' => $this->snapshot($market), 'main' => $this->snapshot($main)];
        $this->assertSame([null, 'PAUSED', $before['active']['version'] + 1], [$after['active']['type'], $after['active']['status'], $after['active']['version']]);
        $this->assertSame([null, 'SENT', $before['sent']['version']], [$after['sent']['type'], $after['sent']['status'], $after['sent']['version']]);
        $this->assertSame([null, 'PAUSED', $before['paused']['version']], [$after['paused']['type'], $after['paused']['status'], $after['paused']['version']]);
        $this->assertSame($before['market'], $after['market'], 'la liste de l\'autre type est intacte');
        $this->assertSame($before['main'], $after['main'], 'la liste principale est intacte');
        foreach (['active', 'sent', 'paused'] as $label) {
            $this->assertSame($before[$label]['rows'], $after[$label]['rows'], "$label : aucune ligne n'est perdue ni déplacée");
            $this->assertSame($before[$label]['meals'], $after[$label]['meals'], "$label : aucun repas n'est perdu");
            $this->assertSame($before[$label]['name'], $after[$label]['name'], "$label garde son nom");
        }
        $this->assertGreaterThan(0, count($before['active']['rows']) + count($before['active']['meals']));

        // Then : le type d'un autre compte et son rayon n'ont pas bougé
        $this->request('GET', '/api/v1/shopping-list-types/' . $theirs, $this->other);
        $this->assertSame(['viande'], array_column($this->jsonBody()['departments'], 'slug'));
    }

    public function testDeletingTheAccountErasesItsTypesAndTheirDepartments(): void
    {
        // Given
        $mine = $this->createdType($this->owner, 'Boucherie');
        $theirs = $this->createdType($this->other, 'Boucherie');
        $this->request('PUT', '/api/v1/shopping-list-types/' . $mine, $this->owner, ['name' => 'Boucherie', 'departmentSlugs' => ['viande']]);
        $this->request('PUT', '/api/v1/shopping-list-types/' . $theirs, $this->other, ['name' => 'Boucherie', 'departmentSlugs' => ['viande']]);

        // When
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new PurgeUserDataCommand($this->owner->getId()));

        // Then
        $connection = $this->entityManager->getConnection();
        $this->assertSame([$theirs], $connection->fetchFirstColumn('SELECT id FROM shopping_list_type'));
        $this->assertSame([$this->other->getId()], $connection->fetchFirstColumn('SELECT owner_id FROM department_list_type'));
    }

    public function testRefusesUnauthenticatedRequests(): void
    {
        // When & Then
        foreach ([['GET', '/api/v1/shopping-list-types'], ['POST', '/api/v1/shopping-list-types'], ['GET', '/api/v1/shopping-list-types/3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34'], ['PUT', '/api/v1/shopping-list-types/3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34'], ['DELETE', '/api/v1/shopping-list-types/3f1c2a7e-8d41-4c62-9b0a-5e7d2f6a1b34']] as [$method, $uri]) {
            $this->client->request($method, $uri, content: '{}', server: ['CONTENT_TYPE' => 'application/json']);
            $this->assertSame(401, $this->client->getResponse()->getStatusCode(), "$method $uri");
        }
    }

    private function createdType(User $user, string $name): string
    {
        $this->request('POST', '/api/v1/shopping-list-types', $user, ['name' => $name]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $name);

        return basename($this->client->getResponse()->headers->get('Location'));
    }

    private function typeCount(User $user): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM shopping_list_type WHERE owner_id = ?', [$user->getId()]);
    }

    /**
     * @return array<string, list<string>> les rayons de chaque type du propriétaire, par nom de type
     */
    private function departmentsByType(): array
    {
        $this->request('GET', '/api/v1/shopping-list-types', $this->owner);
        $byType = [];
        foreach ($this->jsonBody()['member'] as $type) {
            $byType[$type['name']] = $type['departmentSlugs'];
        }
        // Deux types créés dans la même seconde n'ont pas d'ordre garanti entre eux : on compare par nom
        ksort($byType);

        return $byType;
    }

    /**
     * @param class-string<ShoppingListBuilder> $builderClass
     * @param callable(ShoppingListBuilder): ShoppingListBuilder $configure
     */
    private function savedList(string $builderClass, callable $configure): ShoppingList
    {
        $list = $configure(new $builderClass()->withOwner($this->owner))->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list;
    }

    /**
     * L'état d'une liste lu en base : son type, son statut, sa version, son nom, ses lignes et ses repas.
     *
     * @return array{type: ?string, status: string, version: int, name: ?string, rows: list<array<string, mixed>>, meals: list<array<string, mixed>>}
     */
    private function snapshot(ShoppingList $list): array
    {
        $connection = $this->entityManager->getConnection();
        $row = $connection->fetchAssociative('SELECT list_type_id, status, version, name FROM shopping_list WHERE id = ?', [$list->getId()]);

        return [
            'type' => $row['list_type_id'],
            'status' => $row['status'],
            'version' => (int) $row['version'],
            'name' => $row['name'],
            'rows' => $connection->fetchAllAssociative('SELECT id, ingredient, quantity FROM shopping_list_row WHERE shopping_list = ? ORDER BY id', [$list->getId()]),
            'meals' => $connection->fetchAllAssociative('SELECT id, recipe, serving FROM meal WHERE shopping_list = ? ORDER BY id', [$list->getId()]),
        ];
    }

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

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
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
