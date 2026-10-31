<?php

namespace Repas\Tests\User\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryLoader\OwnershipInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\VoiceLinkOwnershipInMemoryLoader;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Security\OwnerVoter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class OwnerVoterTest extends TestCase
{
    private User $owner;
    private User $other;

    protected function setUp(): void
    {
        $this->owner = new UserBuilder()->withId('owner-id')->build();
        $this->other = new UserBuilder()->withId('other-id')->build();
    }

    public function testAUserIsHimself(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::HIMSELF, 'owner-id'));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::HIMSELF, 'owner-id'));
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
        $loader = new OwnershipInMemoryLoader(recipeAuthors: ['recipe-1' => 'owner-id'], shoppingListOwners: ['list-1' => 'owner-id'], mealOwners: ['meal-1' => 'owner-id']);
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::RECIPE_OWNER, 'recipe-1', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::SHOPPING_LIST_OWNER, 'list-1', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::MEAL_OWNER, 'meal-1', $loader, admin: true));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::HIMSELF, 'owner-id', admin: true));
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

    public function testOnlyTheOwnerOwnsAVoiceLinkAndAnAdministratorHasNoPassThrough(): void
    {
        // Given : la liaison avec Alexa est à owner-id (ADR 0029)
        $links = new VoiceLinkOwnershipInMemoryLoader(['link-1' => 'owner-id']);

        // Then : le propriétaire la retire, un autre compte non, et un administrateur non plus : retirer la liaison d'un autre couperait sa commande vocale
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::VOICE_LINK_OWNER, 'link-1', voiceLinks: $links));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::VOICE_LINK_OWNER, 'link-1', voiceLinks: $links));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::VOICE_LINK_OWNER, 'link-1', admin: true, voiceLinks: $links));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::VOICE_LINK_OWNER, 'link-1', admin: true, voiceLinks: $links));
    }

    public function testAnUnknownVoiceLinkIsNotFoundEvenForAnAdministrator(): void
    {
        // Then : sans passe-droit, la liaison est cherchée : un identifiant inconnu donne 404
        $this->expectException(NotFoundHttpException::class);

        $this->vote($this->owner, OwnerVoter::VOICE_LINK_OWNER, 'unknown-link', admin: true);
    }

    public function testAnAdministratorOwnsEverythingWithoutLookingTheResourceUp(): void
    {
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->other, OwnerVoter::SHOPPING_LIST_OWNER, 'unknown-list', admin: true));
    }

    public function testADisabledUserOwnsNothing(): void
    {
        $disabled = new UserBuilder()->withId('owner-id')->isDisabled()->build();
        $loader = new OwnershipInMemoryLoader(shoppingListOwners: ['list-1' => 'owner-id']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($disabled, OwnerVoter::SHOPPING_LIST_OWNER, 'list-1', $loader));
    }

    public function testAnUnknownResourceIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->vote($this->owner, OwnerVoter::SHOPPING_LIST_ROW_OWNER, 'unknown-row');
    }

    public function testItAbstainsOnAnotherAttribute(): void
    {
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->owner, 'OTHER', 'owner-id'));
    }

    private function vote(User $user, string $attribute, string $subject, ?OwnershipInMemoryLoader $loader = null, bool $admin = false, ?VoiceLinkOwnershipInMemoryLoader $voiceLinks = null): int
    {
        $decisionManager = new class($admin) implements AccessDecisionManagerInterface {
            public function __construct(private readonly bool $admin)
            {
            }

            public function decide(TokenInterface $token, array $attributes, mixed $object = null, ?AccessDecision $accessDecision = null): bool
            {
                return $this->admin;
            }
        };
        $voter = new OwnerVoter($decisionManager, $loader ?? new OwnershipInMemoryLoader(), $voiceLinks ?? new VoiceLinkOwnershipInMemoryLoader());

        return $voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $subject, [$attribute]);
    }
}
