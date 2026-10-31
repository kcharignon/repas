<?php

namespace Repas\Drive\Application\OpenHostService;

use Repas\Drive\Application\Loader\IngredientChoiceLoader;

/**
 * Open Host Service de lecture du contexte `Drive` (ADR 0035) : les choix de produits vus par ingrédient, pour les rapports et les aperçus
 * d'un autre contexte (rapport de l'amorçage, aperçu de la fusion). Lecture seule, sans enseigne nommée.
 */
final readonly class IngredientChoiceQueryOHS
{
    public function __construct(
        private IngredientChoiceLoader $choices,
    ) {
    }

    public function fingerprint(string $userId): IngredientChoiceFingerprintDTO
    {
        $fingerprint = $this->choices->fingerprint($userId);

        return new IngredientChoiceFingerprintDTO(
            $fingerprint['choices'],
            $fingerprint['products'],
            $fingerprint['packSizeSum'],
            $fingerprint['contentHash'],
            $fingerprint['ingredientSlugByChoice'],
            $fingerprint['productCountByChoice'],
        );
    }

    /**
     * Les slugs des ingrédients que les choix de l'utilisateur désignent (toutes enseignes, tous magasins), sans doublon, triés.
     *
     * @return list<string>
     */
    public function referencedSlugs(string $userId): array
    {
        return $this->choices->referencedSlugs($userId);
    }

    /**
     * Les slugs que les choix de chaque utilisateur désignent : `[identifiant d'utilisateur => slugs triés, sans doublon]`.
     *
     * @return array<string, list<string>>
     */
    public function referencedSlugsOfEveryUser(): array
    {
        return $this->choices->referencedSlugsOfEveryUser();
    }

    /**
     * Ce que la fusion de l'ingrédient absorbé dans l'ingrédient gardé ferait des choix de l'utilisateur, sans rien écrire.
     */
    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): IngredientChoiceMergeImpactDTO
    {
        $impact = $this->choices->mergeImpact($userId, $absorbedSlug, $keptSlug);

        return new IngredientChoiceMergeImpactDTO($impact['moved'], $impact['dropped'], $impact['products']);
    }
}
