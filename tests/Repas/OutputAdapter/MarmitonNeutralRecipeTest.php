<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportMarmitonRecipeCommand;
use Repas\Repas\Application\Command\PurgeUserData\PurgeUserDataCommand;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetConversionHealth\GetConversionHealthQuery;
use Repas\Repas\Application\Query\GetIngredient\GetIngredientQuery;
use Repas\Repas\Application\Query\GetSeedingReport\GetSeedingReportQuery;
use Repas\Repas\Application\Query\ListAvailableIngredients\ListAvailableIngredientsQuery;
use Repas\Repas\Application\Query\ListDuplicateIngredients\ListDuplicateIngredientsQuery;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\ListIngredientsByDepartmentQuery;
use Repas\Repas\Application\Query\ListIngredientsToFix\ListIngredientsToFixQuery;
use Repas\Repas\Application\Query\SearchIngredients\SearchIngredientsQuery;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MarmitonInMemoryPageReader;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

/**
 * La recette Marmiton neutre et ses ingrédients sur la VRAIE base (ADR 0037 décisions 3 à 6) : le propriétaire « personne »
 * (`Recipe::MARMITON_OWNER_ID`, qui n'est pas un compte) n'a aucune existence pour les comptes, et chaque ligne reçoit son ingrédient dans l'ordre
 * (a) réutilisé, (b) copié du catalogue, (c) créé en rayon `divers`. La copie d'un compte amorcé retrouve ses propres ingrédients par provenance.
 */
class MarmitonNeutralRecipeTest extends DatabaseTestCase
{
    private const string OWNER = Recipe::MARMITON_OWNER_ID;

