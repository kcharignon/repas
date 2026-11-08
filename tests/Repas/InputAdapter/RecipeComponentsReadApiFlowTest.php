<?php

namespace Repas\Tests\Repas\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * La lecture d'une recette qui en utilise une autre (ADR 0040 décision 19, COMP-001) de bout en bout, avec les vrais bus, la vraie base et la vraie
 * sécurité : la clé `components` est TOUJOURS écrite (`[]` sans composant), pour la recette d'un autre aussi ; les lignes de la recette utilisée sont
 * TELLES QU'ELLES SONT ÉCRITES ; `rows` ne contient jamais de ligne aplatie. Aucune route n'écrit de composant (COMP-002).
 */
class RecipeComponentsReadApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;
    private DataPersister $persister;
    private User $author;
    private User $other;
    private Ingredient $flour;
    private Ingredient $sugar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->persister = new DataPersister(static::getContainer());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->author = $this->savedUser();
        $this->other = $this->savedUser();
        $this->flour = new IngredientBuilder()->withName('Farine')->withCreator($this->author, true)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->build();
        $this->sugar = new IngredientBuilder()->withName('Sucre')->withCreator($this->author, true)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->build();
        $this->persister->saveIngredient($this->flour);
        $this->persister->saveIngredient($this->sugar);
    }

    public function testTheComponentsKeyIsAlwaysWrittenAndEmptyWithoutAnyComponent(): void
    {
        // Given
        $recipe = $this->savedRecipe($this->author, 'Crêpes', [[$this->sugar, 100.0]]);

        // When
        $this->request('GET', '/api/v1/recipes/' . $recipe->getId(), $this->author);
        $own = $this->jsonBody();
        $this->request('GET', '/api/v1/recipes/' . $recipe->getId(), $this->other);
        $ofAnother = $this->jsonBody();

        // Then : la clé est là, vide, pour la sienne comme pour celle d'un autre
        $this->assertSame([200, []], [$this->client->getResponse()->getStatusCode(), $ofAnother['components']]);
        $this->assertArrayHasKey('components', $own);
        $this->assertSame([], $own['components']);
        $this->assertCount(1, $own['rows']);
    }

    public function testARecipeThatUsesAnotherListsItWithItsRowsAsWrittenAndKeepsItsOwnRows(): void
    {
        // Given : la pâte est écrite pour 6 avec 250 g de farine et utilisée deux fois par une tarte pour 4 qui a 100 g de sucre
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250.0]], serving: 6);
        $pie = $this->savedRecipe($this->author, 'Tarte au citron', [[$this->sugar, 100.0]], serving: 4, components: [[$dough, 2.0]]);

        // When
        $this->request('GET', '/api/v1/recipes/' . $pie->getId(), $this->author);
        $body = $this->jsonBody();

        // Then : un composant, ses lignes JAMAIS multipliées ni aplaties dans `rows`
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(
            [['recipeId' => $dough->getId(), 'name' => 'Pâte sablée', 'serving' => 6, 'multiplier' => 2, 'secret' => false, 'convertible' => true]],
            array_map(static fn(array $component): array => array_diff_key($component, ['rows' => true]), $body['components']),
        );
        $this->assertSame([$this->flour->getSlug()], array_column($body['components'][0]['rows'], 'ingredientSlug'));
        $this->assertSame([250], array_column($body['components'][0]['rows'], 'quantity'));
        $this->assertSame([$this->sugar->getSlug()], array_column($body['rows'], 'ingredientSlug'));
        $this->assertSame([100], array_column($body['rows'], 'quantity'));
        // les clés de chaque ligne d'une recette utilisée sont celles d'une ligne de la recette
        $this->assertSame(array_keys($body['rows'][0]), array_keys($body['components'][0]['rows'][0]));
        $this->assertSame(['id', 'name', 'serving', 'typeSlug', 'typeName', 'secret', 'favorite', 'rows', 'components'], array_slice(array_keys($body), 0, 9));
    }

    public function testTheOrdinaryRecipeOfAnotherUserShowsItsComponentsBeforeItIsCopied(): void
    {
        // Given
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250.0]], serving: 6);
        $pie = $this->savedRecipe($this->author, 'Tarte au citron', [[$this->sugar, 100.0]], components: [[$dough, 0.5]]);

        // When
        $this->request('GET', '/api/v1/recipes/' . $pie->getId(), $this->other);
        $body = $this->jsonBody();

        // Then : son détail, avec ses recettes utilisées, avant de la copier ; elle n'est pas modifiable par ce lecteur
        $this->assertSame([200, false, [$dough->getId()], [0.5]], [$this->client->getResponse()->getStatusCode(), $body['canEdit'], array_column($body['components'], 'recipeId'), array_column($body['components'], 'multiplier')]);
    }

    public function testASecretRecipeThatUsesAnotherStaysUnknownToAnotherUser(): void
    {
        // Given
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250.0]]);
        $pie = $this->savedRecipe($this->author, 'Tarte secrète', [[$this->sugar, 100.0]], secret: true, components: [[$dough, 1.0]]);

        // When
        $this->request('GET', '/api/v1/recipes/' . $pie->getId(), $this->other);

        // Then : `404` et le message d'une recette inconnue, rien de ses composants
        $this->assertSame([404, ['error' => ['message' => RecipeException::notFound($pie->getId())->getMessage()]]], [$this->client->getResponse()->getStatusCode(), $this->jsonBody()]);
        $this->assertStringNotContainsString('Pâte sablée', (string) $this->client->getResponse()->getContent());
    }

    public function testNoRouteWritesAComponentYet(): void
    {
        // Given
        $dough = $this->savedRecipe($this->author, 'Pâte sablée', [[$this->flour, 250.0]]);
        $pie = $this->savedRecipe($this->author, 'Tarte au citron', [[$this->sugar, 100.0]]);

        // When : un corps qui porte `components` est ignoré par l'écriture d'aujourd'hui (COMP-002 l'ouvre)
        $this->request('PUT', '/api/v1/recipes/' . $pie->getId(), $this->author, [
            'name' => 'Tarte au citron', 'serving' => 4, 'typeSlug' => 'dessert',
            'rows' => [['ingredientSlug' => $this->sugar->getSlug(), 'unitSlug' => 'gramme', 'quantity' => 100]],
            'components' => [['recipeId' => $dough->getId(), 'multiplier' => 2]],
        ]);
        $status = $this->client->getResponse()->getStatusCode();
        $this->request('GET', '/api/v1/recipes/' . $pie->getId(), $this->author);

        // Then
        $this->assertContains($status, [200, 204]);
        $this->assertSame([], $this->jsonBody()['components']);
    }

    /**
     * @param list<array{Ingredient, float}> $rows les lignes, en grammes : l'ingrédient et sa quantité
     * @param list<array{Recipe, float}> $components les recettes utilisées et leur multiplicateur
     */
    private function savedRecipe(User $author, string $name, array $rows, int $serving = 4, bool $secret = false, array $components = []): Recipe
    {
        $builder = new RecipeBuilder()->withAuthor($author)->withName($name)->withServing($serving)->withRecipeType(new RecipeTypeBuilder()->isDessert())->asSecret($secret);
        foreach ($rows as [$ingredient, $quantity]) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isGramme())->withQuantity((int) $quantity));
        }
        foreach ($components as [$used, $multiplier]) {
            $builder->withComponent($used, $multiplier);
        }
        $recipe = $builder->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
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
}
