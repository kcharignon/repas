<?php

namespace Repas\User\Infrastructure\Security;


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

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly ShoppingListRepository $shoppingListRepository,
        private readonly RecipeRepository $recipeRepository,
    ) {
    }


    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
                self::HIMSELF,
                self::INGREDIENT_OWNER,
                self::SHOPPING_LIST_OWNER,
                self::RECIPE_OWNER,
            ]) && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        switch ($attribute) {
            case self::HIMSELF:
                return $user->getId() === $subject;
            case self::INGREDIENT_OWNER:
                return str_starts_with($subject, $user->getId());
            case self::SHOPPING_LIST_OWNER:
                $shoppingList = $this->shoppingListRepository->findOneById($subject);
                return $shoppingList->getOwner()->isEqual($user);
            case self::RECIPE_OWNER:
                $recipe = $this->recipeRepository->findOneById($subject);
                return $recipe->getAuthor()->isEqual($user);
            default:
                return false;
        }
    }

}
