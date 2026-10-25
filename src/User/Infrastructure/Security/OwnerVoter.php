<?php

namespace Repas\User\Infrastructure\Security;


use Repas\Shared\Application\Interface\OwnershipLoader;
use Repas\Shared\Application\Interface\VoiceLinkOwnershipLoader;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\User\Domain\Model\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class OwnerVoter extends Voter
{
    public const string HIMSELF = 'HIMSELF';
    public const string INGREDIENT_OWNER = 'INGREDIENT_OWNER';
    public const string SHOPPING_LIST_OWNER = 'SHOPPING_LIST_OWNER';
    public const string RECIPE_OWNER = 'RECIPE_OWNER';
    public const string MEAL_OWNER = 'MEAL_OWNER';
    public const string SHOPPING_LIST_ROW_OWNER = 'SHOPPING_LIST_ROW_OWNER';
    public const string VOICE_LINK_OWNER = 'VOICE_LINK_OWNER';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly OwnershipLoader $ownershipLoader,
        private readonly VoiceLinkOwnershipLoader $voiceLinkOwnershipLoader,
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
                self::SHOPPING_LIST_ROW_OWNER,
                self::VOICE_LINK_OWNER,
            ]) && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // Un administrateur passe pour les recettes, les listes et les repas des autres, JAMAIS pour les ingrédients : un ingrédient est à
        // son propriétaire seul, sans passe-droit (ADR 0028, amendement du 2026-10-20, D3). Ni pour les liaisons avec Alexa : retirer
        // celle d'un autre couperait sa commande vocale (ADR 0029)
        if (!in_array($attribute, [self::INGREDIENT_OWNER, self::VOICE_LINK_OWNER], true) && $this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        $user = $token->getUser();

        if (!$user instanceof User || $user->isDisabled()) {
            return false;
        }

        // Une ressource introuvable donne un 404, pas une erreur 500
        try {
            return $this->owns($attribute, $subject, $user);
        } catch (DomainException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }
    }

    private function owns(string $attribute, string $subject, User $user): bool
    {
        switch ($attribute) {
            case self::HIMSELF:
                return $user->getId() === $subject;
            case self::INGREDIENT_OWNER:
                // Tout ingrédient a un propriétaire : lui seul le modifie ou le supprime, un administrateur compris
                return $this->ownershipLoader->ingredientCreatorId($subject) === $user->getId();
            case self::SHOPPING_LIST_OWNER:
                return $this->ownershipLoader->shoppingListOwnerId($subject) === $user->getId();
            case self::RECIPE_OWNER:
                return $this->ownershipLoader->recipeAuthorId($subject) === $user->getId();
            case self::MEAL_OWNER:
                return $this->ownershipLoader->mealOwnerId($subject) === $user->getId();
            case self::SHOPPING_LIST_ROW_OWNER:
                return $this->ownershipLoader->shoppingListRowOwnerId($subject) === $user->getId();
            case self::VOICE_LINK_OWNER:
                // Aucun passe-droit administrateur : la liaison est à son propriétaire seul
                return $this->voiceLinkOwnershipLoader->voiceLinkOwnerId($subject) === $user->getId();
            default:
                return false;
        }
    }

}
