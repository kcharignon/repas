<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MarmitonInMemoryPageReader;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Corriger puis supprimer la recette Marmiton neutre (ADR 0037, amendement du 2026-11-08, MAR-005) de bout en bout : les vrais bus, la vraie base, le
 * vrai voter, les vraies erreurs HTTP. L'administrateur la modifie avec l'éditeur ordinaire et la supprime ; aucun autre utilisateur ne le peut ; la
 * rendre secrète ou favorite reste refusé à tous ; les copies déjà faites ne bougent pas et les suivantes reçoivent la version corrigée.
 */
class RecipeWithoutOwnerApiFlowTest extends DatabaseTestCase
{
    private const string URL = 'https://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx';
    private const string CANONICAL = 'https://www.marmiton.org/recettes/recette_tarte-au-citron-meringuee_22082.aspx';

    private KernelBrowser $client;
    private DataPersister $persister;
    private MarmitonInMemoryPageReader $reader;
    private User $admin;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
        $this->reader = new MarmitonInMemoryPageReader()->willRead('22082', new MarmitonRecipePage(self::CANONICAL, 'Tarte au citron meringuée', '6 personnes', 'Dessert', ['250 g de farine', '3 oeufs', '2 jaunes d\'oeuf', '2 blancs d\'oeuf']));
        static::getContainer()->set(MarmitonPageReader::class, $this->reader);

        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isMiscellaneous()->build());
        foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isSoupSpoon()] as $unit) {
            $this->persister->saveUnit($unit->build());
        }
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->admin = $this->savedUser(admin: true);
        $this->alice = $this->savedUser();
        $this->bob = $this->savedUser();
    }

    public function testTheAdministratorCorrectsTheNeutralInTheOrdinaryEditorAndTheNextCopiersGetTheCorrectedVersion(): void
    {
        // Given : Alice a copié la tarte avant ; l'administrateur a un citron à lui
        $aliceCopy = $this->importedId($this->alice);
        $neutralId = $this->neutralId();
        $lemon = new IngredientBuilder()->withName('Citron')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($lemon);
        $this->request('GET', "/api/v1/recipes/$aliceCopy", $this->alice);
        $aliceSheet = $this->jsonBody();
        $this->request('GET', "/api/v1/recipes/$neutralId", $this->admin);
        $etag = (string) $this->client->getResponse()->headers->get('ETag');
        $sheet = $this->jsonBody();
        $this->assertSame(['Farine', 'Oeufs', 'Jaunes d\'oeuf', 'Blancs d\'oeuf'], array_column($sheet['rows'], 'ingredientName'));

        // When : il garde la farine et les œufs (ce que l'éditeur renvoie), retire jaunes et blancs, ajoute son citron, change le nom
        $rows = array_map(static fn(array $row): array => ['ingredientSlug' => $row['ingredientSlug'], 'unitSlug' => $row['unitSlug'], 'quantity' => $row['quantity']], array_slice($sheet['rows'], 0, 2));
        $rows[] = ['ingredientSlug' => $lemon->getSlug(), 'unitSlug' => 'unite', 'quantity' => 4];
        $this->request('PUT', "/api/v1/recipes/$neutralId", $this->admin, ['name' => 'tarte au citron sans jaunes', 'serving' => 6, 'typeSlug' => 'dessert', 'rows' => $rows], ['HTTP_IF_MATCH' => $etag]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/recipes/$neutralId", $this->admin);
        $edited = $this->jsonBody();
        $this->assertSame(['Tarte au citron sans jaunes', self::CANONICAL, false, false, null, true], [$edited['name'], $edited['sourceUrl'], $edited['secret'], $edited['favorite'], $edited['originalId'], $edited['canEdit']]);
        $this->assertSame($sheet['version'] + 1, $edited['version']);
        $this->assertSame(['Farine', 'Oeufs', 'Citron'], array_column($edited['rows'], 'ingredientName'));
        $reservedSlugs = $this->entityManager->getConnection()->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator = ?', [Recipe::MARMITON_OWNER_ID]);
        foreach ($edited['rows'] as $row) {
            $this->assertContains($row['ingredientSlug'], $reservedSlugs, 'Les lignes de la neutre sont des ingrédients du propriétaire réservé, jamais ceux de l\'administrateur.');
        }

        // Then : la copie d'Alice, faite avant, est telle quelle ; Bob reçoit la version corrigée, avec SES ingrédients
        $this->request('GET', "/api/v1/recipes/$aliceCopy", $this->alice);
        $this->assertSame($aliceSheet, $this->jsonBody());
        $bobCopy = $this->importedId($this->bob);
        $this->request('GET', "/api/v1/recipes/$bobCopy", $this->bob);
        $bobSheet = $this->jsonBody();
        $this->assertSame(['Tarte au citron sans jaunes', 6, 'dessert', $neutralId], [$bobSheet['name'], $bobSheet['serving'], $bobSheet['typeSlug'], $bobSheet['originalId']]);
        $this->assertSame(['Farine', 'Oeufs', 'Citron'], array_column($bobSheet['rows'], 'ingredientName'));
        $bobsIngredients = $this->entityManager->getConnection()->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator = ?', [$this->bob->getId()]);
        foreach ($bobSheet['rows'] as $row) {
            $this->assertContains($row['ingredientSlug'], $bobsIngredients);
        }
        $this->assertSame(1, $this->reader->callCount());
    }

    public function testOnlyTheAdministratorEditsOrRemovesTheNeutralAndNobodyMakesItSecretOrFavorite(): void
    {
        // Given
        $this->importedId($this->alice);
        $neutralId = $this->neutralId();
        $this->request('GET', "/api/v1/recipes/$neutralId", $this->admin);
        $rows = array_map(static fn(array $row): array => ['ingredientSlug' => $row['ingredientSlug'], 'unitSlug' => $row['unitSlug'], 'quantity' => $row['quantity']], $this->jsonBody()['rows']);
        $body = ['name' => 'Piratée', 'serving' => 2, 'typeSlug' => 'plat', 'rows' => $rows];
        $before = $this->neutralSnapshot();

        // When & Then : un utilisateur ordinaire ne la modifie ni ne la supprime (`403`, la recette existe), un administrateur non plus ne la rend ni secrète ni favorite
        foreach ([$this->alice, $this->bob] as $who) {
            $this->request('PUT', "/api/v1/recipes/$neutralId", $who, $body);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'PUT');
            $this->request('DELETE', "/api/v1/recipes/$neutralId", $who);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'DELETE');
        }
        $this->request('PUT', "/api/v1/recipes/$neutralId", $this->admin, ['secret' => true] + $body);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'secrète');
        $this->request('PUT', "/api/v1/recipes/$neutralId/favorite", $this->admin, ['favorite' => true]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'favori');
        $this->assertSame($before, $this->neutralSnapshot(), 'La neutre est telle quelle : même version, même nom, mêmes lignes.');

        // When : l'administrateur renvoie le choix actuel (l'éditeur envoie `secret: false`)
        $this->request('PUT', "/api/v1/recipes/$neutralId", $this->admin, ['secret' => false] + $body);

        // Then : c'est une modification ordinaire
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/recipes/$neutralId", $this->bob);
        $this->assertSame(['Piratée', false, false], [$this->jsonBody()['name'], $this->jsonBody()['secret'], $this->jsonBody()['canEdit']]);
    }

    public function testTheAdministratorsRefusalsAreTheOrdinaryOnes(): void
    {
        // Given
        $this->importedId($this->alice);
        $neutralId = $this->neutralId();
        $this->request('GET', "/api/v1/recipes/$neutralId", $this->admin);
        $sheet = $this->jsonBody();
        $rows = array_map(static fn(array $row): array => ['ingredientSlug' => $row['ingredientSlug'], 'unitSlug' => $row['unitSlug'], 'quantity' => $row['quantity']], $sheet['rows']);
        $others = new IngredientBuilder()->withName('Citron')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withCreator($this->alice, true)->build();
        $this->persister->saveIngredient($others);
        $before = $this->neutralSnapshot();
        $reserved = $this->rows("ingredient WHERE creator = '" . Recipe::MARMITON_OWNER_ID . "'");

        // When & Then : version périmée (`412`), ingrédient d'un autre compte (`404`), unité qui ne se convertit pas (`422`), recette sans ligne (`422`)
        $body = ['name' => 'Tarte', 'serving' => 4, 'typeSlug' => 'plat'];
        $this->request('PUT', "/api/v1/recipes/$neutralId", $this->admin, ['rows' => $rows] + $body, ['HTTP_IF_MATCH' => '"99"']);
        $this->assertSame(412, $this->client->getResponse()->getStatusCode(), 'version');
        $this->request('PUT', "/api/v1/recipes/$neutralId", $this->admin, ['rows' => [...$rows, ['ingredientSlug' => $others->getSlug(), 'unitSlug' => 'unite', 'quantity' => 1]]] + $body);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), 'ingrédient d\'un autre');
        $mine = new IngredientBuilder()->withName('Poivre')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($mine);
        $this->request('PUT', "/api/v1/recipes/$neutralId", $this->admin, ['rows' => [...$rows, ['ingredientSlug' => $mine->getSlug(), 'unitSlug' => 'cuillere-a-soupe', 'quantity' => 1]]] + $body);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), 'unité');
        $this->request('PUT', "/api/v1/recipes/$neutralId", $this->admin, ['rows' => []] + $body);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), 'sans ligne');

        // Then : rien n'est écrit, pas même un ingrédient copié avant le refus
        $this->assertSame($before, $this->neutralSnapshot());
        $this->assertSame($reserved, $this->rows("ingredient WHERE creator = '" . Recipe::MARMITON_OWNER_ID . "'"));
    }

    public function testTheAdministratorRemovesTheNeutralTheCopiesStayAndANewImportStartsAgain(): void
    {
        // Given : Alice et Bob ont copié la tarte
        $aliceCopy = $this->importedId($this->alice);
        $bobCopy = $this->importedId($this->bob);
        $neutralId = $this->neutralId();

        // When
        $this->request('DELETE', "/api/v1/recipes/$neutralId", $this->admin);

        // Then : la neutre n'existe plus pour personne ; les copies sont intactes, lisibles et modifiables par leurs auteurs, avec leur lien d'origine
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/recipes/$neutralId", $this->admin);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/recipes/$aliceCopy", $this->alice);
        $sheet = $this->jsonBody();
        $this->assertSame([200, self::CANONICAL, true, null], [$this->client->getResponse()->getStatusCode(), $sheet['sourceUrl'], $sheet['canEdit'], $sheet['originalId']]);
        // La neutre supprimée, la copie n'est plus une « version commune » et ne désigne rien
        $this->assertSame([false, null], [$sheet['ownerless'], $sheet['copyId']]);
        $rows =array_map(static fn(array $row): array => ['ingredientSlug' => $row['ingredientSlug'], 'unitSlug' => $row['unitSlug'], 'quantity' => $row['quantity']], $sheet['rows']);
        $this->request('PUT', "/api/v1/recipes/$aliceCopy", $this->alice, ['name' => 'Ma tarte', 'serving' => 2, 'typeSlug' => 'plat', 'rows' => $rows]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', "/api/v1/recipes/$bobCopy", $this->bob);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // When : un nouvel import de la même page, par Bob
        $this->importedId($this->bob);

        // Then : la page est relue, une neutre neuve est créée (et l'import la copie)
        $this->assertSame(2, $this->reader->callCount());
        $this->assertNotSame('', $this->neutralId());
        $this->assertNotSame($neutralId, $this->neutralId());
    }

    public function testAnOrdinaryUserCannotRemoveTheNeutralAndNothingChanges(): void
    {
        // Given
        $this->importedId($this->alice);
        $before = $this->neutralSnapshot();

        // When
        $this->request('DELETE', '/api/v1/recipes/' . $this->neutralId(), $this->bob);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame($before, $this->neutralSnapshot());
    }

    public function testTheAdministratorFindsTheNeutralInOtherPeopleRecipesAfterCopyingItAndAskForTheCopyIsSaid(): void
    {
        // Given : l'administrateur et Bob ont copié la tarte
        $this->importedId($this->admin);
        $this->importedId($this->bob);
        $neutralId = $this->neutralId();

        // When
        $this->request('GET', '/api/v1/recipes/other-people', $this->admin);
        $asAdmin = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/other-people', $this->bob);
        $asBob = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/other-people?q=tarte', $this->admin);
        $searched = $this->jsonBody();

        // Then : seul l'administrateur la voit encore, et le dit ; la clé est écrite pour tous
        $this->assertSame([[$neutralId, true, true]], array_map(static fn(array $recipe): array => [$recipe['id'], $recipe['fromMarmiton'], $recipe['alreadyCopied']], $asAdmin['member']));
        $this->assertSame([true], array_map(static fn(array $recipe): bool => $recipe['copyId'] !== null, $asAdmin['member']), 'copyId nomme la copie de l\'administrateur');
        $this->assertSame([], array_filter(array_column($asBob['member'], 'copyId')));
        $this->assertSame(1, $asAdmin['totalItems']);
        $this->assertSame([], $asBob['member']);
        $this->assertSame([$neutralId], array_column($searched['member'], 'id'));
    }

    // --- Aides ------------------------------------------------------------------------------------------------------------------------------

    private function importedId(User $user): string
    {
        $this->request('POST', '/api/v1/recipes/import/marmiton', $user, ['url' => self::URL]);
        $this->assertContains($this->client->getResponse()->getStatusCode(), [200, 201]);

        return basename((string) $this->client->getResponse()->headers->get('Location'));
    }

    private function neutralId(): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne("SELECT id FROM recipe WHERE marmiton_id = '22082'");
    }

    /**
     * @return array{array<string, mixed>|false, list<array<string, mixed>>}
     */
    private function neutralSnapshot(): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            $connection->fetchAssociative("SELECT id, name, serving, author, type, version, secret, favorite, source_url FROM recipe WHERE marmiton_id = '22082'"),
            $connection->fetchAllAssociative("SELECT rr.id, rr.ingredient, rr.quantity, rr.unit, rr.position FROM recipe_row rr JOIN recipe r ON r.id = rr.recipe WHERE r.marmiton_id = '22082' ORDER BY rr.position"),
        ];
    }

    private function rows(string $tableAndFilter): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM $tableAndFilter");
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
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, User $user, ?array $body = null, array $headers = []): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ] + $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
