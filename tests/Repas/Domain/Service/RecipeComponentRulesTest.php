<?php

namespace Repas\Tests\Repas\Domain\Service;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Domain\Service\RecipeComponentRules;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\RecipeBuilder;

/**
 * Les règles d'une recette qui en utilise une autre qui regardent DEUX recettes (ADR 0040 décisions 6 à 9) : même compte, profondeur 1 dans les deux
 * sens, secrète et ordinaire, recette non vide. Elles se jugent sur l'état final et n'ont aucun passe-droit.
 */
class RecipeComponentRulesTest extends TestCase
{
    private RecipeComponentRules $rules;

    protected function setUp(): void
    {
        $this->rules = new RecipeComponentRules();
    }

    public function testAnOrdinaryRecipeMayUseAnOrdinaryRecipeOfTheSameAuthor(): void
    {
        // Given
        $parent = $this->recipe('tarte', 'author');
        $component = $this->recipe('pate', 'author');

        // When & Then : aucun refus
        $this->rules->assertCanUse($parent, $component, parentIsUsed: false);
        $this->addToAssertionCount(1);
    }

    public function testASecretRecipeMayUseBothKinds(): void
    {
        // Given
        $parent = $this->recipe('tarte', 'author', secret: true);

        // When & Then
        $this->rules->assertCanUse($parent, $this->recipe('pate', 'author'), false);
        $this->rules->assertCanUse($parent, $this->recipe('creme', 'author', secret: true), false);
        $this->addToAssertionCount(2);
    }

    public function testARecipeDoesNotUseItself(): void
    {
        // Given
        $recipe = $this->recipe('tarte', 'author');

        // Then
        $this->assertRefused(RecipeException::COMPONENT_SELF, 422, fn() => $this->rules->assertCanUse($recipe, $recipe, false));
    }

    public function testARecipeWithoutOwnerIsNeverComposed(): void
    {
        // Given : la neutre d'une recette Marmiton ; l'auteur est un identifiant réservé qui n'est pas un compte
        $neutral = new RecipeBuilder()->withId('neutre')->asMarmitonNeutral()->build();
        $component = $this->recipe('pate', Recipe::MARMITON_OWNER_ID);

        // Then
        $this->assertRefused(RecipeException::COMPONENT_OWNERLESS, 422, fn() => $this->rules->assertCanUse($neutral, $component, false));
    }

    public function testTheRecipeOfAnotherAuthorIsUnknown(): void
    {
        // Given : un administrateur qui modifie la recette d'un autre compare avec l'AUTEUR de la recette, jamais avec lui-même
        $parent = $this->recipe('tarte', 'author');
        $component = $this->recipe('pate', 'someone-else');

        // Then : `404`, le message d'une recette inconnue, on ne révèle rien
        $this->assertRefused(RecipeException::notFound('pate')->getMessage(), 404, fn() => $this->rules->assertCanUse($parent, $component, false));
    }

    public function testTheSecretRecipeOfAnotherAuthorIsUnknownToo(): void
    {
        // Given
        $parent = $this->recipe('tarte', 'author');
        $component = $this->recipe('pate', 'someone-else', secret: true);

        // Then : le même refus que pour la recette d'un autre, pas un refus de secret
        $this->assertRefused(RecipeException::notFound('pate')->getMessage(), 404, fn() => $this->rules->assertCanUse($parent, $component, false));
    }

    public function testARecipeThatIsAlreadyUsedCannotUseAnother(): void
    {
        // Given : profondeur 1, premier sens
        $parent = $this->recipe('pate', 'author');
        $component = $this->recipe('sucre', 'author');

        // Then
        $this->assertRefused(RecipeException::COMPONENT_CHAIN, 422, fn() => $this->rules->assertCanUse($parent, $component, parentIsUsed: true));
    }

    public function testARecipeWithComponentsCannotBeUsed(): void
    {
        // Given : profondeur 1, second sens ; un modèle chargé sans ses arêtes sait pourtant qu'il en a
        $parent = $this->recipe('tarte', 'author');
        $composed = new RecipeBuilder()->withId('pate')->withAuthorId('author')->withComponent(new RecipeBuilder()->withId('sucre'))->build();

        // Then
        $this->assertRefused(RecipeException::COMPONENT_CHAIN, 422, fn() => $this->rules->assertCanUse($parent, $composed, false));
    }

    public function testAnOrdinaryRecipeCannotUseASecretOne(): void
    {
        // Given : sinon lire ou copier la recette visible révélerait le contenu d'une secrète
        $parent = $this->recipe('tarte', 'author');
        $component = $this->recipe('pate', 'author', secret: true);

        // Then
        $this->assertRefused(RecipeException::COMPONENT_SECRET, 409, fn() => $this->rules->assertCanUse($parent, $component, false));
    }

