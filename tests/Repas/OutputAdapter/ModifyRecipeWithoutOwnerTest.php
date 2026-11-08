<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\ChangeRecipeFavorite\ChangeRecipeFavoriteCommand;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportMarmitonRecipeCommand;
use Repas\Repas\Application\Command\RemoveRecipe\RemoveRecipeCommand;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\ListAvailableIngredients\ListAvailableIngredientsQuery;
use Repas\Repas\Application\Query\ListDuplicateIngredients\ListDuplicateIngredientsQuery;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\ListIngredientsByDepartmentQuery;
use Repas\Repas\Application\Query\ListIngredientsToFix\ListIngredientsToFixQuery;
use Repas\Repas\Application\Query\SearchIngredients\SearchIngredientsQuery;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
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
 * Un administrateur modifie ou supprime DIRECTEMENT une recette SANS PROPRIÉTAIRE (la neutre d'une recette Marmiton), sur la VRAIE base (ADR 0037,
 * amendement du 2026-11-08, MAR-005) : la neutre prend le contenu envoyé avec des ingrédients du propriétaire réservé (jamais ceux de
 * l'administrateur, jamais en double d'une modification à l'autre), les prochains qui la copient retrouvent leurs propres ingrédients par provenance,
 * les copies déjà faites ne bougent pas, et tout autre utilisateur la trouve figée. Supprimée, elle libère son tag : un nouvel import la recrée.
 */
class ModifyRecipeWithoutOwnerTest extends DatabaseTestCase
{
    private const string OWNER = Recipe::MARMITON_OWNER_ID;

    private DataPersister $persister;
    private MarmitonInMemoryPageReader $reader;
    private CommandBusInterface $commands;
    private QueryBusInterface $queries;
    /** L'administrateur ET propriétaire du catalogue (le compte du propriétaire de l'application). */
    private User $admin;
    private User $alice;
    private User $bob;
    private Ingredient $catalogFlour;
    private Ingredient $catalogEgg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new MarmitonInMemoryPageReader();
        static::getContainer()->set(MarmitonPageReader::class, $this->reader);
        $this->commands = static::getContainer()->get(CommandBusInterface::class);
        $this->queries = static::getContainer()->get(QueryBusInterface::class);

        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isMiscellaneous()->build());
        foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isCoffeeSpoon()] as $unit) {
            $this->persister->saveUnit($unit->build());
        }
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->admin = $this->savedUser(new UserBuilder()->isCatalogOwner());
        $this->alice = $this->savedUser(new UserBuilder());
        $this->bob = $this->savedUser(new UserBuilder());

        // Le catalogue : « Farine » et « Œuf » sont « de base », donc donnés à chaque compte à son activation
        $this->catalogFlour = new IngredientBuilder()->isFlour()->withCreator($this->admin, true)->withBase()->build();
        $this->catalogEgg = new IngredientBuilder()->isEgg()->withCreator($this->admin, true)->withBase()->build();
        $this->persister->saveIngredient($this->catalogFlour);
        $this->persister->saveIngredient($this->catalogEgg);
    }

    // --- Le parcours du propriétaire : corriger la neutre, les suivants reçoivent la version corrigée ---------------------------------------

    public function testTheAdministratorCorrectsTheNeutralAndTheNextCopiersReceiveTheCorrectedVersion(): void
    {
        // Given : la tarte (« 3 oeufs » et les jaunes et blancs à part), importée par Alice (qui n'a rien reçu) ; Bob a reçu le catalogue
        $this->commands->dispatch(new SeedUserIngredientsCommand($this->bob->getId(), 'activation'));
        $this->read('1', 'Tarte', ['250 g de farine', '3 oeufs', '2 jaunes d\'oeuf', '2 blancs d\'oeuf']);
        $aliceCopy = $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $aliceBefore = [$this->rowsOf($aliceCopy), $this->versionOf($aliceCopy)];
        $before = $this->rowsOf($neutralId);
        $this->assertSame(['Farine', 'Œuf', 'Jaunes d\'oeuf', 'Blancs d\'oeuf'], array_column($before, 'name'), 'Marmiton lit les jaunes et les blancs comme deux ingrédients de plus.');
        $reservedBefore = $this->reservedIngredientCount();
        $versionBefore = $this->versionOf($neutralId);
        // Un ingrédient à l'administrateur que la neutre n'a pas : le propriétaire réservé en recevra une copie
        $lemon = new IngredientBuilder()->withName('Citron')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($lemon);

        // When : dans l'éditeur ordinaire, il retire jaunes et blancs, met cinq œufs (la ligne réservée est renvoyée telle quelle), ajoute un citron à lui
        $this->edit($neutralId, [[$before[0]['slug'], 'gramme', 250.0], [$before[1]['slug'], 'unite', 5.0], [$lemon->getSlug(), 'unite', 2.0]], 'tarte au citron sans jaunes', 'dessert', 6);

        // Then : la neutre est ce qu'il a envoyé (nom avec une majuscule), version +1, tag et lien d'origine inchangés, toujours ordinaire et originale
        $neutral = static::getContainer()->get(RecipeRepository::class)->findOneById($neutralId);
        $this->assertSame(['Tarte au citron sans jaunes', 6, 'dessert', $versionBefore + 1], [$neutral->getName(), $neutral->getServing(), $neutral->getType()->getSlug(), $neutral->getVersion()]);
        $this->assertSame(['1', 'https://www.marmiton.org/recettes/recette_page_1.aspx', self::OWNER, null, false, false], [$neutral->getMarmitonId(), $neutral->getSourceUrl(), $neutral->getAuthorId(), $neutral->getOriginalId(), $neutral->isSecret(), $neutral->isFavorite()]);
        $this->assertSame([['Farine', 250.0, 'gramme'], ['Œuf', 5.0, 'unite'], ['Citron', 2.0, 'unite']], array_map(static fn(array $row): array => [$row['name'], $row['quantity'], $row['unit']], $this->rowsOf($neutralId)));

        // Then : tous les ingrédients de la neutre sont au propriétaire réservé, JAMAIS à l'administrateur ; seul « Citron » a été copié, « Œuf » est celui d'avant
        foreach ($this->rowsOf($neutralId) as $row) {
            $this->assertSame(self::OWNER, $row['creator'], $row['name']);
        }
        $this->assertSame($reservedBefore + 1, $this->reservedIngredientCount());
        $this->assertSame($before[1]['slug'], $this->rowsOf($neutralId)[1]['slug']);
        $this->assertSame(1, $this->reservedCountNamed('Œuf'));
        $this->assertSame($lemon->getSlug(), $this->entityManager->getConnection()->fetchOne('SELECT adopted_from FROM ingredient WHERE creator = ? AND name = ?', [self::OWNER, 'Citron']));

        // Then : la copie d'Alice, faite avant, n'a pas bougé
        $this->assertSame($aliceBefore, [$this->rowsOf($aliceCopy), $this->versionOf($aliceCopy)]);

        // When : Bob, qui a reçu le catalogue, copie la recette Marmiton
        $bobsIngredientsBefore = $this->slugsCreatedBy($this->bob);
        $bobCopy = $this->import($this->bob, '1');

        // Then : il reçoit la version corrigée avec SES ingrédients retrouvés par provenance : un seul « Œuf », seul « Citron » (qu'il n'avait pas) lui est copié
        $this->assertSame([['Farine', 250.0, 'gramme'], ['Œuf', 5.0, 'unite'], ['Citron', 2.0, 'unite']], array_map(static fn(array $row): array => [$row['name'], $row['quantity'], $row['unit']], $this->rowsOf($bobCopy)));
        foreach ($this->rowsOf($bobCopy) as $row) {
            $this->assertSame($this->bob->getId(), $row['creator']);
        }
        $this->assertCount(count($bobsIngredientsBefore) + 1, $this->slugsCreatedBy($this->bob));
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM ingredient WHERE creator = ? AND name = 'Œuf'", [$this->bob->getId()]));
        $this->assertSame('Tarte au citron sans jaunes', $this->nameOf($bobCopy));
        $this->assertSame(1, $this->reader->callCount(), 'La page n\'est lue qu\'une fois, à la création de la neutre.');
    }

    public function testSavingAgainWithoutChangingAnythingDuplicatesNoReservedIngredient(): void
    {
        // Given : une neutre corrigée avec un ingrédient de l'administrateur (copié à la première modification)
        $this->read('1', 'Tarte', ['250 g de farine']);
        $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $pepper = new IngredientBuilder()->withName('Poivre')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($pepper);
        $flour = $this->rowsOf($neutralId)[0]['slug'];
        $this->edit($neutralId, [[$flour, 'gramme', 250.0], [$pepper->getSlug(), 'gramme', 5.0]]);
        $reservedAfterFirst = $this->reservedIngredientCount();
        $pepperOfTheNeutral = $this->rowsOf($neutralId)[1]['slug'];

        // When : l'éditeur renvoie la neutre telle quelle (ses lignes réservées), puis l'administrateur renvoie SES ingrédients, deux fois
        $this->edit($neutralId, [[$flour, 'gramme', 250.0], [$pepperOfTheNeutral, 'gramme', 5.0]]);
        $this->edit($neutralId, [[$this->catalogFlour->getSlug(), 'gramme', 300.0], [$pepper->getSlug(), 'gramme', 7.0]]);
        $this->edit($neutralId, [[$this->catalogFlour->getSlug(), 'gramme', 300.0], [$pepper->getSlug(), 'gramme', 7.0]]);

        // Then : aucun ingrédient réservé de plus, le même « Poivre » à chaque fois
        $this->assertSame($reservedAfterFirst, $this->reservedIngredientCount());
        $this->assertSame(1, $this->reservedCountNamed('Poivre'));
        $rows = $this->rowsOf($neutralId);
        $this->assertSame([['Farine', 300.0], ['Poivre', 7.0]], array_map(static fn(array $row): array => [$row['name'], $row['quantity']], $rows));
        $this->assertSame([$flour, $pepperOfTheNeutral], array_column($rows, 'slug'));
        $this->assertSame(5, $this->versionOf($neutralId), 'Création, puis quatre modifications : une version de plus à chacune.');
    }

    public function testAnIngredientOfTheReservedOwnerThatCannotConvertTheUnitIsReplacedOnceAndThenReused(): void
    {
        // Given : « Farine » du propriétaire réservé (copiée du catalogue sans conversion de la cuillère à soupe) ; l'administrateur règle la conversion
        // sur la sienne
        $this->read('1', 'Tarte', ['250 g de farine']);
        $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $oldReservedFlour = $this->rowsOf($neutralId)[0]['slug'];
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($this->catalogFlour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(12)->build());
        static::getContainer()->get(ConversionService::class)->clear();

        // When : il met une cuillère à soupe de SA farine
        $this->edit($neutralId, [[$this->catalogFlour->getSlug(), 'gramme', 250.0], [$this->catalogFlour->getSlug(), 'cuillere-a-soupe', 1.0]]);

        // Then : un deuxième « Farine » réservé, AVEC la conversion de l'administrateur, sert aux deux lignes
        $rows = $this->rowsOf($neutralId);
        $this->assertSame($rows[0]['slug'], $rows[1]['slug']);
        $this->assertNotSame($oldReservedFlour, $rows[0]['slug']);
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM conversion WHERE ingredient = ?', [$rows[0]['slug']]));
        $this->assertSame(2, $this->reservedCountNamed('Farine'));

        // When : modifiée encore
        $this->edit($neutralId, [[$this->catalogFlour->getSlug(), 'gramme', 300.0], [$this->catalogFlour->getSlug(), 'cuillere-a-soupe', 2.0]]);

        // Then : le même deuxième « Farine » est retrouvé (il convertit les deux unités), pas un troisième
        $this->assertSame(2, $this->reservedCountNamed('Farine'));
        $this->assertSame($rows[0]['slug'], $this->rowsOf($neutralId)[0]['slug']);
    }

    public function testAnAdministratorWhoReceivedTheCatalogReusesTheReservedIngredientsOfTheSameOrigin(): void
    {
        // Given : un administrateur qui n'est PAS le propriétaire du catalogue (ses ingrédients sont des copies : leur origine est celle du catalogue)
        $second = $this->savedUser(new UserBuilder()->isAdmin());
        $this->commands->dispatch(new SeedUserIngredientsCommand($second->getId(), 'activation'));
        $this->read('1', 'Tarte', ['250 g de farine', '3 oeufs']);
        $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $reservedBefore = $this->reservedIngredientCount();
        $reservedEgg = $this->rowsOf($neutralId)[1]['slug'];
        $secondsEgg = (string) $this->entityManager->getConnection()->fetchOne("SELECT slug FROM ingredient WHERE creator = ? AND name = 'Œuf'", [$second->getId()]);
        $secondsFlour = (string) $this->entityManager->getConnection()->fetchOne("SELECT slug FROM ingredient WHERE creator = ? AND name = 'Farine'", [$second->getId()]);
        $this->assertNotSame('', $secondsEgg);

        // When : il choisit SES ingrédients, copies de ceux du catalogue
        $this->edit($neutralId, [[$secondsFlour, 'gramme', 251.0], [$secondsEgg, 'unite', 4.0]], userId: $second->getId());

        // Then : l'origine commune a suffi, aucun ingrédient réservé de plus, et ceux de la neutre sont toujours ceux du propriétaire réservé
        $this->assertSame($reservedBefore, $this->reservedIngredientCount());
        $neutralRows = $this->rowsOf($neutralId);
        $this->assertSame($reservedEgg, $neutralRows[1]['slug']);
        $this->assertSame([251.0, 4.0], array_column($neutralRows, 'quantity'));
        foreach ($neutralRows as $row) {
            $this->assertSame(self::OWNER, $row['creator']);
        }
    }

    public function testASubIngredientOfTheAdministratorIsAnOrdinaryIngredientWithNoParentInTheNeutral(): void
    {
        // Given : « Jaune d'œuf » est une partie d'« Œuf » chez l'administrateur (ADR 0038)
        $this->read('1', 'Tarte', ['250 g de farine']);
        $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $yolk = new IngredientBuilder()->withName('Jaune d\'œuf')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($this->admin, true)->withParent($this->catalogEgg->getSlug(), 3.0)->build();
        $this->persister->saveIngredient($yolk);

        // When
        $this->edit($neutralId, [[$this->rowsOf($neutralId)[0]['slug'], 'gramme', 250.0], [$yolk->getSlug(), 'unite', 2.0]]);

        // Then : la ligne de la neutre est un ingrédient réservé ordinaire, jamais relié à un parent (ADR 0038 décision 5), et le parent n'a pas été copié
        $connection = $this->entityManager->getConnection();
        $this->assertSame([], $connection->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator = ? AND (parent_slug IS NOT NULL OR parent_ratio IS NOT NULL)', [self::OWNER]));
        $this->assertSame(1, $this->reservedCountNamed('Jaune d\'œuf'));
        $this->assertSame(0, $this->reservedCountNamed('Œuf'));
        $this->assertSame($this->catalogEgg->getSlug(), $connection->fetchOne('SELECT parent_slug FROM ingredient WHERE slug = ?', [$yolk->getSlug()]), 'L\'ingrédient de l\'administrateur est intact.');
    }

    // --- Ce que la modification ne touche pas ----------------------------------------------------------------------------------------------------

    public function testNeitherTheStatisticsNorTheAccountsAreInvolvedAndTheReservedIngredientsStayInvisible(): void
    {
        // Given
        $this->read('1', 'Tarte', ['250 g de farine']);
        $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $lemon = new IngredientBuilder()->withName('Citron')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($lemon);
        $accountsBefore = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM "user" ORDER BY id');

        // When
        $this->edit($neutralId, [[$this->rowsOf($neutralId)[0]['slug'], 'gramme', 250.0], [$lemon->getSlug(), 'unite', 4.0]]);

        // Then : aucun compte n'a bougé (ni statistiques de recettes ni d'ingrédients créés, administrateur compris)
        $this->assertSame($accountsBefore, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM "user" ORDER BY id'));

        // Then : aucun ingrédient du propriétaire réservé n'est visible d'un compte, nulle part
        $reserved = $this->entityManager->getConnection()->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator = ? ORDER BY slug', [self::OWNER]);
        $this->assertCount(2, $reserved);
        foreach ([$this->admin, $this->alice, $this->bob] as $who) {
            $seen = json_encode([
                $this->queries->ask(new ListAvailableIngredientsQuery($who->getId())),
                $this->queries->ask(new SearchIngredientsQuery('citron', $who->getId())),
                $this->queries->ask(new ListIngredientsByDepartmentQuery('divers', $who->getId())),
                $this->queries->ask(new ListDuplicateIngredientsQuery($who->getId())),
                $this->queries->ask(new ListIngredientsToFixQuery($who->getId())),
            ], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(self::OWNER, $seen);
            foreach ($reserved as $slug) {
                $this->assertStringNotContainsString('"' . $slug . '"', $seen);
            }
        }
    }

    public function testAnOrdinaryUserFindsTheNeutralFrozenAndNothingIsWrittenNotEvenAnIngredient(): void
    {
        // Given
        $this->read('1', 'Tarte', ['250 g de farine']);
        $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $counts = $this->tableCounts();
        $version = $this->versionOf($neutralId);

        // When & Then : même si le contrôleur avait oublié le droit, la garde du modèle refuse (403) ; modifier, mettre en favoris, rendre secrète
        $attempts = [
            'modifier' => fn() => $this->commands->dispatch(new UpdateRecipeCommand($neutralId, 'Autre', 2, $this->rows([$this->catalogFlour->getSlug(), 'gramme', 1.0]), 'plat', userId: $this->alice->getId())),
            'rendre secrète' => fn() => $this->commands->dispatch(new UpdateRecipeCommand($neutralId, 'Tarte', 4, $this->rows([$this->rowsOf($neutralId)[0]['slug'], 'gramme', 250.0]), 'plat', secret: true, userId: $this->admin->getId(), byAdministrator: true)),
            'favori' => fn() => $this->commands->dispatch(new ChangeRecipeFavoriteCommand($neutralId, $this->admin->getId(), true)),
            'supprimer' => fn() => $this->commands->dispatch(new RemoveRecipeCommand($neutralId)),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $this->fail("$name aurait dû être refusé.");
            } catch (\Throwable $exception) {
                $cause = $exception->getPrevious() ?? $exception;
                $this->assertInstanceOf(RecipeException::class, $cause, $name);
                $this->assertSame(403, $cause->getCode(), $name);
            }
        }
        $this->entityManager->clear();
        $this->assertSame($counts, $this->tableCounts());
        $this->assertSame($version, $this->versionOf($neutralId));
        $this->assertSame('Tarte', $this->nameOf($neutralId));
    }

    public function testARefusedModificationRollsBackTheIngredientsAlreadyCopied(): void
    {
        // Given : une ligne convertible (un ingrédient copié) PUIS une qui ne l'est pas
        $this->read('1', 'Tarte', ['250 g de farine']);
        $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $lemon = new IngredientBuilder()->withName('Citron')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreator($this->admin, true)->build();
        $this->persister->saveIngredient($lemon);
        $counts = $this->tableCounts();
        $version = $this->versionOf($neutralId);

        // When & Then : une cuillère à soupe de citron ne se convertit pas en unités
        try {
            $this->edit($neutralId, [[$lemon->getSlug(), 'unite', 1.0], [$lemon->getSlug(), 'cuillere-a-soupe', 1.0]]);
            $this->fail('Une unité sans conversion aurait dû être refusée.');
        } catch (\Throwable $exception) {
            $this->assertSame(422, ($exception->getPrevious() ?? $exception)->getCode());
        }
        $this->entityManager->clear();
        $this->assertSame($counts, $this->tableCounts());
        $this->assertSame($version, $this->versionOf($neutralId));
    }

    // --- Supprimer la recette sans propriétaire ---------------------------------------------------------------------------------------------------

    public function testTheAdministratorRemovesTheNeutralTheCopiesStayTheirAuthorsAndNothingKeepsItsIdentifier(): void
    {
        // Given : la neutre, la copie d'Alice (qui la modifie ensuite à sa façon) et celle de Bob
        $this->read('1', 'Tarte', ['250 g de farine', '3 oeufs']);
        $aliceCopy = $this->import($this->alice, '1');
        $bobCopy = $this->import($this->bob, '1');
        $neutralId = $this->neutralId('1');
        $reservedIngredients = $this->reservedIngredientCount();
        $aliceRows = $this->rowsOf($aliceCopy);
        $connection = $this->entityManager->getConnection();

        // When
        $this->commands->dispatch(new RemoveRecipeCommand($neutralId, byAdministrator: true));
        $this->entityManager->clear();

        // Then : la neutre et ses lignes ont disparu, aucune table ne garde son identifiant, son tag est libéré
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM recipe WHERE id = ? OR marmiton_id = ?', [$neutralId, '1']));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM recipe_row WHERE recipe = ?', [$neutralId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM meal WHERE recipe = ?', [$neutralId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM spoken_recipe_phrase_choice WHERE recipe_id = ?', [$neutralId]));
        $this->assertNull(static::getContainer()->get(RecipeRepository::class)->findMarmitonNeutralId('1'));
        // Les ingrédients réservés, inoffensifs, restent
        $this->assertSame($reservedIngredients, $this->reservedIngredientCount());

        // Then : les copies restent à leurs auteurs, telles quelles, avec leur lien d'origine, lisibles ET modifiables par eux
        $loader = static::getContainer()->get(RecipeLoader::class);
        $alice = $loader->get($aliceCopy, $this->alice->getId());
        $this->assertSame([$aliceCopy, 'https://www.marmiton.org/recettes/recette_page_1.aspx', true, null], [$alice->id, $alice->sourceUrl, $alice->canEdit, $alice->originalId]);
        $this->assertSame($aliceRows, $this->rowsOf($aliceCopy));
        $this->assertSame(2, count($this->rowsOf($bobCopy)));
        $this->commands->dispatch(new UpdateRecipeCommand($aliceCopy, 'Ma tarte', 4, $this->rows([$aliceRows[0]['slug'], 'gramme', 100.0]), 'plat', userId: $this->alice->getId()));
        $this->entityManager->clear();
        $this->assertSame('Ma tarte', $this->nameOf($aliceCopy));
    }

    public function testAfterTheRemovalANewImportOfTheSamePageReadsItAgainAndCreatesAFreshNeutral(): void
    {
        // Given : un import raté (Alice l'a copié), supprimé par l'administrateur
        $this->read('1', 'Tarte ratée', ['250 g de farine']);
        $aliceCopy = $this->import($this->alice, '1');
        $this->commands->dispatch(new RemoveRecipeCommand($this->neutralId('1'), byAdministrator: true));
        $this->entityManager->clear();
        $this->read('1', 'Tarte', ['250 g de farine', '3 oeufs']);

        // When : Bob importe la même page, puis Alice
        $bobCopy = $this->import($this->bob, '1');
        $aliceSecondCopy = $this->import($this->alice, '1');

        // Then : la page est relue, une neutre neuve est créée, Bob en reçoit la copie ; Alice, dont la copie d'avant n'est plus rattachée à rien, en reçoit
        // une seconde (limite connue : « déjà copié » passe par la neutre)
        $this->assertSame(2, $this->reader->callCount());
        $this->assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM recipe WHERE marmiton_id = ?', ['1']));
        $this->assertSame('Tarte', $this->nameOf($bobCopy));
        $this->assertSame(2, count($this->rowsOf($bobCopy)));
        $this->assertNotSame($aliceCopy, $aliceSecondCopy);
        $this->assertSame('Tarte ratée', $this->nameOf($aliceCopy));
    }

    public function testANeutralStillPlannedInAListIsNotRemoved(): void
    {
        // Given : une neutre planifiée dans une liste (un repas d'avant la règle qui interdit de planifier la recette d'un autre)
        $this->read('1', 'Tarte', ['250 g de farine']);
        $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $list = new \Repas\Tests\Helper\Builder\ShoppingListBuilder()->withOwner($this->alice)->build();
        static::getContainer()->get(\Repas\Repas\Domain\Interface\ShoppingListRepository::class)->save($list);
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO meal (id, shopping_list, recipe, serving) VALUES (?, ?, ?, 4)',
            [UuidGenerator::new(), $list->getId(), $neutralId],
        );
        $this->entityManager->clear();

        // When & Then : les règles d'une suppression ne changent pas (`409`), rien n'est effacé
        try {
            $this->commands->dispatch(new RemoveRecipeCommand($neutralId, byAdministrator: true));
            $this->fail('Une recette planifiée ne se supprime pas.');
        } catch (\Throwable $exception) {
            $cause = $exception->getPrevious() ?? $exception;
            $this->assertInstanceOf(RecipeException::class, $cause);
            $this->assertSame(409, $cause->getCode());
        }
        $this->entityManager->clear();
        $this->assertSame($neutralId, $this->neutralId('1'));
        $this->assertGreaterThan(0, count($this->rowsOf($neutralId)));
    }

    // --- Retrouver la neutre : « Recettes des autres » de l'administrateur ----------------------------------------------------------------------

    public function testTheAdministratorSeesTheNeutralInOtherPeopleRecipesEvenWhenHeAlreadyCopiedItAndSaysSo(): void
    {
        // Given : une neutre, copiée par l'administrateur ET par Bob ; une recette ordinaire d'Alice, copiée par l'administrateur
        $this->read('1', 'Tarte', ['250 g de farine']);
        $adminCopy = $this->import($this->admin, '1');
        $this->import($this->bob, '1');
        $neutralId = $this->neutralId('1');
        $alices = $this->savedOrdinaryRecipe($this->alice, 'Crêpes');
        $alicesToo = $this->savedOrdinaryRecipe($this->alice, 'Gaufres');
        $this->commands->dispatch(new CopyRecipeCommand($alices, $this->admin->getId(), UuidGenerator::new()));
        $this->entityManager->clear();
        $loader = static::getContainer()->get(RecipeLoader::class);
        $counter = static::getContainer()->get(QueryCounter::class);

        // When
        $counter->reset();
        $asAdmin = $loader->listOfOtherPeople($this->admin->getId(), 200, 0, null, true);
        $queriesOfTheAdmin = $counter->count();
        $this->entityManager->clear();
        $asBob = $loader->listOfOtherPeople($this->bob->getId(), 200, 0, null, false);
        $asAdminWithoutTheRight = $loader->listOfOtherPeople($this->admin->getId(), 200, 0);
        $searched = $loader->listOfOtherPeople($this->admin->getId(), 200, 0, 'tarte', true);

        // Then : l'administrateur voit la neutre déjà copiée (et le dit), la recette d'Alice qu'il n'a pas copiée, pas celle qu'il a copiée
        $byId = [];
        foreach ($asAdmin->member as $recipe) {
            $byId[$recipe->id] = [$recipe->fromMarmiton, $recipe->alreadyCopied, $recipe->copyId];
        }
        $this->assertEquals([$neutralId => [true, true, $adminCopy], $alicesToo => [false, false, null]], $byId);
        $this->assertSame(2, $asAdmin->totalItems);
        $this->assertSame(1, $queriesOfTheAdmin, 'Une seule requête, comme pour un autre utilisateur : la copie déjà faite est lue dans la même instruction.');

        // Then : Bob ne voit pas la neutre qu'il a copiée, la réponse d'un autre utilisateur ne change pas ; sans le droit, l'administrateur est un utilisateur ordinaire
        $this->assertSame([$alices, $alicesToo], array_column($asBob->member, 'id'));
        $this->assertSame([false, false], array_map(static fn($recipe): bool => $recipe->alreadyCopied, $asBob->member));
        $this->assertSame([$alicesToo], array_column($asAdminWithoutTheRight->member, 'id'));

        // Then : la recherche de l'administrateur trouve la neutre copiée aussi
        $this->assertSame([$neutralId], array_column($searched->member, 'id'));
        $this->assertSame([true], array_map(static fn($recipe): bool => $recipe->alreadyCopied, $searched->member));
    }

    public function testTheSheetSaysWhoMayEditItAndWhichRecipeItIsTheCopyOfWithoutAnyExtraQuery(): void
    {
        // Given : la neutre, la copie d'Alice, une recette ordinaire d'Alice
        $this->read('1', 'Tarte', ['250 g de farine']);
        $aliceCopy = $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $ordinary = $this->savedOrdinaryRecipe($this->alice, 'Crêpes');
        $this->entityManager->clear();
        $loader = static::getContainer()->get(RecipeLoader::class);
        $counter = static::getContainer()->get(QueryCounter::class);

        // When
        $sheets = [];
        $queries = [];
        $cases = [
            'neutre, administrateur' => [$neutralId, $this->admin, true],
            'neutre, autre utilisateur' => [$neutralId, $this->bob, false],
            'copie, son auteur' => [$aliceCopy, $this->alice, false],
            'copie, administrateur' => [$aliceCopy, $this->admin, true],
            'ordinaire, autre utilisateur' => [$ordinary, $this->bob, false],
            'ordinaire, son auteur' => [$ordinary, $this->alice, false],
        ];
        foreach ($cases as $name => [$id, $user, $isAdministrator]) {
            $this->entityManager->clear();
            $counter->reset();
            $sheets[$name] = $loader->get($id, $user->getId(), $isAdministrator);
            $queries[$name] = $counter->count();
        }

        // Then : modifiable par son auteur, par un administrateur (dont la recette sans propriétaire), jamais par un autre utilisateur
        $this->assertSame(
            ['neutre, administrateur' => true, 'neutre, autre utilisateur' => false, 'copie, son auteur' => true, 'copie, administrateur' => true, 'ordinaire, autre utilisateur' => false, 'ordinaire, son auteur' => true],
            array_map(static fn($sheet): bool => $sheet->canEdit, $sheets),
        );
        // Then : l'original de la copie, rien pour une recette originale ; la même quantité de requêtes pour toutes
        $this->assertSame($neutralId, $sheets['copie, son auteur']->originalId);
        $this->assertNull($sheets['neutre, administrateur']->originalId);
        $this->assertNull($sheets['ordinaire, son auteur']->originalId);
        $this->assertCount(1, array_unique($queries), 'La lecture coûte autant de requêtes pour toute recette : ' . json_encode($queries));
    }

    // --- « Version commune » et copie de l'utilisateur : le front n'a rien à deviner ---------------------------------------------------------

    public function testTheSheetOfARecipeWithoutOwnerSaysSoAndNamesTheCopyOfTheReaderWhateverItBecame(): void
    {
        // Given : la neutre, copiée par Alice (qui la renomme, change son type et la rend secrète) et par Bob ; une recette ordinaire d'Alice
        $this->read('1', 'Tarte', ['250 g de farine']);
        $aliceCopy = $this->import($this->alice, '1');
        $bobCopy = $this->import($this->bob, '1');
        $neutralId = $this->neutralId('1');
        $this->entityManager->getConnection()->executeStatement("UPDATE recipe SET name = 'Mon dessert', slug = 'mon-dessert', type = 'dessert', secret = TRUE WHERE id = ?", [$aliceCopy]);
        $ordinary = $this->savedOrdinaryRecipe($this->alice, 'Crêpes');
        $loader = static::getContainer()->get(RecipeLoader::class);
        $counter = static::getContainer()->get(QueryCounter::class);

        // When
        $cases = [
            'neutre, Alice' => [$neutralId, $this->alice, false],
            'neutre, Bob' => [$neutralId, $this->bob, false],
            'neutre, administrateur sans copie' => [$neutralId, $this->admin, true],
            'copie d\'Alice, Alice' => [$aliceCopy, $this->alice, false],
            'ordinaire, Alice' => [$ordinary, $this->alice, false],
            'ordinaire, administrateur' => [$ordinary, $this->admin, true],
        ];
        $sheets = [];
        $queries = [];
        foreach ($cases as $name => [$id, $user, $isAdministrator]) {
            $this->entityManager->clear();
            $counter->reset();
            $sheets[$name] = $loader->get($id, $user->getId(), $isAdministrator);
            $queries[$name] = $counter->count();
        }

        // Then : la copie du lecteur, la sienne (même secrète, renommée, d'un autre type), et nulle part ailleurs
        $this->assertSame(
            ['neutre, Alice' => [true, $aliceCopy], 'neutre, Bob' => [true, $bobCopy], 'neutre, administrateur sans copie' => [true, null], 'copie d\'Alice, Alice' => [false, null], 'ordinaire, Alice' => [false, null], 'ordinaire, administrateur' => [false, null]],
            array_map(static fn($sheet): array => [$sheet->ownerless, $sheet->copyId], $sheets),
        );
        $this->assertCount(1, array_unique($queries), 'Aucune requête de plus : ' . json_encode($queries));
    }

    public function testWithSeveralCopiesTheOldestOneIsNamed(): void
    {
        // Given : Alice a deux copies de la neutre (un réimport après une suppression en laisse deux)
        $this->read('1', 'Tarte', ['250 g de farine']);
        $first = $this->import($this->alice, '1');
        $neutralId = $this->neutralId('1');
        $second = UuidGenerator::new();
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO recipe (id, name, slug, serving, author, type, orignal, version, secret, favorite, source_url) SELECT ?, name, slug, serving, author, type, orignal, version, secret, favorite, source_url FROM recipe WHERE id = ?',
            [$second, $first],
        );
        $oldest = min($first, $second);
        $loader = static::getContainer()->get(RecipeLoader::class);

        // When
        $this->entityManager->clear();
        $sheet = $loader->get($neutralId, $this->alice->getId());

        // Then : l'ordre est celui de l'identifiant (une recette n'a pas de date de création), et la fiche garde une seule réponse
        $this->assertSame($oldest, $sheet->copyId);
    }

    public function testACopyWhoseNeutralWasRemovedIsNotAVersionCommuneAnymore(): void
    {
        // Given : Alice a copié la neutre, que l'administrateur supprime ensuite
        $this->read('1', 'Tarte', ['250 g de farine']);
        $aliceCopy = $this->import($this->alice, '1');
        $this->commands->dispatch(new RemoveRecipeCommand($this->neutralId('1'), byAdministrator: true));
        $this->entityManager->clear();

        // When
        $sheet = static::getContainer()->get(RecipeLoader::class)->get($aliceCopy, $this->alice->getId());

        // Then : une recette ordinaire, à elle, qui garde son lien Marmiton mais n'est pas « sans propriétaire »
        $this->assertSame([false, null, null], [$sheet->ownerless, $sheet->copyId, $sheet->originalId]);
        $this->assertNotNull($sheet->sourceUrl);
    }

    public function testTheAdministratorPageNamesTheCopyEvenRenamedOrMovedAndTheOldestWhenThereAreSeveral(): void
    {
        // Given : deux neutres ; l'administrateur a renommé et déplacé sa copie de la première, et a deux copies de la seconde ; Alice a copié la première
        $this->read('1', 'Tarte', ['250 g de farine']);
        $this->read('2', 'Gratin', ['250 g de farine']);
        $copyOfFirst = $this->import($this->admin, '1');
        $copyOfSecond = $this->import($this->admin, '2');
        $this->import($this->alice, '1');
        $first = $this->neutralId('1');
        $second = $this->neutralId('2');
        $this->entityManager->getConnection()->executeStatement("UPDATE recipe SET name = 'Autre chose', slug = 'autre-chose', type = 'dessert' WHERE id = ?", [$copyOfFirst]);
        $extra = UuidGenerator::new();
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO recipe (id, name, slug, serving, author, type, orignal, version, secret, favorite, source_url) SELECT ?, name, slug, serving, author, type, orignal, version, secret, favorite, source_url FROM recipe WHERE id = ?',
            [$extra, $copyOfSecond],
        );
        $loader = static::getContainer()->get(RecipeLoader::class);
        $counter = static::getContainer()->get(QueryCounter::class);

        // When
        $this->entityManager->clear();
        $counter->reset();
        $page = $loader->listOfOtherPeople($this->admin->getId(), 200, 0, null, true);
        $queries = $counter->count();
        $this->entityManager->clear();
        $searched = $loader->listOfOtherPeople($this->admin->getId(), 200, 0, 'gratin', true);
        $this->entityManager->clear();
        $asAlice = $loader->listOfOtherPeople($this->alice->getId(), 200, 0);

        // Then : une ligne par recette (la jointure ne multiplie rien), la copie trouvée, `alreadyCopied` vrai si et seulement si `copyId` existe
        $byId = [];
        foreach ($page->member as $recipe) {
            $byId[$recipe->id] = [$recipe->copyId, $recipe->alreadyCopied];
        }
        $this->assertEquals([$first => [$copyOfFirst, true], $second => [min($copyOfSecond, $extra), true]], $byId);
        $this->assertSame(2, $page->totalItems);
        $this->assertSame(1, $queries);
        $this->assertSame([min($copyOfSecond, $extra)], array_map(static fn($recipe): ?string => $recipe->copyId, $searched->member));

        // Then : un autre utilisateur ne reçoit jamais de copyId (sa liste ne contient que ce qu'il n'a pas copié)
        $this->assertSame([$second], array_column($asAlice->member, 'id'));
        $this->assertSame([null], array_map(static fn($recipe): ?string => $recipe->copyId, $asAlice->member));
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
     * L'administrateur (ou `$userId`) modifie la recette avec l'éditeur ordinaire : `[ingrédient, unité, quantité]` par ligne.
     *
     * @param list<array{0: string, 1: string, 2: float}> $rows
     */
    private function edit(string $recipeId, array $rows, string $name = 'Tarte', string $typeSlug = 'plat', int $serving = 4, ?string $userId = null): void
    {
        $this->commands->dispatch(new UpdateRecipeCommand($recipeId, $name, $serving, $this->rows(...$rows), $typeSlug, userId: $userId ?? $this->admin->getId(), byAdministrator: true));
        $this->entityManager->clear();
    }

    /**
     * @param array{0: string, 1: string, 2: float} ...$rows
     * @return Tab<UpdateRecipeRowSubCommand>
     */
    private function rows(array ...$rows): Tab
    {
        return Tab::fromArray(...array_map(static fn(array $row): UpdateRecipeRowSubCommand => new UpdateRecipeRowSubCommand($row[0], $row[1], $row[2]), $rows));
    }

    private function neutralId(string $marmitonId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT id FROM recipe WHERE marmiton_id = ?', [$marmitonId]);
    }

    private function versionOf(string $recipeId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT version FROM recipe WHERE id = ?', [$recipeId]);
    }

    private function nameOf(string $recipeId): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT name FROM recipe WHERE id = ?', [$recipeId]);
    }

    /**
     * @return list<array{slug: string, name: string, creator: string, quantity: float, unit: string}> les lignes de la recette, dans l'ordre
     */
    private function rowsOf(string $recipeId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT i.slug, i.name, i.creator, rr.quantity, rr.unit FROM recipe_row rr JOIN ingredient i ON i.slug = rr.ingredient WHERE rr.recipe = ? ORDER BY rr.position',
            [$recipeId],
        );

        return array_map(static fn(array $row): array => ['slug' => $row['slug'], 'name' => $row['name'], 'creator' => $row['creator'], 'quantity' => (float) $row['quantity'], 'unit' => $row['unit']], $rows);
    }

    private function reservedIngredientCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [self::OWNER]);
    }

    private function reservedCountNamed(string $name): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND name = ?', [self::OWNER, $name]);
    }

    /**
     * @return list<string>
     */
    private function slugsCreatedBy(User $user): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator = ? ORDER BY slug', [$user->getId()]);
    }

    /**
     * @return array<string, int> le nombre de lignes des tables que l'application écrit
     */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['recipe', 'recipe_row', 'ingredient', 'conversion', 'ingredient_alias'] as $table) {
            $counts[$table] = (int) $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM $table");
        }

        return $counts;
    }

    private function savedOrdinaryRecipe(User $author, string $name): string
    {
        $flour = new IngredientBuilder()->isFlour()->withCreator($author, true)->build();
        $this->persister->saveIngredient($flour);
        $recipe = new RecipeBuilder()->withAuthor($author)->withName($name)->build();
        $recipe->addRow($flour, new UnitBuilder()->isGramme()->build(), 100.0);
        $this->persister->saveRecipe($recipe);
        $this->entityManager->clear();

        return $recipe->getId();
    }

    private function savedUser(UserBuilder $builder): User
    {
        $user = $builder->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
