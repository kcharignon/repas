<?php

namespace Repas\User\Infrastructure\Security;


use Repas\Shared\Application\Interface\OwnershipLoader;
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
        private readonly OwnershipLoader $ownershipLoader,
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
                // Un ingrédient commun (sans créateur) n'appartient à personne : seul un administrateur peut le modifier
                return $this->ownershipLoader->ingredientCreatorId($subject) === $user->getId();
            case self::SHOPPING_LIST_OWNER:
                return $this->ownershipLoader->shoppingListOwnerId($subject) === $user->getId();
            case self::RECIPE_OWNER:
                return $this->ownershipLoader->recipeAuthorId($subject) === $user->getId();
            case self::MEAL_OWNER:
                return $this->ownershipLoader->mealOwnerId($subject) === $user->getId();
            default:
                return false;
        }
    }

}
