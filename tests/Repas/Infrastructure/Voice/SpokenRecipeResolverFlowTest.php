<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Loader\SpokenRecipeCandidates;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Application\Dto\RecipeSuggestion;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Repas\Shared\Application\Interface\SpokenRecipeResolver;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Le port `SpokenRecipeResolver` câblé pour de vrai (ADR 0029 décision 13, VOX-305) : adaptateur, chargeur des recettes de l'utilisateur, mémoire de
 * phrase des recettes, bus transactionnel et classeur de noms sur la base de test.
 */
class SpokenRecipeResolverFlowTest extends DatabaseTestCase
{
    private SpokenRecipeResolver $resolver;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = static::getContainer()->get(SpokenRecipeResolver::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, string $name, ?string $id = null): string
    {
        $builder = new RecipeBuilder()->isPastaCarbonara()->withName($name);
        if ($id !== null) {
            $builder = $builder->withId($id);
        }
        $recipe = $builder->withAuthor($author)->build();
        foreach ($recipe->getRows() as $row) {
            $row->getIngredient()->setCreatorId($author->getId());
        }
        $this->persister->saveRecipe($recipe);

        return $recipe->getId();
    }

    public function testItResolvesAPhraseToARecipeOfTheUserOnly(): void
    {
        // Given : le hachis est à l'autre utilisateur, le gratin à l'utilisateur
        $user = $this->savedUser();
        $other = $this->savedUser();
        $gratin = $this->savedRecipe($user, 'Gratin dauphinois');
        $this->savedRecipe($other, 'Hachis parmentier');

        // When
        $found = $this->resolver->resolveOrSuggest($user->getId(), 'le gratin dauphinois');
        $notFound = $this->resolver->resolveOrSuggest($user->getId(), 'hachis parmentier');

        // Then : la recette de l'autre n'existe pas pour lui, ni trouvée ni proposée
        $this->assertSame([$gratin, 'Gratin dauphinois'], [$found->recipe?->id, $found->recipe?->name]);
        $this->assertNull($notFound->recipe);
        $this->assertSame([], $notFound->suggestions);
    }

    public function testAUserWithoutAnyRecipeGetsNothing(): void
    {
        $resolution = $this->resolver->resolveOrSuggest($this->savedUser()->getId(), 'gratin');

        $this->assertNull($resolution->recipe);
        $this->assertSame([], $resolution->suggestions);
    }

    public function testTheRecipeWhoseNameIsTheOneSaidWinsOverALongerOne(): void
    {
        // Given : « tarte » et « tarte aux pommes »
        $user = $this->savedUser();
        $tart = $this->savedRecipe($user, 'Tarte');
        $this->savedRecipe($user, 'Tarte aux pommes');

        // When
        $found = $this->resolver->resolveOrSuggest($user->getId(), 'une tarte');

        // Then : le nom identique passe avant le nom plus long
        $this->assertSame($tart, $found->recipe?->id);
    }

    public function testTwoRecipesThatBothFitAreProposedNeverGuessed(): void
    {
        // Given : « gratin » colle aussi bien au gratin dauphinois qu'au gratin de pâtes
        $user = $this->savedUser();
        $dauphinois = $this->savedRecipe($user, 'Gratin dauphinois');
        $pasta = $this->savedRecipe($user, 'Gratin de pâtes');

        // When
        $resolution = $this->resolver->resolveOrSuggest($user->getId(), 'gratin');

        // Then : une recette planifiée à tort modifie beaucoup de lignes : on demande
        $this->assertNull($resolution->recipe);
        $this->assertEqualsCanonicalizing(
            [new RecipeSuggestion($dauphinois, 'Gratin dauphinois'), new RecipeSuggestion($pasta, 'Gratin de pâtes')],
            $resolution->suggestions,
        );
    }

    public function testASimilarNameThatIsNotChosenByItselfIsProposed(): void
    {
        // Given
        $user = $this->savedUser();
        $gratin = $this->savedRecipe($user, 'Gratin dauphinois');
        $this->savedRecipe($user, 'Hachis parmentier');

        // When : une faute de frappe trop forte pour être devinée
        $resolution = $this->resolver->resolveOrSuggest($user->getId(), 'gratin dofinois');

        // Then : proposée, jamais planifiée toute seule ; le hachis, qui n'a rien à voir, n'est pas proposé
        $this->assertNull($resolution->recipe);
        $this->assertEquals([new RecipeSuggestion($gratin, 'Gratin dauphinois')], $resolution->suggestions);
    }

