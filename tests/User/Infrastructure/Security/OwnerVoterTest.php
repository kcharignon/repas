<?php

namespace Repas\Tests\User\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryLoader\OwnershipInMemoryLoader;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Security\OwnerVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
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
        $loader = new OwnershipInMemoryLoader(ingredientCreators: ['mine' => 'owner-id', 'common' => null]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::INGREDIENT_OWNER, 'mine', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::INGREDIENT_OWNER, 'mine', $loader));
    }

    public function testACommonIngredientBelongsToNobodyButAnAdministratorMayEditIt(): void
    {
        $loader = new OwnershipInMemoryLoader(ingredientCreators: ['common' => null]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->owner, OwnerVoter::INGREDIENT_OWNER, 'common', $loader));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::INGREDIENT_OWNER, 'common', $loader, admin: true));
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

    public function testOnlyTheOwnerOfTheShoppingListOwnsAMeal(): void
    {
        $loader = new OwnershipInMemoryLoader(mealOwners: ['meal-1' => 'owner-id']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->owner, OwnerVoter::MEAL_OWNER, 'meal-1', $loader));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->other, OwnerVoter::MEAL_OWNER, 'meal-1', $loader));
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

    public function testAnUnknownResourceRaisesTheNotFoundException(): void
    {
        // Then
        $this->expectExceptionObject(ShoppingListException::shoppingListNotFound('unknown-list'));

        // When
        $this->vote($this->owner, OwnerVoter::SHOPPING_LIST_OWNER, 'unknown-list');
    }

    public function testItAbstainsOnAnotherAttribute(): void
    {
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->owner, 'OTHER', 'owner-id'));
    }

    private function vote(User $user, string $attribute, string $subject, ?OwnershipInMemoryLoader $loader = null, bool $admin = false): int
    {
        $decisionManager = new class($admin) implements AccessDecisionManagerInterface {
            public function __construct(private readonly bool $admin)
            {
            }

            public function decide(TokenInterface $token, array $attributes, mixed $object = null, bool $allowMultipleVotes = false): bool
            {
                return $this->admin;
            }
        };
        $voter = new OwnerVoter($decisionManager, $loader ?? new OwnershipInMemoryLoader());

        return $voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $subject, [$attribute]);
    }
}
