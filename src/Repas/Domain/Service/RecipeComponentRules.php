<?php

namespace Repas\Repas\Domain\Service;


use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\Recipe;

/**
 * Les règles d'une recette qui en utilise une autre et qui regardent DEUX recettes (ADR 0040 décisions 6 à 9), écrites une fois : la recette
 * composée et la recette utilisée, déjà chargées par le cas d'usage. Pur : aucune lecture, des modèles et des booléens que le cas d'usage a posés
 * au port de la recette (`isUsedAsComponent`, `isUsedByOrdinaryRecipe`, `hasSecretComponent`).
 *
 * Les règles se jugent sur l'ÉTAT FINAL de la requête (décision 8) : un `PUT` peut changer `secret` et `components` ensemble, le cas d'usage
 * applique d'abord tout ce qui change dans la recette composée puis appelle ces méthodes. Aucun passe-droit : un administrateur qui modifie la
 * recette d'un autre compare avec l'AUTEUR de la recette, jamais avec lui-même.
 *
 * Ce qui ne regarde qu'un composant ou l'ensemble des composants d'une recette (multiplicateur, doublon, soi-même, plafond) est jugé par
 * `RecipeComponent` et `Recipe::setComponents`.
 */
final readonly class RecipeComponentRules
{
    /**
     * La recette composée peut-elle utiliser cette recette ? Les refus, dans l'ordre de l'ADR 0040 décision 26 :
     *  1. la recette elle-même : `422 RECIPE_COMPONENT_SELF` ;
     *  2. une recette sans propriétaire n'est jamais composée : `422 RECIPE_COMPONENT_OWNERLESS` ;
     *  3. la recette utilisée est d'un autre auteur (ou secrète d'un autre, c'est le même cas) : `404`, avec le message d'une recette inconnue, on ne
     *     révèle rien ;
     *  4. la recette composée est déjà utilisée par une autre : `422 RECIPE_COMPONENT_CHAIN` (profondeur 1, premier sens) ;
     *  5. la recette utilisée a elle-même des composants : `422 RECIPE_COMPONENT_CHAIN` (second sens) ;
     *  6. une recette ordinaire n'utilise qu'une recette ordinaire : `409 RECIPE_COMPONENT_SECRET`.
     *
     * Pour plusieurs recettes utilisées, le cas d'usage appelle chaque étape pour TOUTES les recettes avant la suivante (`assertFound`, puis
     * `assertDepth`, puis `assertSecrecy`) : celle-ci est l'enchaînement pour une seule recette.
     *
     * @param bool $parentIsUsed une autre recette utilise déjà la recette composée (`RecipeRepository::isUsedAsComponent`)
     * @param string|null $editorId l'utilisateur qui écrit (voir `assertFound`)
     *
     * @throws RecipeException
     */
    public function assertCanUse(Recipe $parent, Recipe $component, bool $parentIsUsed, ?string $editorId = null): void
    {
        if ($parent->getId() === $component->getId()) {
            throw RecipeException::componentSelf();
        }
        if ($parent->isMarmitonNeutral()) {
            throw RecipeException::componentOwnerless();
        }
        $this->assertFound($parent, $component, $editorId);
        $this->assertDepth($parent, $component, $parentIsUsed);
        $this->assertSecrecy($parent, $component);
    }

    /**
     * La recette utilisée existe, pour qui écrit (étape 3) : elle est de l'AUTEUR de la recette composée (jamais celle d'un autre compte, on la
     * copie d'abord) et, si `$editorId` est donné, visible de celui qui écrit. Sans ce second contrôle, l'administrateur qui modifie la recette
     * ordinaire d'un autre apprendrait par un `409` qu'une recette secrète de cet auteur existe : sans passe-droit, elle est pour lui une recette
     * inconnue.
     *
     * @throws RecipeException `404`, avec le message d'une recette inconnue
     */
    public function assertFound(Recipe $parent, Recipe $component, ?string $editorId = null): void
    {
        if ($parent->getAuthorId() !== $component->getAuthorId() || ($editorId !== null && !$component->isVisibleTo($editorId))) {
            throw RecipeException::notFound($component->getId());
        }
    }

    /**
     * Profondeur 1, dans les deux sens (étapes 4 et 5) : `422 RECIPE_COMPONENT_CHAIN`.
     *
     * @param bool $parentIsUsed une autre recette utilise déjà la recette composée
     *
     * @throws RecipeException
     */
    public function assertDepth(Recipe $parent, Recipe $component, bool $parentIsUsed): void
    {
        if ($parentIsUsed || $component->hasComponents()) {
            throw RecipeException::componentChain();
        }
    }

    /**
     * Une recette ordinaire n'utilise qu'une recette ordinaire (étape 6) : `409 RECIPE_COMPONENT_SECRET`.
     *
     * @throws RecipeException
     */
    public function assertSecrecy(Recipe $parent, Recipe $component): void
    {
        if (!$parent->isSecret() && $component->isSecret()) {
            throw RecipeException::componentSecret();
        }
    }

    /**
     * Une recette contient quelque chose (décision 9) : au moins une ligne ou une recette utilisée, sur l'état final. Faite seulement de recettes
     * utilisées, elle est permise.
     *
     * @throws RecipeException `422 RECIPE_EMPTY`
     */
    public function assertNotEmpty(Recipe $recipe): void
    {
        if ($recipe->getRows()->count() === 0 && !$recipe->hasComponents()) {
            throw RecipeException::empty();
        }
    }

    /**
     * Rendre une recette secrète (décision 8) : refusé tant qu'une recette ORDINAIRE l'utilise, sans quoi lire ou copier celle-là montrerait le
     * contenu de celle-ci.
     *
     * @param bool $usedByOrdinary une recette ordinaire l'utilise (`RecipeRepository::isUsedByOrdinaryRecipe`)
     *
     * @throws RecipeException `409 RECIPE_SECRET_USED_BY_PUBLIC`
     */
    public function assertCanBecomeSecret(bool $usedByOrdinary): void
    {
        if ($usedByOrdinary) {
            throw RecipeException::secretUsedByPublic();
        }
    }

    /**
     * Rendre une recette de nouveau visible (décision 8) : refusé tant qu'elle utilise une recette secrète.
     *
     * @param bool $hasSecretComponent l'un de ses composants est secret (`RecipeRepository::hasSecretComponent`)
     *
     * @throws RecipeException `409 RECIPE_COMPONENT_SECRET`
     */
    public function assertCanBecomePublic(bool $hasSecretComponent): void
    {
        if ($hasSecretComponent) {
            throw RecipeException::componentSecret();
        }
    }
}
