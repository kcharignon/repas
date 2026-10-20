<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Copier la recette d'un autre, de bout en bout, avec les vrais bus, la vraie base, les listeners et le voter (ADR 0028 décision 8,
 * ING-004) : le rapprochement des ingrédients, JAMAIS d'écrasement d'un ingrédient du copieur, puis la suite d'un parcours réel
 * (planifier la copie, liste de courses, plan d'envoi du drive qui lit les produits mémorisés du copieur).
 *
 * Les communs sont la farine et le sucre (rayon boulangerie, achetés au kilo, comptés au gramme).
 */
class RecipeCopyApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isFlour()->build());
        $this->persister->saveIngredient(new IngredientBuilder()->isSugar()->build());
    }

    public function testACopierWhoAdoptedGetsHisOwnIngredientsAndKeepsEveryExistingOneThenPlansAndSendsTheCopy(): void
    {
        // Given : l'auteur a adopté farine et sucre et a son « Praliné » ; le copieur a adopté la farine, a son propre « Praliné » (même rayon,
        // même unité, une conversion à lui) et mémorisé un produit du drive pour sa farine
        $author = $this->savedUser();
        $copier = $this->savedUser();
        $authorFlour = $this->adopt($author, 'farine');
        $authorSugar = $this->adopt($author, 'sucre');
        $authorPraline = $this->savedPersonal('Praliné', $author);
        $copierFlour = $this->adopt($copier, 'farine');
        $copierPraline = $this->savedPersonal('Praliné', $copier);
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($copierPraline)->withStartUnit(new UnitBuilder()->isKilo())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(900)->build());
        static::getContainer()->get(DriveSettingsRepository::class)->save(new DriveSettingsBuilder()->withUserId($copier->getId())->build());
        $this->request('PUT', '/api/v1/drive/choices', $copier, $this->choicesBody($copierFlour, '555111'));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->request('POST', '/api/v1/recipes', $author, $this->recipeBody($authorFlour, $authorSugar, $authorPraline->getSlug()));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $original = basename($this->client->getResponse()->headers->get('Location'));
        $ingredientsBefore = $this->scalar('SELECT COUNT(*) FROM ingredient');
        $conversionsBefore = $this->scalar('SELECT COUNT(*) FROM conversion');
        $prelineBefore = $this->row('SELECT name, department, default_cooking_unit, default_purchase_unit, image, adopted_from FROM ingredient WHERE slug = ?', $copierPraline->getSlug());

        // When : le copieur copie la recette de l'auteur
        $this->request('POST', "/api/v1/recipes/$original/copy", $copier);

        // Then : 201, et la copie utilise SES ingrédients : farine (alias), sucre (adopté à la volée), Praliné (le sien, même nom et même rayon)
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copy = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertEqualsCanonicalizing([$copierFlour, 'sucre' . $copier->getId(), $copierPraline->getSlug()], $this->recipeSlugs($copy));
        // Rien d'écrasé, pas de doublon : un seul ingrédient de plus (le sucre adopté), ses conversions ne sont pas dupliquées
        $this->assertSame($ingredientsBefore + 1, $this->scalar('SELECT COUNT(*) FROM ingredient'));
        $this->assertSame($conversionsBefore, $this->scalar('SELECT COUNT(*) FROM conversion'), 'Le sucre n\'a pas de conversion propre ; aucune autre n\'est dupliquée.');
        $this->assertSame($prelineBefore, $this->row('SELECT name, department, default_cooking_unit, default_purchase_unit, image, adopted_from FROM ingredient WHERE slug = ?', $copierPraline->getSlug()));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM conversion WHERE ingredient = ?', $copierPraline->getSlug()));
        // Une adoption et des liens ne sont pas des créations
        $this->assertSame(0, $this->createdIngredients($copier));
        // L'original n'a pas bougé
        $this->assertEqualsCanonicalizing([$authorFlour, $authorSugar, $authorPraline->getSlug()], $this->recipeSlugs($original));

        // When : il planifie la copie dans sa liste active, puis l'envoie au drive
        $listId = $this->savedActiveList($copier);
        $this->request('PUT', '/api/v1/shopping-lists/active/recipes/' . $copy, $copier);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : sa liste porte ses ingrédients
        $this->assertEqualsCanonicalizing([$copierFlour, 'sucre' . $copier->getId(), $copierPraline->getSlug()], $this->listSlugs($listId));
        $this->request('POST', "/api/v1/shopping-lists/$listId/send", $copier);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // When / Then : le plan d'envoi retrouve le produit mémorisé de SA farine
        $this->request('GET', "/api/v1/drive/plans/$listId", $copier);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $lines = array_column($this->jsonBody()['lines'], null, 'ingredientSlug');
        $this->assertSame('KNOWN', $lines[$copierFlour]['status']);
        $this->assertSame(['555111'], array_column($lines[$copierFlour]['products'], 'retailerProductId'));
        $this->assertSame('NEW', $lines['sucre' . $copier->getId()]['status']);
    }

    public function testAnIngredientOfTheCopierIsNeverOverwrittenByTheOneOfTheAuthor(): void
    {
        // Given : le copieur a « Praliné » au rayon boulangerie avec une conversion à lui ; l'auteur a « Praliné » dans un autre rayon,
        // acheté à l'unité (même nom, donc même slug de base : c'était l'écrasement)
        $author = $this->savedUser();
        $copier = $this->savedUser();
        $theirs = $this->savedPersonal('Praliné', $copier);
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($theirs)->withStartUnit(new UnitBuilder()->isKilo())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(900)->build());
        $authors = $this->saved(new IngredientBuilder()->withName('Praliné')->withDepartment(new DepartmentBuilder()->isCheese())->withDefaultCookingUnit(new UnitBuilder()->isUnite())->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withImage('images/author.png')->withCreator($author, true));
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($authors)->withStartUnit(new UnitBuilder()->isUnite())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(35)->build());
        $this->request('POST', '/api/v1/recipes', $author, ['name' => 'Gâteau', 'serving' => 4, 'typeSlug' => 'plat', 'rows' => [['ingredientSlug' => $authors->getSlug(), 'unitSlug' => 'unite', 'quantity' => 3]]]);
        $original = basename($this->client->getResponse()->headers->get('Location'));
        $before = $this->row('SELECT name, department, default_cooking_unit, default_purchase_unit, image, compatible_units FROM ingredient WHERE slug = ?', $theirs->getSlug());

        // When
        $this->request('POST', "/api/v1/recipes/$original/copy", $copier);

        // Then : une copie au slug suivant, l'ingrédient du copieur est intact, avec sa seule conversion
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copy = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertSame(['praline-2' . $copier->getId()], $this->recipeSlugs($copy));
        $this->assertSame($before, $this->row('SELECT name, department, default_cooking_unit, default_purchase_unit, image, compatible_units FROM ingredient WHERE slug = ?', $theirs->getSlug()));
        $this->assertSame([900.0], array_map('floatval', $this->column('SELECT coefficient FROM conversion WHERE ingredient = ?', $theirs->getSlug())));
        // La copie a les unités, le rayon, l'image et la conversion de l'auteur
        $this->assertSame(['Praliné', 'fromage', 'unite', 'unite', 'images/author.png'], $this->row('SELECT name, department, default_cooking_unit, default_purchase_unit, image FROM ingredient WHERE slug = ?', 'praline-2' . $copier->getId()));
        $this->assertSame([35.0], array_map('floatval', $this->column('SELECT coefficient FROM conversion WHERE ingredient = ?', 'praline-2' . $copier->getId())));
        // Une vraie création : elle est comptée
        $this->assertSame(1, $this->createdIngredients($copier));
    }

    public function testSomeoneWhoAdoptedNothingKeepsTheCommonsAndGetsACopyOfThePersonalIngredients(): void
    {
        // Given : un auteur et un copieur sans aucun alias (la production aujourd'hui) ; la recette mêle des communs et un ingrédient personnel
        $author = $this->savedUser();
        $copier = $this->savedUser();
        $authorPraline = $this->savedPersonal('Praliné', $author);
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($authorPraline)->withStartUnit(new UnitBuilder()->isKilo())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(950)->build());
        $this->request('POST', '/api/v1/recipes', $author, $this->recipeBody('farine', 'sucre', $authorPraline->getSlug()));
        $original = basename($this->client->getResponse()->headers->get('Location'));

        // When
        $this->request('POST', "/api/v1/recipes/$original/copy", $copier);

        // Then : comme avant, les communs partagés et une copie personnelle avec sa conversion, sans alias ni adoption
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copy = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertEqualsCanonicalizing(['farine', 'sucre', 'praline' . $copier->getId()], $this->recipeSlugs($copy));
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM ingredient_alias'));
        $this->assertSame([null], $this->column('SELECT adopted_from FROM ingredient WHERE slug = ?', 'praline' . $copier->getId()));
        $this->assertSame([950.0], array_map('floatval', $this->column('SELECT coefficient FROM conversion WHERE ingredient = ?', 'praline' . $copier->getId())));
        $this->assertSame(1, $this->createdIngredients($copier));

        // When : il copie la même recette une seconde fois
        $this->request('POST', "/api/v1/recipes/$original/copy", $copier);

        // Then : il retrouve sa copie, aucun doublon, aucune conversion de plus
        $second = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertEqualsCanonicalizing(['farine', 'sucre', 'praline' . $copier->getId()], $this->recipeSlugs($second));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM ingredient WHERE creator = ?', $copier->getId()));
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM conversion WHERE ingredient = ?', 'praline' . $copier->getId()));
        $this->assertSame(1, $this->createdIngredients($copier));
    }

    public function testCopyingYourOwnRecipeCreatesNothingAndDuplicatesNoConversion(): void
    {
        // Given
        $user = $this->savedUser();
        $praline = $this->savedPersonal('Praliné', $user);
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($praline)->withStartUnit(new UnitBuilder()->isKilo())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(950)->build());
        $this->request('POST', '/api/v1/recipes', $user, $this->recipeBody('farine', 'sucre', $praline->getSlug()));
        $original = basename($this->client->getResponse()->headers->get('Location'));
        $ingredients = $this->scalar('SELECT COUNT(*) FROM ingredient');
        $conversions = $this->scalar('SELECT COUNT(*) FROM conversion');

        // When
        $this->request('POST', "/api/v1/recipes/$original/copy", $user);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $copy = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertEqualsCanonicalizing($this->recipeSlugs($original), $this->recipeSlugs($copy));
        $this->assertSame($ingredients, $this->scalar('SELECT COUNT(*) FROM ingredient'));
        $this->assertSame($conversions, $this->scalar('SELECT COUNT(*) FROM conversion'));
        $this->assertSame(0, $this->createdIngredients($user));
    }

    public function testAnIngredientOfAThirdUserIsNeverLinked(): void
    {
        // Given : un troisième utilisateur a « Praliné » au même rayon ; le copieur n'a rien
        $author = $this->savedUser();
        $copier = $this->savedUser();
        $third = $this->savedUser();
        $thirds = $this->savedPersonal('Praliné', $third);
        $authors = $this->savedPersonal('Praliné', $author);
        $this->request('POST', '/api/v1/recipes', $author, $this->recipeBody('farine', 'sucre', $authors->getSlug()));
        $original = basename($this->client->getResponse()->headers->get('Location'));

        // When
        $this->request('POST', "/api/v1/recipes/$original/copy", $copier);

        // Then : le copieur reçoit SA copie, jamais l'ingrédient d'un tiers
        $copy = basename($this->client->getResponse()->headers->get('Location'));
        $this->assertContains('praline' . $copier->getId(), $this->recipeSlugs($copy));
        $this->assertNotContains($thirds->getSlug(), $this->recipeSlugs($copy));
    }

    /**
     * @return array<string, mixed>
     */
    private function recipeBody(string $flour, string $sugar, string $praline): array
    {
        return [
            'name' => 'Pâte sablée',
            'serving' => 4,
            'typeSlug' => 'plat',
            'rows' => [
                ['ingredientSlug' => $flour, 'unitSlug' => 'gramme', 'quantity' => 250],
                ['ingredientSlug' => $sugar, 'unitSlug' => 'gramme', 'quantity' => 100],
                ['ingredientSlug' => $praline, 'unitSlug' => 'gramme', 'quantity' => 50],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function choicesBody(string $ingredientSlug, string $retailerProductId): array
    {
        return ['retailer' => 'leclerc', 'storeId' => '123456', 'choices' => [
            ['ingredientSlug' => $ingredientSlug, 'action' => 'ADD_PRODUCT', 'placement' => 'FALLBACK', 'product' => ['retailerProductId' => $retailerProductId, 'label' => 'Farine ' . $retailerProductId]],
        ]];
    }

    private function adopt(User $user, string $commonSlug): string
    {
        return static::getContainer()->get(CommandBusInterface::class)->dispatch(new AdoptCommonIngredientCommand($user->getId(), $commonSlug, 'migration-2026-10-21'));
    }

    private function savedPersonal(string $name, User $creator): Ingredient
    {
        return $this->saved(
            new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isBakery())
                ->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->withCreator($creator, true),
        );
    }

    private function saved(IngredientBuilder $builder): Ingredient
    {
        $ingredient = $builder->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function savedActiveList(User $owner): string
    {
        $list = new ShoppingListBuilder()->withOwner($owner)->build();
        static::getContainer()->get(ShoppingListRepository::class)->save($list);

        return $list->getId();
    }

    /**
     * @return list<string>
     */
    private function recipeSlugs(string $recipeId): array
    {
        return $this->column('SELECT ingredient FROM recipe_row WHERE recipe = ?', $recipeId);
    }

    /**
     * @return list<string>
     */
    private function listSlugs(string $listId): array
    {
        return $this->column('SELECT ingredient FROM shopping_list_row WHERE shopping_list = ?', $listId);
    }

    private function createdIngredients(User $user): int
    {
        $this->entityManager->clear();

        return (int) (static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->getStatistics()['ingredients'] ?? 0);
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
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

    /**
     * @return list<string>
     */
    private function column(string $sql, string ...$params): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getConnection()->fetchFirstColumn($sql, $params);
    }

    /**
     * @return list<mixed> la première ligne
     */
    private function row(string $sql, string ...$params): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getConnection()->fetchNumeric($sql, $params);
    }

    private function scalar(string $sql, string ...$params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }
}