    private DataPersister $persister;
    private MarmitonInMemoryPageReader $reader;
    private CommandBusInterface $commands;
    private QueryBusInterface $queries;
    private User $catalogOwner;
    private User $alice;
    private User $bob;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new MarmitonInMemoryPageReader();
        static::getContainer()->set(MarmitonPageReader::class, $this->reader);
        $this->commands = static::getContainer()->get(CommandBusInterface::class);
        $this->queries = static::getContainer()->get(QueryBusInterface::class);

        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isMiscellaneous()->build());
        foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isCoffeeSpoon()] as $unit) {
            $this->persister->saveUnit($unit->build());
        }
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->catalogOwner = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $this->alice = $this->savedUser(new UserBuilder());
        $this->bob = $this->savedUser(new UserBuilder());
        $this->admin = $this->savedUser(new UserBuilder()->isAdmin());
    }

    // --- Le propriétaire « personne » n'existe pour aucun compte ---------------------------------------------------------------------------

    public function testNoAccountSeesAnIngredientOfTheNeutralInAnyListSearchOrSheet(): void
    {
        // Given : une neutre importée, avec ses ingrédients du propriétaire réservé
        $this->read('1', 'Gâteau', ['250 g de farine', '3 oeufs', '100 g de sucre glace']);
        $this->import($this->alice, '1');
        $marmitonSlugs = $this->marmitonIngredientSlugs();
        $this->assertCount(3, $marmitonSlugs);

        foreach ([$this->alice, $this->bob, $this->admin, $this->catalogOwner] as $who) {
            // When : toutes les lectures d'ingrédients d'un compte
            $seen = json_encode([
                $this->queries->ask(new ListAvailableIngredientsQuery($who->getId())),
                $this->queries->ask(new ListAvailableIngredientsQuery($who->getId(), 'sucre')),
                $this->queries->ask(new SearchIngredientsQuery('farine', $who->getId())),
                $this->queries->ask(new ListIngredientsByDepartmentQuery('divers', $who->getId())),
                $this->queries->ask(new ListDuplicateIngredientsQuery($who->getId())),
                $this->queries->ask(new ListIngredientsToFixQuery($who->getId())),
            ], JSON_THROW_ON_ERROR);

            // Then : aucune sortie ne porte ni un de leurs slugs ni leur propriétaire (les slugs se terminent par son identifiant)
            $this->assertStringNotContainsString(self::OWNER, $seen, "Un ingrédient du propriétaire réservé est visible de {$who->getId()}.");
            foreach ($marmitonSlugs as $slug) {
                $this->assertStringNotContainsString('"' . $slug . '"', $seen);
            }
            // Et la fiche d'un ingrédient du propriétaire réservé est introuvable
            foreach ($marmitonSlugs as $slug) {
                try {
                    $this->queries->ask(new GetIngredientQuery($slug, $who->getId()));
                    $this->fail("La fiche de $slug aurait dû être introuvable.");
                } catch (IngredientException $exception) {
                    $this->assertSame(404, $exception->getCode());
                }
            }
        }
    }

    public function testTheVisibilityRuleIsTheOnlyOneAndNeverMatchesTheReservedOwner(): void
    {
        // Given
        $this->read('1', 'Gâteau', ['250 g de farine']);
        $this->import($this->alice, '1');

        // When : la règle de visibilité de tous les comptes (créateur = moi) appliquée à chaque compte
        $connection = $this->entityManager->getConnection();
        $visibleToSomeone = 0;
        foreach ([$this->alice, $this->bob, $this->admin, $this->catalogOwner] as $who) {
            $visibleToSomeone += (int) $connection->fetchOne(
                'SELECT count(*) FROM ingredient i WHERE ' . IngredientVisibility::ownedSql('i', 'viewer') . ' AND i.creator = :reserved',
                ['viewer' => $who->getId(), 'reserved' => self::OWNER],
            );
        }

        // Then
        $this->assertSame(0, $visibleToSomeone);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM "user" WHERE id = ?', [self::OWNER]), 'Le propriétaire réservé n\'est pas un compte.');
    }

    public function testNoIngredientOfTheNeutralIsCopiedByTheSeedingOfAnAccountEvenIfItIsMarkedBase(): void
    {
        // Given : une neutre importée ; un marqueur « de base » posé directement en base sur un ingrédient du propriétaire réservé (inerte)
        $this->read('1', 'Gâteau', ['250 g de farine', '3 oeufs']);
        $this->import($this->alice, '1');
        $this->entityManager->getConnection()->executeStatement('UPDATE ingredient SET is_base = TRUE WHERE creator = ?', [self::OWNER]);
        $sugar = new IngredientBuilder()->isSugar()->withCreator($this->catalogOwner, true)->withBase()->build();
        $this->persister->saveIngredient($sugar);

        // When : l'amorçage d'un nouveau compte
        $toSeed = static::getContainer()->get(IngredientAdoptionBulkRepository::class)->ingredientsToSeed($this->bob->getId(), $this->catalogOwner->getId(), []);
        $this->commands->dispatch(new SeedUserIngredientsCommand($this->bob->getId(), 'activation'));

        // Then : seul le catalogue du propriétaire part, jamais un ingrédient du propriétaire réservé
        $this->assertSame([$sugar->getSlug()], array_map(static fn($ingredient): string => $ingredient->slug, $toSeed));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IN (SELECT slug FROM ingredient WHERE creator = ?)', [$this->bob->getId(), self::OWNER]));
    }

    public function testThePurgeOfAnAccountNeverTouchesTheNeutralNorItsIngredients(): void
    {
        // Given : Alice a importé, une neutre existe
        $this->read('1', 'Gâteau', ['250 g de farine']);
        $this->import($this->alice, '1');
        $neutral = (string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM recipe WHERE marmiton_id = ?', ['1']);
        $before = $this->marmitonIngredientSlugs();

        // When : son compte est supprimé (sa copie part, la neutre reste)
        $this->commands->dispatch(new PurgeUserDataCommand($this->alice->getId()));

        // Then
        $this->entityManager->clear();
        $this->assertSame($neutral, (string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM recipe WHERE marmiton_id = ?', ['1']));
        $this->assertSame($before, $this->marmitonIngredientSlugs());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM recipe WHERE author = ?', [$this->alice->getId()]));
    }

    public function testAnIngredientOfTheReservedOwnerLoadsAndIsNotAnAccountsIngredient(): void
    {
        // Given
        $this->read('1', 'Gâteau', ['250 g de farine']);
        $this->import($this->alice, '1');
        $slug = $this->marmitonIngredientSlugs()[0];

        // When : le modèle se charge (un créateur est exigé, et celui-ci en est un)
        $this->entityManager->clear();
        $ingredient = static::getContainer()->get(IngredientRepository::class)->findOneBySlug($slug);

        // Then
        $this->assertSame(self::OWNER, $ingredient->getCreatorId());
        $this->assertFalse($ingredient->isBase());
        $this->assertFalse($ingredient->isOwnedBy($this->alice->getId()));
        $this->assertFalse($ingredient->isOwnedBy($this->admin->getId()));
    }

    public function testNeitherTheIngredientCapNorTheStatisticsOfAnAccountAreInvolved(): void
    {
        // Given : le propriétaire réservé a déjà autant d'ingrédients que le plafond d'un compte
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from, is_base)"
            . " SELECT 'plein-' || g, 'Plein ' || g, '', 'plein-' || g, 'divers', 'gramme', 'gramme', ?, '[]', NULL, FALSE FROM generate_series(1, ?) g",
            [self::OWNER, Ingredient::MAX_PER_CREATOR],
        );
        $statisticsBefore = $connection->fetchAllAssociative('SELECT * FROM "user" ORDER BY id');
        $this->read('1', 'Gâteau', ['250 g de farine', '3 oeufs']);

        // When
        $this->import($this->alice, '1');

        // Then : l'import réussit, et seul le compte d'Alice a bougé (sa copie), jamais un autre compte ni un propriétaire réservé
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM recipe WHERE marmiton_id = ?', ['1']));
        $after = $connection->fetchAllAssociative('SELECT * FROM "user" ORDER BY id');
        $this->assertSame(count($statisticsBefore), count($after));
        foreach ($after as $index => $row) {
            if ($row['id'] !== $this->alice->getId()) {
                $this->assertSame($statisticsBefore[$index], $row, 'Un autre compte a été touché.');
            }
        }
    }

    public function testTheHealthReportsDoNotTakeTheReservedOwnerForAnAnomaly(): void
    {
        // Given : une neutre dont une ligne ne se convertit pas (une cuillère de maïzena achetée au gramme sans conversion)
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Maïzena')->withDepartment(new DepartmentBuilder()->isBakery())->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->withCreator($this->catalogOwner, true)->withBase()->build());
        $this->read('1', 'Gâteau', ['1 cuillères à soupe de maïzena', '3 oeufs']);
        $this->import($this->alice, '1');

        // When
        $conversions = $this->queries->ask(new GetConversionHealthQuery());
        $seeding = $this->queries->ask(new GetSeedingReportQuery([], [], false));

        // Then : aucun ingrédient « sans compte », aucune ligne « sans compte » qui ne se convertit pas
        $this->assertSame(0, $conversions->unattachedIngredientsNotConvertible);
        $this->assertSame(0, $conversions->unattachedRecipeRowsNotConvertible);
        $this->assertSame(0, $seeding->health->ingredientsWithoutCreator);
    }

    // --- La lecture : le lien d'origine et la pastille ne coûtent aucune requête ----------------------------------------------------------

    public function testTheBadgeAndTheLinkOfOriginComeFromTheQueriesAlreadyMade(): void
    {
        // Given : une neutre et la copie d'Alice
        $this->read('1', 'Gâteau', ['250 g de farine', '3 oeufs']);
        $copyId = $this->import($this->alice, '1');
        $neutralId = (string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM recipe WHERE marmiton_id = ?', ['1']);
        $loader = static::getContainer()->get(RecipeLoader::class);
        $counter = static::getContainer()->get(QueryCounter::class);

        // When : la page « Recettes des autres » de Bob (une seule instruction, la neutre en fait partie) et la fiche de la copie puis de la neutre
        $this->entityManager->clear();
        $counter->reset();
        $page = $loader->listOfOtherPeople($this->bob->getId(), 200, 0);
        $pageQueries = $counter->count();
        $this->entityManager->clear();
        $counter->reset();
        $copy = $loader->get($copyId, $this->alice->getId());
        $copyQueries = $counter->count();
        $this->entityManager->clear();
        $counter->reset();
        $neutral = $loader->get($neutralId, $this->bob->getId());
        $neutralQueries = $counter->count();

        // Then : la pastille et le lien sont lus dans les mêmes instructions que le reste
        $this->assertSame(1, $pageQueries);
        $this->assertSame([[$neutralId, true]], array_map(static fn($recipe): array => [$recipe->id, $recipe->fromMarmiton], $page->member));
        $this->assertSame($copyQueries, $neutralQueries);
        $this->assertSame('https://www.marmiton.org/recettes/recette_page_1.aspx', $copy->sourceUrl);
        $this->assertSame($copy->sourceUrl, $neutral->sourceUrl);
    }

    // --- La résolution des ingrédients : (a) réutiliser, (b) copier le catalogue, (c) créer ------------------------------------------------

    public function testAnIngredientAlreadyCreatedForMarmitonIsReusedByAnotherNeutral(): void
    {
        // Given : une première recette crée « Sel », une seconde en parle aussi
        $this->read('1', 'Gâteau', ['1 pincées de sel', '250 g de farine']);
        $this->read('2', 'Tarte', ['2 pincées de sels']);
        $this->import($this->alice, '1');
        $saltSlug = $this->slugOfRow('1', 'Sel');

        // When
        $this->import($this->bob, '2');

        // Then : le même ingrédient du propriétaire réservé, pas un deuxième
        $this->assertSame($saltSlug, $this->slugOfRow('2', 'Sel'));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM ingredient WHERE creator = ? AND slugged_name = 'sel'", [self::OWNER]));
    }

    public function testAnIngredientOfTheCatalogMarkedBaseIsCopiedWithItsOriginItsUnitsAndItsOwnConversions(): void
    {
        // Given : « Farine » est un ingrédient de base du catalogue, avec une conversion propre
        $flour = new IngredientBuilder()->isFlour()->withCreator($this->catalogOwner, true)->withBase()->build();
        $this->persister->saveIngredient($flour);
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(12)->build());
        $this->read('1', 'Gâteau', ['250 g de farine', '1 cuillères à soupe de farine']);

        // When
        $this->import($this->alice, '1');

        // Then : une copie pour le propriétaire réservé, qui retient l'origine, les unités et la conversion propre ; une seule pour les deux lignes
        $connection = $this->entityManager->getConnection();
        $copies = $connection->fetchAllAssociative("SELECT slug, department, default_purchase_unit, adopted_from, is_base FROM ingredient WHERE creator = ?", [self::OWNER]);
        $this->assertCount(1, $copies);
        $this->assertSame(['boulangerie', 'kilo', $flour->getSlug(), false], [$copies[0]['department'], $copies[0]['default_purchase_unit'], $copies[0]['adopted_from'], $copies[0]['is_base']]);
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient = ?', [$copies[0]['slug']]));
        // L'original est intact
        $this->assertSame([$this->catalogOwner->getId(), true], array_values($connection->fetchNumeric('SELECT creator, is_base FROM ingredient WHERE slug = ?', [$flour->getSlug()])));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient = ?', [$flour->getSlug()]));
    }

    public function testAnIngredientThatTheCatalogDoesNotKnowIsCreatedInMiscellaneousInTheUnitOfTheLine(): void
    {
        // Given
        $this->read('1', 'Gâteau', ['4 citrons de taille moyenne', '2 jaunes d\'oeuf']);

        // When
        $this->import($this->alice, '1');

        // Then
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT name, department, default_cooking_unit, default_purchase_unit, adopted_from, creator, compatible_units FROM ingredient WHERE creator = ? ORDER BY name',
            [self::OWNER],
        );
        $this->assertSame(['Citrons', 'Jaunes d\'oeuf'], array_column($rows, 'name'));
        foreach ($rows as $row) {
            $this->assertSame(['divers', 'unite', 'unite', null], [$row['department'], $row['default_cooking_unit'], $row['default_purchase_unit'], $row['adopted_from']]);
            $this->assertStringContainsString('unite', (string) $row['compatible_units']);
        }
    }

    public function testACopierWhoReceivedTheCatalogFindsHisOwnIngredientsInsteadOfNewOnes(): void
    {
        // Given : le catalogue a « Farine » et « Sucre » de base ; Bob les a reçus à son activation (l'amorçage réel) ; Alice non
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->withCreator($this->catalogOwner, true)->withBase()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isSugar()->withCreator($this->catalogOwner, true)->withBase()->build());
        $this->commands->dispatch(new SeedUserIngredientsCommand($this->bob->getId(), 'activation'));
        $bobsIngredients = $this->slugsCreatedBy($this->bob);
        $this->assertCount(2, $bobsIngredients);
        $this->read('1', 'Gâteau', ['250 g de farine', '100 g de sucre', '2 oeufs']);

        // When : Bob importe d'abord (la neutre est créée à sa demande), Alice ensuite
        $this->import($this->bob, '1');
        $this->import($this->alice, '1');

        // Then : Bob retrouve ses deux ingrédients, aucun doublon « Farine » ni « Sucre » ; seuls les « Oeufs » (inconnus du catalogue) lui sont copiés
        $bobsRecipe = $this->rowSlugsOfCopyBy($this->bob);
        $this->assertSame($bobsIngredients[0], $bobsRecipe[0]);
        $this->assertSame($bobsIngredients[1], $bobsRecipe[1]);
        $this->assertCount(3, $this->slugsCreatedBy($this->bob));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM ingredient WHERE creator = ? AND slugged_name = 'farine'", [$this->bob->getId()]));

        // Then : Alice, qui n'a rien reçu, en reçoit une copie pour chaque ligne, qui retient l'origine du catalogue (jamais « création »)
        $alices = $this->slugsCreatedBy($this->alice);
        $this->assertCount(3, $alices);
        $origins = $this->entityManager->getConnection()->fetchFirstColumn('SELECT adopted_from FROM ingredient WHERE creator = ? ORDER BY slug', [$this->alice->getId()]);
        $this->assertNotContains(null, $origins);
        foreach ($alices as $slug) {
            $this->assertStringEndsWith($this->alice->getId(), $slug);
        }
    }

    public function testTheNeutralKeepsAUnitThatDoesNotConvertAndTheSheetOfTheCopyReportsIt(): void
    {
        // Given : « Maïzena » du catalogue s'achète au kilo, sans conversion de la cuillère à soupe
        $this->persister->saveIngredient(new IngredientBuilder()->withName('Maïzena')->withDepartment(new DepartmentBuilder()->isBakery())->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->withCreator($this->catalogOwner, true)->withBase()->build());
        $this->read('1', 'Gâteau', ['1 cuillères à soupe de maïzena', '250 g de beurre']);

        // When
        $copyId = $this->import($this->alice, '1');

        // Then : la copie existe ; la fiche dit « à régler » pour la ligne qui ne se convertit pas, et seulement elle
        $fiche = static::getContainer()->get(RecipeLoader::class)->get($copyId, $this->alice->getId());
        $convertible = [];
        foreach ($fiche->rows as $row) {
            $convertible[$row->ingredientName] = $row->convertible;
        }
        $this->assertSame(['Maïzena' => false, 'Beurre' => true], $convertible);
    }

    public function testTheCopyOfAKnownNeutralNeverReadsThePageAgain(): void
    {
        // Given
        $this->read('1', 'Gâteau', ['250 g de farine']);
        $this->import($this->alice, '1');

        // When
        $this->import($this->bob, '1');
        $this->import($this->admin, '1');

        // Then
        $this->assertSame(1, $this->reader->callCount());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM recipe WHERE marmiton_id = ?', ['1']));
        $this->assertSame(3, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM recipe WHERE orignal IS NOT NULL'));
    }

    public function testTheUniqueIndexRefusesASecondNeutralOfTheSameRecipe(): void
    {
        // Given : une neutre
        $this->read('1', 'Gâteau', ['250 g de farine']);
        $this->import($this->alice, '1');

        // When & Then : la base elle-même refuse un second tag identique (dernier filet si le verrou était contourné)
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO recipe (id, name, slug, serving, author, type, version, marmiton_id) VALUES (?, 'Autre', 'autre', 4, ?, 'plat', 1, '1')",
            [UuidGenerator::new(), self::OWNER],
        );
    }

    public function testTheRepositoryFindsTheNeutralAndTheCopyInOneQueryEach(): void
    {
        // Given
        $this->read('1', 'Gâteau', ['250 g de farine']);
        $copyId = $this->import($this->alice, '1');
        $this->entityManager->clear();
        $recipes = static::getContainer()->get(RecipeRepository::class);

        // When
        $neutralId = $recipes->findMarmitonNeutralId('1');

        // Then
        $this->assertNotNull($neutralId);
        $this->assertSame($copyId, $recipes->findCopyIdOf((string) $neutralId, $this->alice->getId()));
        $this->assertNull($recipes->findCopyIdOf((string) $neutralId, $this->bob->getId()));
        $this->assertNull($recipes->findMarmitonNeutralId('2'));
        $this->assertSame(self::OWNER, $recipes->findOneById((string) $neutralId)->getAuthorId());
        $this->assertTrue($recipes->findOneById((string) $neutralId)->isMarmitonNeutral());
    }

    // --- Aides ------------------------------------------------------------------------------------------------------------------------------

    /**
     * @param list<string> $lines
     */
    private function read(string $marmitonId, string $title, array $lines): void
    {
        $this->reader->willRead($marmitonId, new MarmitonRecipePage(sprintf('https://www.marmiton.org/recettes/recette_page_%s.aspx', $marmitonId), $title, '4 personnes', 'Plat principal', $lines));
    }

    /**
     * @return string l'identifiant de la copie de l'utilisateur
     */
    private function import(User $user, string $marmitonId): string
    {
        $imported = $this->commands->dispatch(new ImportMarmitonRecipeCommand(sprintf('https://www.marmiton.org/recettes/recette_page_%s.aspx', $marmitonId), $user->getId(), UuidGenerator::new()));
        $this->entityManager->clear();

        return $imported->recipeId;
    }

    /**
     * @return list<string> les slugs des ingrédients du propriétaire réservé
     */
    private function marmitonIngredientSlugs(): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator = ? ORDER BY slug', [self::OWNER]);
    }

    /**
     * @return list<string>
     */
    private function slugsCreatedBy(User $user): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator = ? ORDER BY slug', [$user->getId()]);
    }

    /**
     * @return list<string> les ingrédients des lignes de la copie de l'utilisateur, dans l'ordre de la recette
     */
    private function rowSlugsOfCopyBy(User $user): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT rr.ingredient FROM recipe_row rr JOIN recipe r ON r.id = rr.recipe WHERE r.author = ? AND r.orignal IS NOT NULL ORDER BY rr.position',
            [$user->getId()],
        );
    }

    private function slugOfRow(string $marmitonId, string $ingredientName): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT i.slug FROM recipe_row rr JOIN recipe r ON r.id = rr.recipe JOIN ingredient i ON i.slug = rr.ingredient WHERE r.marmiton_id = ? AND i.name = ?',
            [$marmitonId, $ingredientName],
        );
    }

    private function savedUser(UserBuilder $builder): User
    {
        $user = $builder->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
