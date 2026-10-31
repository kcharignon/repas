<?php

namespace Repas\Repas\Infrastructure\Security;


use Repas\Repas\Application\Loader\OwnershipLoader;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur les objets du contexte `Repas` (ADR 0035 : chaque contexte juge les siens ; `HIMSELF` est au contexte `User`, `VOICE_LINK_OWNER`
 * au contexte `Voice`). Un attribut de sécurité n'est qu'une chaîne : les controllers des autres contextes qui citent `SHOPPING_LIST_OWNER`
 * (le `Drive`) n'importent pas cette classe.
 */
class OwnerVoter extends Voter
{
    public const string INGREDIENT_OWNER = 'INGREDIENT_OWNER';
    public const string SHOPPING_LIST_OWNER = 'SHOPPING_LIST_OWNER';
    public const string RECIPE_OWNER = 'RECIPE_OWNER';
    public const string MEAL_OWNER = 'MEAL_OWNER';
    public const string SHOPPING_LIST_ROW_OWNER = 'SHOPPING_LIST_ROW_OWNER';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly OwnershipLoader $ownershipLoader,
    ) {
    }


    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
                self::INGREDIENT_OWNER,
                self::SHOPPING_LIST_OWNER,
                self::RECIPE_OWNER,
                self::MEAL_OWNER,
                self::SHOPPING_LIST_ROW_OWNER,
            ]) && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // Un administrateur passe pour les recettes, les listes et les repas des autres, JAMAIS pour les ingrédients : un ingrédient est à
        // son propriétaire seul, sans passe-droit (ADR 0028, amendement du 2026-10-20, D3). Pour une recette, le passe-droit s'arrête à la
        // recette SECRÈTE d'un autre (ADR 0033), vérifiée plus bas
        $isAdmin = $this->accessDecisionManager->decide($token, ['ROLE_ADMIN']);
        if ($isAdmin && !in_array($attribute, [self::INGREDIENT_OWNER, self::RECIPE_OWNER], true)) {
            return true;
        }

        $user = $token->getUser();

        if (!$user instanceof AuthenticatedUser || $user->isDisabled()) {
            return false;
        }

        // Une ressource introuvable donne un 404, pas une erreur 500
        try {
            if ($isAdmin && $attribute === self::RECIPE_OWNER) {
                // L'administrateur modifie et supprime toute recette ordinaire ; la recette secrète d'un autre n'existe pas pour lui, comme pour
                // tout utilisateur : `recipeAuthorIdFor` la traite en recette inconnue (404)
                $this->ownershipLoader->recipeAuthorIdFor($subject, $user->getId());

                return true;
            }

            return $this->owns($attribute, $subject, $user);
        } catch (DomainException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }
    }

    private function owns(string $attribute, string $subject, AuthenticatedUser $user): bool
    {
        switch ($attribute) {
            case self::INGREDIENT_OWNER:
                // Tout ingrédient a un propriétaire : lui seul le modifie ou le supprime, un administrateur compris
                return $this->ownershipLoader->ingredientCreatorId($subject) === $user->getId();
            case self::SHOPPING_LIST_OWNER:
                return $this->ownershipLoader->shoppingListOwnerId($subject) === $user->getId();
            case self::RECIPE_OWNER:
                // Une recette secrète d'un autre n'existe pas pour lui : `404`, pas `403` (ADR 0033) ; une recette ordinaire d'un autre est refusée
                return $this->ownershipLoader->recipeAuthorIdFor($subject, $user->getId()) === $user->getId();
            case self::MEAL_OWNER:
                return $this->ownershipLoader->mealOwnerId($subject) === $user->getId();
            case self::SHOPPING_LIST_ROW_OWNER:
                return $this->ownershipLoader->shoppingListRowOwnerId($subject) === $user->getId();
            default:
                return false;
        }
    }

}
