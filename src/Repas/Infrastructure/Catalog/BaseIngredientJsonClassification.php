<?php

namespace Repas\Repas\Infrastructure\Catalog;


use LogicException;
use Repas\Repas\Domain\Interface\BaseIngredientClassification;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le classement « de base » des 261 anciens communs, lu dans `Data/base-ingredients.json` (ING-009) : la copie sous `src/` du fichier validé
 * `docs/ingredients/base-ingredients.tsv` (`docs/` n'est pas dans l'image de production). Le test `BaseIngredientsDataTest` échoue si les deux
 * divergent. Le fichier est une DONNÉE validée : ce code ne la corrige jamais, et refuse un fichier incohérent (slug en double, marqueur absent).
 */
final class BaseIngredientJsonClassification implements BaseIngredientClassification
{
    /** @var ?array<string, bool> */
    private ?array $classification = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Repas/Infrastructure/Data/base-ingredients.json')]
        private readonly string $file,
    ) {
    }

    public function all(): array
    {
        if ($this->classification !== null) {
            return $this->classification;
        }

        $content = @file_get_contents($this->file);
        if ($content === false) {
            throw new LogicException('Le fichier de classement des ingrédients « de base » est introuvable.');
        }

        $classification = [];
        foreach (json_decode($content, true, 8, JSON_THROW_ON_ERROR) as $entry) {
            if (!is_array($entry) || !is_string($entry['slug'] ?? null) || $entry['slug'] === '' || !is_bool($entry['base'] ?? null)) {
                throw new LogicException('Le fichier de classement des ingrédients « de base » est invalide : chaque ligne a un slug et un marqueur booléen.');
            }
            if (isset($classification[$entry['slug']])) {
                throw new LogicException('Le fichier de classement des ingrédients « de base » est invalide : un slug est en double.');
            }
            $classification[$entry['slug']] = $entry['base'];
        }

        return $this->classification = $classification;
    }
}
