<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Service\IngredientNameMatcher;

/**
 * Un ingrédient que le rapprochement peut retenir (ADR 0028 décision 8) : une valeur légère, lue sans hydrater de modèle
 * (`IngredientMatchCandidates`). Elle ne porte que ce que le rapprochement compare ou contrôle : identité, nom, rayon, unités, origine.
 * Les références sont des slugs (ADR 0013).
 */
final readonly class IngredientCandidate
{
    /** Le nom normalisé de comparaison, découpé en mots (`IngredientNameMatcher::tokens()`) ; jamais stocké. */
    public array $tokens;

    /**
     * @param list<string> $compatibleUnitSlugs
     * @param ?string $creatorId le propriétaire du candidat, `null` pour un commun
     * @param ?string $adoptedFrom le slug du commun dont le candidat est l'adoption
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $departmentSlug,
        public string $purchaseUnitSlug,
        public array $compatibleUnitSlugs = [],
        public ?string $creatorId = null,
        public ?string $adoptedFrom = null,
    ) {
        $this->tokens = IngredientNameMatcher::tokens($name);
    }

    /**
     * Le nom normalisé : les mots joints par « - ». Deux candidats de même nom normalisé sont le même nom pour l'utilisateur.
     */
    public function normalizedName(): string
    {
        return implode('-', $this->tokens);
    }

    public function isCommon(): bool
    {
        return $this->creatorId === null;
    }
}