    public function testTheRefusalsComeInTheOrderOfTheDecision(): void
    {
        // Given : une recette d'un autre auteur, qui a des composants, secrète, pour une recette déjà utilisée : tout est refusable
        $parent = $this->recipe('tarte', 'author');
        $everything = new RecipeBuilder()->withId('pate')->withAuthorId('someone-else')->asSecret()->withComponent(new RecipeBuilder()->withId('sucre'))->build();

        // Then : d'abord elle-même, puis la recette sans propriétaire, puis l'inconnue, puis la profondeur, puis le secret
        $this->assertRefused(RecipeException::COMPONENT_SELF, 422, fn() => $this->rules->assertCanUse($parent, $parent, true));
        $this->assertRefused(RecipeException::notFound('pate')->getMessage(), 404, fn() => $this->rules->assertCanUse($parent, $everything, true));

        $sameAuthor = new RecipeBuilder()->withId('pate')->withAuthorId('author')->asSecret()->withComponent(new RecipeBuilder()->withId('sucre'))->build();
        $this->assertRefused(RecipeException::COMPONENT_CHAIN, 422, fn() => $this->rules->assertCanUse($parent, $sameAuthor, true));
        $this->assertRefused(RecipeException::COMPONENT_CHAIN, 422, fn() => $this->rules->assertCanUse($parent, $sameAuthor, false), 'avant le secret');

        $neutral = new RecipeBuilder()->withId('neutre')->asMarmitonNeutral()->build();
        $this->assertRefused(RecipeException::COMPONENT_OWNERLESS, 422, fn() => $this->rules->assertCanUse($neutral, $everything, true), 'avant l\'inconnue');
    }

    public function testARecipeMadeOnlyOfUsedRecipesIsNotEmpty(): void
    {
        // Given
        $rowsOnly = new RecipeBuilder()->isPastaCarbonara()->build();
        $componentsOnly = new RecipeBuilder()->withId('tarte')->withComponent(new RecipeBuilder()->withId('pate'))->build();
        $both = new RecipeBuilder()->isPastaCarbonara()->withComponent(new RecipeBuilder()->withId('pate'))->build();

        // When & Then
        foreach ([$rowsOnly, $componentsOnly, $both] as $recipe) {
            $this->rules->assertNotEmpty($recipe);
        }
        $this->addToAssertionCount(3);
    }

    public function testARecipeWithNeitherRowsNorComponentsIsEmpty(): void
    {
        // Given
        $empty = new RecipeBuilder()->build();

        // Then
        $this->assertRefused(RecipeException::EMPTY, 422, fn() => $this->rules->assertNotEmpty($empty));
    }

    public function testTheEmptyJudgmentFollowsTheFinalState(): void
    {
        // Given : les composants sont retirés mais des lignes restent, ou l'inverse
        $recipe = new RecipeBuilder()->withId('tarte')->withComponent(new RecipeBuilder()->withId('pate'))->build();
        $recipe->setComponents(Tab::newEmptyTyped(RecipeComponent::class));

        // Then : sans ligne ni composant, elle est vide
        $this->assertRefused(RecipeException::EMPTY, 422, fn() => $this->rules->assertNotEmpty($recipe));
    }

    public function testMakingARecipeSecretIsRefusedWhileAnOrdinaryRecipeUsesIt(): void
    {
        // Then
        $this->rules->assertCanBecomeSecret(usedByOrdinary: false);
        $this->assertRefused(RecipeException::SECRET_USED_BY_PUBLIC, 409, fn() => $this->rules->assertCanBecomeSecret(usedByOrdinary: true));
    }

    public function testMakingARecipePublicIsRefusedWhileItUsesASecretOne(): void
    {
        // Then
        $this->rules->assertCanBecomePublic(hasSecretComponent: false);
        $this->assertRefused(RecipeException::COMPONENT_SECRET, 409, fn() => $this->rules->assertCanBecomePublic(hasSecretComponent: true));
    }

    public function testTheSecretRulesJudgeTheFinalStateOfTheRequest(): void
    {
        // Given : un PUT qui rend la recette secrète ET lui donne un composant secret ; la recette est d'abord secrète, puis jugée
        $parent = $this->recipe('tarte', 'author');
        $secretComponent = $this->recipe('pate', 'author', secret: true);

        // When : l'état final de la requête est une recette secrète
        $parent->changeSecret(true, 'author');

        // Then : plus de refus, un secret peut utiliser les deux
        $this->rules->assertCanUse($parent, $secretComponent, false);
        $this->addToAssertionCount(1);
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertRefused(string $message, int $code, callable $action, string $explanation = ''): void
    {
        try {
            $action();
            $this->fail("Le refus $message était attendu. $explanation");
        } catch (RecipeException $exception) {
            $this->assertSame([$message, $code], [$exception->getMessage(), $exception->getCode()], $explanation);
        }
    }

    private function recipe(string $id, string $authorId, bool $secret = false): Recipe
    {
        $builder = new RecipeBuilder()->withId($id)->withAuthorId($authorId);

        return $secret ? $builder->asSecret()->build() : $builder->build();
    }
}
