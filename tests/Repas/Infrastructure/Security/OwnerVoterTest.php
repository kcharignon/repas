<?php

namespace Repas\Tests\Repas\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Infrastructure\Security\OwnerVoter;
use Repas\Tests\Helper\ControlledAccessDecisionManager;
use Repas\Tests\Helper\InMemoryLoader\OwnershipInMemoryLoader;
use Repas\Tests\Helper\SecurityUserDouble;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Les droits sur les objets de `Repas` (ADR 0035 : le voter est du contexte, le propriétaire d'un objet est lu par son port à lui). Le voter ne
 * connaît de l'utilisateur du jeton que `AuthenticatedUser`.
 */
class OwnerVoterTest extends TestCase
{
    private SecurityUserDouble $owner;
    private SecurityUserDouble $other;

    protected function setUp(): void
    {
        $this->owner = new SecurityUserDouble('owner-id');
        $this->other = new SecurityUserDouble('other-id');
    }

    public function testOnlyTheCreatorOwnsAnIngredient(): void
    {
        $loader = new OwnershipInMemoryLoader(ingredientCreators: ['mine' => 'owner-id']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::INGREDIENT_OWNER, 'mine', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::INGREDIENT_OWNER, 'mine', $loader));
    }

    public function testAnAdministratorHasNoPassThroughOnTheIngredientOfAnotherUser(): void
    {
        // Given : l'ingrédient est à owner-id, l'administrateur est un autre compte (ADR 0028, amendement du 2026-10-20, D3)
        $loader = new OwnershipInMemoryLoader(ingredientCreators: ['mine' => 'owner-id']);

        // Then : son rôle ne lui donne rien sur les ingrédients des autres ; le propriétaire, administrateur ou non, garde les siens
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::INGREDIENT_OWNER, 'mine', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::INGREDIENT_OWNER, 'mine', $loader, admin: true));
    }

    public function testAnAdministratorStillHasThePassThroughOnTheOtherResources(): void
    {
        // Then : seul INGREDIENT_OWNER change, les recettes, les listes, les repas et les lignes gardent leur passe-droit
        $loader = new OwnershipInMemoryLoader(
            recipeAuthors: ['recipe-1' => 'owner-id'],
            shoppingListOwners: ['list-1' => 'owner-id'],
            mealOwners: ['meal-1' => 'owner-id'],
            shoppingListRowOwners: ['row-1' => 'owner-id'],
        );
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::RECIPE_OWNER, 'recipe-1', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::SHOPPING_LIST_OWNER, 'list-1', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::MEAL_OWNER, 'meal-1', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::SHOPPING_LIST_ROW_OWNER, 'row-1', $loader, admin: true));
    }

    public function testAnUnknownIngredientIsNotFoundEvenForAnAdministrator(): void
    {
        // Then : sans passe-droit, la ressource est cherchée : un slug inconnu donne 404, pas un accès accordé
        $this->expectException(NotFoundHttpException::class);

        $this->vote($this->owner, OwnerVoter::INGREDIENT_OWNER, 'unknown', admin: true);
    }

    public function testOnlyTheOwnerOwnsAShoppingList(): void
    {
        $loader = new OwnershipInMemoryLoader(shoppingListOwners: ['list-1' => 'owner-id']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::SHOPPING_LIST_OWNER, 'list-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::SHOPPING_LIST_OWNER, 'list-1', $loader));
    }

    public function testOnlyTheAuthorOwnsARecipe(): void
    {
        $loader = new OwnershipInMemoryLoader(recipeAuthors: ['recipe-1' => 'owner-id']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::RECIPE_OWNER, 'recipe-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::RECIPE_OWNER, 'recipe-1', $loader));
    }

    public function testTheSecretRecipeOfAnotherUserIsNotFoundNotForbiddenForEveryoneButItsAuthor(): void
    {
        // Given : une recette secrète de owner-id (ADR 0033) et une ordinaire
        $loader = new OwnershipInMemoryLoader(recipeAuthors: ['secret' => 'owner-id', 'ordinary' => 'owner-id'], secretRecipes: ['secret']);

        // Then : l'auteur la possède ; un autre reçoit un 404, jamais un 403 qui révélerait qu'elle existe ; une recette ordinaire reste un refus 403
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::RECIPE_OWNER, 'secret', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::RECIPE_OWNER, 'ordinary', $loader));
        $this->expectException(NotFoundHttpException::class);
        $this->vote($this->other, OwnerVoter::RECIPE_OWNER, 'secret', $loader);
    }

    public function testTheSecretRecipeOfAnotherUserHasTheMessageOfAnUnknownRecipe(): void
    {
        // Given
        $loader = new OwnershipInMemoryLoader(recipeAuthors: ['secret' => 'owner-id'], secretRecipes: ['secret']);

        // When
        $messages = [];
        foreach (['secret', 'unknown'] as $id) {
            try {
                $this->vote($this->other, OwnerVoter::RECIPE_OWNER, $id, $loader);
                $this->fail('Un 404 est attendu.');
            } catch (NotFoundHttpException $exception) {
                $messages[] = str_replace($id, '{id}', $exception->getMessage());
            }
        }

        // Then : indiscernable d'une recette inconnue
        $this->assertSame($messages[0], $messages[1]);
    }

    public function testAnAdministratorHasNoPassThroughOnTheSecretRecipeOfAnotherUser(): void
    {
        // Given : la recette secrète de owner-id (ADR 0033) ; l'administrateur est un autre compte
        $loader = new OwnershipInMemoryLoader(recipeAuthors: ['secret' => 'owner-id', 'ordinary' => 'owner-id'], secretRecipes: ['secret']);

        // Then : il garde l'édition et la suppression des recettes ordinaires, et l'auteur administrateur celles de sa propre secrète ; la secrète d'un autre
        // n'existe pas pour lui (404)
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::RECIPE_OWNER, 'ordinary', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::RECIPE_OWNER, 'secret', $loader, admin: true));
        $this->expectException(NotFoundHttpException::class);
        $this->vote($this->other, OwnerVoter::RECIPE_OWNER, 'secret', $loader, admin: true);
    }

    public function testAnAdministratorIsNotFoundOnTheSecretRecipeWithTheMessageOfAnUnknownOne(): void
    {
        // Given
        $loader = new OwnershipInMemoryLoader(recipeAuthors: ['secret' => 'owner-id'], secretRecipes: ['secret']);

        // When
        $messages = [];
        foreach (['secret', 'unknown'] as $id) {
            try {
                $this->vote($this->other, OwnerVoter::RECIPE_OWNER, $id, $loader, admin: true);
                $this->fail('Un 404 est attendu.');
            } catch (NotFoundHttpException $exception) {
                $messages[] = str_replace($id, '{id}', $exception->getMessage());
            }
        }

        // Then
        $this->assertSame($messages[0], $messages[1]);
    }

    public function testOnlyTheOwnerOfTheShoppingListOwnsAMeal(): void
    {
        $loader = new OwnershipInMemoryLoader(mealOwners: ['meal-1' => 'owner-id']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::MEAL_OWNER, 'meal-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::MEAL_OWNER, 'meal-1', $loader));
    }

    public function testOnlyTheOwnerOfTheShoppingListOwnsOneOfItsRows(): void
    {
        $loader = new OwnershipInMemoryLoader(shoppingListRowOwners: ['row-1' => 'owner-id']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::SHOPPING_LIST_ROW_OWNER, 'row-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::SHOPPING_LIST_ROW_OWNER, 'row-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::SHOPPING_LIST_ROW_OWNER, 'row-1', $loader, admin: true));
    }

    public function testAnAdministratorOwnsEverythingButIngredientsAndSecretRecipesWithoutLookingTheResourceUp(): void
    {
        foreach ([OwnerVoter::SHOPPING_LIST_OWNER, OwnerVoter::MEAL_OWNER, OwnerVoter::SHOPPING_LIST_ROW_OWNER] as $attribute) {
            $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, $attribute, 'unknown', admin: true), $attribute);
        }
    }

    public function testADisabledUserOwnsNothing(): void
    {
        $disabled = new SecurityUserDouble('owner-id', disabled: true);
        $loader = new OwnershipInMemoryLoader(
            ingredientCreators: ['ingredient-1' => 'owner-id'],
            shoppingListOwners: ['list-1' => 'owner-id'],
            recipeAuthors: ['recipe-1' => 'owner-id'],
            mealOwners: ['meal-1' => 'owner-id'],
            shoppingListRowOwners: ['row-1' => 'owner-id'],
        );

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($disabled, OwnerVoter::INGREDIENT_OWNER, 'ingredient-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($disabled, OwnerVoter::SHOPPING_LIST_OWNER, 'list-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($disabled, OwnerVoter::RECIPE_OWNER, 'recipe-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($disabled, OwnerVoter::MEAL_OWNER, 'meal-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($disabled, OwnerVoter::SHOPPING_LIST_ROW_OWNER, 'row-1', $loader));
    }

    public function testADisabledAdministratorStillPassesWhereTheRoleAlonePasses(): void
    {
        // Given : comportement conservé du voter d'origine (le rôle est jugé avant le compte) ; un compte désactivé ne peut de toute façon plus
        // se connecter (UserChecker)
        $disabled = new SecurityUserDouble('other-id', disabled: true);
        $loader = new OwnershipInMemoryLoader(ingredientCreators: ['ingredient-1' => 'owner-id'], shoppingListOwners: ['list-1' => 'owner-id']);

        // Then : la liste passe par le rôle, l'ingrédient (sans passe-droit) reste refusé par le compte désactivé
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($disabled, OwnerVoter::SHOPPING_LIST_OWNER, 'list-1', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($disabled, OwnerVoter::INGREDIENT_OWNER, 'ingredient-1', $loader, admin: true));
    }

    public function testAnUnknownResourceIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->vote($this->owner, OwnerVoter::SHOPPING_LIST_ROW_OWNER, 'unknown-row');
    }

    public function testEveryKindOfUnknownResourceIsNotFound(): void
    {
        foreach ([OwnerVoter::SHOPPING_LIST_OWNER, OwnerVoter::MEAL_OWNER, OwnerVoter::RECIPE_OWNER, OwnerVoter::INGREDIENT_OWNER] as $attribute) {
            try {
                $this->vote($this->owner, $attribute, 'unknown');
                $this->fail("Un 404 est attendu pour $attribute.");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAUserOfAnotherKindOwnsNothing(): void
    {
        // Given : un utilisateur de jeton qui n'est pas un AuthenticatedUser
        $loader = new OwnershipInMemoryLoader(shoppingListOwners: ['list-1' => 'owner-id']);
        $token = new UsernamePasswordToken(new InMemoryUser('owner-id', null, ['ROLE_USER']), 'main', ['ROLE_USER']);

        // Then
        $this->assertSame(VoterInterface::ACCESS_DENIED, new OwnerVoter(new ControlledAccessDecisionManager(false), $loader)->vote($token, 'list-1', [OwnerVoter::SHOPPING_LIST_OWNER]));
    }

    public function testItAbstainsOnTheAttributesOfTheOtherContexts(): void
    {
        // Then : HIMSELF est au contexte User, VOICE_LINK_OWNER au contexte Voice (ADR 0035)
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->owner, 'HIMSELF', 'owner-id'));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->owner, 'VOICE_LINK_OWNER', 'link-1', admin: true));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->owner, 'OTHER', 'owner-id'));
    }

    public function testItAbstainsWhenTheSubjectIsNotAString(): void
    {
        $token = new UsernamePasswordToken($this->owner, 'main', ['ROLE_USER']);
        $voter = new OwnerVoter(new ControlledAccessDecisionManager(true), new OwnershipInMemoryLoader());

        foreach ([null, 12, ['list-1'], new \stdClass()] as $subject) {
            $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $subject, [OwnerVoter::SHOPPING_LIST_OWNER]));
        }
    }

    private function vote(SecurityUserDouble $user, string $attribute, string $subject, ?OwnershipInMemoryLoader $loader = null, bool $admin = false): int
    {
        $voter = new OwnerVoter(new ControlledAccessDecisionManager($admin), $loader ?? new OwnershipInMemoryLoader());

        return $voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $subject, [$attribute]);
    }
}
