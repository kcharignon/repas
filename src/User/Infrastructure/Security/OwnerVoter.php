<?php

namespace Repas\User\Infrastructure\Security;


use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class OwnerVoter extends Voter
{
    public const string HIMSELF = 'HIMSELF';
    public const string INGREDIENT_OWNER = 'INGREDIENT_OWNER';
    public const string SHOPPING_LIST_OWNER = 'SHOPPING_LIST_OWNER';
    public const string RECIPE_OWNER = 'RECIPE_OWNER';
    public const string MEAL_OWNER = 'MEAL_OWNER';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly ShoppingListRepository $shoppingListRepository,
        private readonly RecipeRepository $recipeRepository,
        private readonly IngredientRepository $ingredientRepository,
    ) {
    }


    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
                self::HIMSELF,
                self::INGREDIENT_OWNER,
                self::SHOPPING_LIST_OWNER,
                self::RECIPE_OWNER,
                self::MEAL_OWNER,
            ]) && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        $user = $token->getUser();

        if (!$user instanceof User || $user->isDisabled()) {
            return false;
        }

        switch ($attribute) {
            case self::HIMSELF:
                return $user->getId() === $subject;
            case self::INGREDIENT_OWNER:
                $ingredient = $this->ingredientRepository->findOneBySlug($subject);
                return $ingredient->getCreator()?->isEqual($user);
            case self::SHOPPING_LIST_OWNER:
                $shoppingList = $this->shoppingListRepository->findOneById($subject);
                return $shoppingList->getOwner()->isEqual($user);
            case self::RECIPE_OWNER:
                $recipe = $this->recipeRepository->findOneById($subject);
                return $recipe->getAuthor()->isEqual($user);
            case self::MEAL_OWNER:
                $shoppingList = $this->shoppingListRepository->findOneByMealId($subject);
                return $shoppingList->getOwner()->isEqual($user);
            default:
                return false;
        }
    }

}