    public function testAProductNeverStealsARecipeAndARecipeNeverStealsAProduct(): void
    {
        // Given : « pizza » est un produit de l'utilisateur, « pizza maison » une de ses recettes
        $user = $this->savedUser();
        $pizza = new IngredientBuilder()->withName('pizza')->withCreator($user, true)->build();
        $this->persister->saveIngredient($pizza);
        $recipe = $this->savedRecipe($user, 'Pizza maison');
        $products = static::getContainer()->get(SpokenIngredientResolver::class);

        // When
        $asRecipe = $this->resolver->resolveOrSuggest($user->getId(), 'pizza');
        $asProduct = $products->resolveOrSuggest($user->getId(), 'pizza');
        $recipeAsProduct = $products->resolveOrSuggest($user->getId(), 'pizza maison');

        // Then : chaque recherche ne voit que son monde, le produit ne vole pas la recette et inversement
        $this->assertSame($recipe, $asRecipe->recipe?->id);
        $this->assertSame($pizza->getSlug(), $asProduct->ingredient?->slug);
        $this->assertNull($recipeAsProduct->ingredient, 'une recette ne se trouve pas parmi les produits');
    }

    public function testWhatTheUserChoseForAPhraseIsRememberedAndWinsNextTime(): void
    {
        // Given : « croque » ne ressemble à aucune recette ; il dit que c'est le hachis
        $user = $this->savedUser();
        $hachis = $this->savedRecipe($user, 'Hachis parmentier');
        $this->savedRecipe($user, 'Gratin dauphinois');
        $this->assertNull($this->resolver->resolveOrSuggest($user->getId(), 'le plat du dimanche')->recipe);

        // When
        $this->resolver->remember($user->getId(), 'le plat du dimanche', $hachis);
        $this->entityManager->clear();

        // Then : la même phrase donne tout de suite cette recette, dite autrement (articles, pluriel)
        $this->assertSame($hachis, $this->resolver->resolveOrSuggest($user->getId(), 'plat du dimanche')->recipe?->id);
        $this->assertSame($hachis, $this->resolver->resolveOrSuggest($user->getId(), 'les plats du dimanche')->recipe?->id);
    }

    public function testTheMemoryOfOneUserNeverServesAnother(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $hachis = $this->savedRecipe($user, 'Hachis parmentier');
        $this->savedRecipe($other, 'Gratin');
        $this->resolver->remember($user->getId(), 'le plat du dimanche', $hachis);

        // Then
        $this->assertNull($this->resolver->resolveOrSuggest($other->getId(), 'le plat du dimanche')->recipe);
    }

    public function testRememberingTheRecipeOfAnotherUserIsRefusedAndWritesNothing(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $theirs = $this->savedRecipe($other, 'Hachis parmentier');

        // Then
        $this->expectExceptionObject(RecipeException::notFound($theirs));
        try {
            $this->resolver->remember($user->getId(), 'le plat du dimanche', $theirs);
        } finally {
            $this->assertSame('0', (string) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM spoken_recipe_phrase_choice'));
        }
    }

    public function testARememberedRecipeThatWasDeletedDoesNotBreakTheSearch(): void
    {
        // Given : la mémoire désigne une recette qui n'est plus parmi celles de l'utilisateur
        $user = $this->savedUser();
        $this->savedRecipe($user, 'Hachis parmentier');
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO spoken_recipe_phrase_choice (user_id, phrase, recipe_id, times, updated_at) VALUES (?, 'plat-dimanche', 'deleted-recipe', 1, NOW())",
            [$user->getId()],
        );

        // When / Then : ignorée, le classement reprend
        $resolution = $this->resolver->resolveOrSuggest($user->getId(), 'le plat du dimanche');
        $this->assertNull($resolution->recipe);
    }

    public function testTheCandidatesAreTheRecipesOfTheUserWithTheirOwnNormalizedName(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $id = $this->savedRecipe($user, 'Œufs à la Coque');
        $this->savedRecipe($other, 'Hachis parmentier');

        // When
        $candidates = static::getContainer()->get(SpokenRecipeCandidates::class)->forUser($user->getId());

        // Then : l'identifiant de la recette tient lieu de slug, aucun rayon, aucune habitude
        $this->assertCount(1, $candidates);
        $this->assertSame([$id, 'oeufs-a-la-coque', 'Œufs à la Coque', '', 0], [$candidates[0]->slug, $candidates[0]->sluggedName, $candidates[0]->name, $candidates[0]->departmentName, $candidates[0]->uses12m]);
    }
}
