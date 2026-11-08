<?php

namespace Repas\Repas\Domain\Model;

use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Un composant : la recette composée utilise une autre recette du même compte, « n fois telle qu'elle est écrite » (ADR 0040 décisions 1, 4 et 5).
 *
 * Il ne désigne les deux recettes que par identifiant (ADR 0013). Le multiplicateur est indépendant du nombre de personnes de la recette utilisée,
 * qui ne sert qu'à l'affichage : une pâte écrite pour 6 avec 250 g de farine, utilisée « 2 fois », apporte 500 g de farine. La position range les
 * composants d'une recette (l'ordre du corps de la requête).
 *
 * Les règles qui ne regardent que le composant (le multiplicateur, la recette elle-même) sont ici ; celles qui regardent les recettes (même auteur,
 * profondeur 1, secrète et ordinaire) sont dans `RecipeComponentRules`, celles qui regardent l'ensemble des composants dans `Recipe::setComponents`.
 */
final class RecipeComponent implements ModelInterface
{
    use ModelTrait;

    /** Combien de fois la recette utilisée, telle qu'elle est écrite : de 0,01 (un centième de la recette) à 100 fois. */
    public const float MIN_MULTIPLIER = 0.01;
    public const float MAX_MULTIPLIER = 100.0;

    private function __construct(
        private string $id,
        private string $recipeId,
        private string $componentId,
        private float $multiplier,
        private int $position,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * La recette COMPOSÉE, celle qui en utilise une autre.
     */
    public function getRecipeId(): string
    {
        return $this->recipeId;
    }

    /**
     * La recette UTILISÉE.
     */
    public function getComponentId(): string
    {
        return $this->componentId;
    }

    public function getMultiplier(): float
    {
        return $this->multiplier;
    }

    /**
     * Le rang du composant dans sa recette, de 0 : l'ordre dans lequel l'auteur les a donnés.
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Le même composant à un autre rang (le modèle ne se modifie pas : `Recipe::setComponents` range les siens dans l'ordre reçu).
     */
    public function withPosition(int $position): self
    {
        $moved = clone $this;
        $moved->position = $position;

        return $moved;
    }

    /**
     * Un nombre fini de 0,01 à 100, bornes comprises. Ni `NaN`, ni infini, ni zéro, ni négatif.
     */
    public static function isMultiplier(float $value): bool
    {
        return is_finite($value) && $value >= self::MIN_MULTIPLIER && $value <= self::MAX_MULTIPLIER;
    }

    /**
     * Reconstruction depuis la persistance : rien n'est jugé, une donnée déjà en base se lit telle quelle.
     *
     * @param array{id: string, recipe_id: string, component_id: string, multiplier: float, position?: int} $datas
     */
    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            recipeId: $datas['recipe_id'],
            componentId: $datas['component_id'],
            multiplier: (float) $datas['multiplier'],
            position: $datas['position'] ?? 0,
        );
    }

    /**
     * Ne juge que le multiplicateur : la recette qui se compose d'elle-même est refusée par `Recipe::setComponents`, après les autres contrôles du
     * corps (l'ordre de l'ADR 0040 décision 26).
     *
     * @throws RecipeException le multiplicateur est hors de 0,01 à 100
     */
    public static function create(string $id, string $recipeId, string $componentId, float $multiplier, int $position = 0): self
    {
        if (!self::isMultiplier($multiplier)) {
            throw RecipeException::componentMultiplierInvalid();
        }

        return new self($id, $recipeId, $componentId, $multiplier, $position);
    }
}
