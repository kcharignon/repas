<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Les valeurs finies et bornées à l'entrée de l'API, avec les vrais bus, la vraie base et le vrai JWT : une quantité, un coefficient ou un
 * nombre de personnes qui n'est pas un nombre fini plausible (`1e999`, une chaîne, un tableau, zéro, un négatif, un million et un) est
 * refusé en `422`, jamais en `500`, et rien n'est enregistré. Les corps sont du JSON brut : `json_encode` ne sait pas écrire `1e999`.
 */
class BoundedValuesApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $user;
    private Ingredient $pasta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
        $this->persister->saveDepartment(new DepartmentBuilder()->isBakery()->build());
        foreach ([new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isUnite()] as $unit) {
            $this->persister->saveUnit($unit->build());
        }
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->user = $this->savedUser();
        $this->pasta = new IngredientBuilder()->isPasta()->withCreator($this->user, true)->build();
        $this->persister->saveIngredient($this->pasta);
    }

    /**
     * Les nombres que l'API refuse, écrits tels qu'ils arrivent dans le corps.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'infini (1e999)' => ['1e999'];
        yield 'moins l\'infini (-1e999)' => ['-1e999'];
        yield 'NaN en texte' => ['"NaN"'];
        yield 'INF en texte' => ['"INF"'];
        yield 'zéro' => ['0'];
        yield 'négatif' => ['-1'];
        yield 'un million et un' => ['1000001'];
        yield 'une chaîne' => ['"beaucoup"'];
        yield 'un nombre en texte' => ['"12"'];
        yield 'un tableau' => ['[1]'];
        yield 'un objet' => ['{"a":1}'];
        yield 'booléen' => ['true'];
        yield 'nul' => ['null'];
    }

    #[DataProvider('invalidNumbers')]
    public function testARecipeRowQuantityThatIsNotAFinitePlausibleNumberIsRefusedAndNothingIsSaved(string $quantity): void
    {
        // When
        $this->raw('POST', '/api/v1/recipes', sprintf('{"name":"Pâtes","serving":4,"typeSlug":"plat","rows":[{"ingredientSlug":"%s","unitSlug":"gramme","quantity":%s}]}', $this->pasta->getSlug(), $quantity));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame(0, $this->rows('recipe'));
        $this->assertSame(0, $this->rows('recipe_row'));
    }

    public function testARecipeRowQuantityIsAcceptedUpToAMillion(): void
    {
        // When
        $this->raw('POST', '/api/v1/recipes', sprintf('{"name":"Pâtes","serving":4,"typeSlug":"plat","rows":[{"ingredientSlug":"%s","unitSlug":"gramme","quantity":1000000}]}', $this->pasta->getSlug()));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    #[DataProvider('invalidNumbers')]
    public function testTheQuantityOfARowIsRefusedOnReplaceToo(string $quantity): void
    {
        // Given
        $this->raw('POST', '/api/v1/recipes', $this->recipeBody('500'));
        $id = basename($this->client->getResponse()->headers->get('Location'));

        // When
        $this->raw('PUT', '/api/v1/recipes/' . $id, $this->recipeBody($quantity));

        // Then : refusée, la recette garde sa ligne d'origine
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(500.0, (float) $this->entityManager->getConnection()->fetchOne('SELECT quantity FROM recipe_row'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidServings(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'cent un' => ['101'];
        yield 'infini' => ['1e999'];
        yield 'une chaîne' => ['"quatre"'];
        yield 'un tableau' => ['[4]'];
        yield 'un décimal' => ['2.5'];
        yield 'un décimal sans reste' => ['4.0'];
        yield 'un décimal en notation scientifique' => ['4e0'];
        yield 'un décimal proche d\'un entier' => ['4.000000001'];
        yield 'un entier en texte' => ['"4"'];
        yield 'un décimal en texte' => ['"2.5"'];
        yield 'booléen' => ['true'];
        yield 'nul' => ['null'];
    }

    #[DataProvider('invalidServings')]
    public function testARecipeServingThatIsNotAWholeNumberFromOneToAHundredIsRefused(string $serving): void
    {
        // When : sans la moindre dépréciation de PHP (une décimale n'est pas convertie en entier avant d'être jugée)
        $deprecations = $this->collectingDeprecations(fn() => $this->raw('POST', '/api/v1/recipes', sprintf('{"name":"Pâtes","serving":%s,"typeSlug":"plat","rows":[{"ingredientSlug":"%s","unitSlug":"gramme","quantity":500}]}', $serving, $this->pasta->getSlug())));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(['error' => ['message' => 'serving: Le nombre de personnes doit être un nombre entier de 1 à 100.']], $this->jsonBody());
        $this->assertSame([], $deprecations);
        $this->assertSame(0, $this->rows('recipe'));
    }

    #[DataProvider('invalidServings')]
    public function testTheServingOfARecipeIsRefusedOnReplaceToo(string $serving): void
    {
        // Given
        $this->raw('POST', '/api/v1/recipes', $this->recipeBody('500'));
        $id = basename($this->client->getResponse()->headers->get('Location'));

        // When
        $this->raw('PUT', '/api/v1/recipes/' . $id, sprintf('{"name":"Pâtes","serving":%s,"typeSlug":"plat","rows":[{"ingredientSlug":"%s","unitSlug":"gramme","quantity":500}]}', $serving, $this->pasta->getSlug()));

        // Then : refusée, la recette garde ses 4 personnes
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(4, (int) $this->entityManager->getConnection()->fetchOne('SELECT serving FROM recipe WHERE id = ?', [$id]));
    }

    public function testAWholeServingFromOneToAHundredIsAccepted(): void
    {
        // When
        foreach ([1, 4, 100] as $serving) {
            $this->raw('POST', '/api/v1/recipes', sprintf('{"name":"Pâtes %1$s","serving":%1$s,"typeSlug":"plat","rows":[{"ingredientSlug":"%2$s","unitSlug":"gramme","quantity":500}]}', $serving, $this->pasta->getSlug()));

            // Then
            $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        }
        $this->assertSame([1, 4, 100], array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn('SELECT serving FROM recipe ORDER BY serving')));
    }

    /**
     * @return list<string> les dépréciations de PHP levées pendant l'appel
     */
    private function collectingDeprecations(callable $call): array
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $call();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }

    public function testARecipeKeepsUpToAHundredRowsAndRefusesMore(): void
    {
        // When : cent lignes
        $this->raw('POST', '/api/v1/recipes', $this->recipeBody('10', Recipe::MAX_ROWS));

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(Recipe::MAX_ROWS, $this->rows('recipe_row'));

        // When : cent et une
        $this->raw('POST', '/api/v1/recipes', $this->recipeBody('10', Recipe::MAX_ROWS + 1));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'rows: Une recette ne peut pas avoir plus de 100 ingrédients.']], $this->jsonBody());
        $this->assertSame(1, $this->rows('recipe'));
    }

    public function testTheRowsOfAnExistingRecipeCannotGrowPastAHundred(): void
    {
        // Given
        $this->raw('POST', '/api/v1/recipes', $this->recipeBody('10'));
        $id = basename($this->client->getResponse()->headers->get('Location'));

        // When
        $this->raw('PUT', '/api/v1/recipes/' . $id, $this->recipeBody('10', Recipe::MAX_ROWS + 1));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame(1, $this->rows('recipe_row'));
    }

    #[DataProvider('invalidCoefficients')]
    public function testAnIngredientCoefficientThatIsNotAFinitePlausibleNumberIsRefused(string $coefficient): void
    {
        // When : un ingrédient acheté au paquet, cuisiné au gramme, sans conversion qui les relie
        $this->persister->saveUnit(new UnitBuilder()->isBox()->build());
        $this->raw('POST', '/api/v1/ingredients', sprintf('{"name":"Praliné","departmentSlug":"boulangerie","defaultCookingUnitSlug":"gramme","defaultPurchaseUnitSlug":"boite","coefficient":%s}', $coefficient));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(1, $this->rows('ingredient'), 'Aucun ingrédient de plus que la pâte du jeu de départ.');
    }

    /**
     * Les coefficients que l'API refuse : un nombre fini de 0,000001 à 1 000 000 est le seul permis.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidCoefficients(): iterable
    {
        yield from self::invalidNumbers();
        yield 'sous un millionième' => ['0.0000001'];
        yield 'au-dessus d\'un million' => ['1000000.5'];
    }

    #[DataProvider('invalidCoefficients')]
    public function testAnIngredientConversionCoefficientThatIsNotAFinitePlausibleNumberIsRefused(string $coefficient): void
    {
        // When
        $this->raw('POST', '/api/v1/ingredients/' . $this->pasta->getSlug() . '/conversions', sprintf('{"startUnitSlug":"unite","endUnitSlug":"gramme","coefficient":%s}', $coefficient));

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(1, $this->rows('conversion'), 'Seule la conversion universelle du jeu de départ existe.');
    }

    public function testAnIngredientConversionCoefficientIsAcceptedAtBothEndsOfTheRange(): void
    {
        // When
        $this->raw('POST', '/api/v1/ingredients/' . $this->pasta->getSlug() . '/conversions', '{"startUnitSlug":"unite","endUnitSlug":"gramme","coefficient":0.000001}');
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->raw('POST', '/api/v1/ingredients/' . $this->pasta->getSlug() . '/conversions', '{"startUnitSlug":"kilo","endUnitSlug":"unite","coefficient":1000000}');

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    #[DataProvider('invalidCoefficients')]
    public function testAUniversalConversionCoefficientThatIsNotAFinitePlausibleNumberIsRefused(string $coefficient): void
    {
        // Given
        $admin = $this->savedUser(admin: true);

        // When
        $this->raw('POST', '/api/v1/conversions', sprintf('{"startUnitSlug":"unite","endUnitSlug":"gramme","coefficient":%s}', $coefficient), $admin);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(1, $this->rows('conversion'));
    }

    #[DataProvider('invalidCoefficients')]
    public function testAUnitBaseFactorThatIsNotAFinitePlausibleNumberIsRefused(string $factor): void
    {
        // Given
        $admin = $this->savedUser(admin: true);

        // When
        $this->raw('POST', '/api/v1/units', sprintf('{"name":"Pincée","symbol":"pin","dimension":"WEIGHT","baseFactor":%s}', $factor), $admin);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $this->assertSame(3, $this->rows('unit'), 'Les trois unités du jeu de départ seulement.');
    }

    /**
     * @return string le corps d'une recette dont chaque ligne porte cette quantité (le même ingrédient : seule la borne du nombre compte)
     */
    private function recipeBody(string $quantity, int $rows = 1): string
    {
        $row = sprintf('{"ingredientSlug":"%s","unitSlug":"gramme","quantity":%s}', $this->pasta->getSlug(), $quantity);

        return sprintf('{"name":"Pâtes","serving":4,"typeSlug":"plat","rows":[%s]}', implode(',', array_fill(0, $rows, $row)));
    }

    private function raw(string $method, string $uri, string $content, ?User $user = null): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user ?? $this->user);
        $this->client->request($method, $uri, content: $content, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function rows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function savedUser(bool $admin = false): User
    {
        $builder = new UserBuilder();
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
