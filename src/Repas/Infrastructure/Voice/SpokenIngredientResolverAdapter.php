<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;

/**
 * Adaptateur du port `SpokenIngredientResolver` : charge les ingrédients de l'utilisateur et leur habitude, puis laisse le service de
 * domaine `SpokenIngredientRanker` choisir. La mémoire de phrase (ce que l'utilisateur a déjà corrigé) n'existe pas encore : `null`
 * (VOX-301 l'ajoutera).
 */
final readonly class SpokenIngredientResolverAdapter implements SpokenIngredientResolver
{
    private SpokenIngredientRanker $ranker;

    public function __construct(
        private SpokenIngredientCandidates $candidates,
    ) {
        $this->ranker = new SpokenIngredientRanker();
    }

    public function resolve(string $userId, string $phrase): ?ResolvedIngredient
    {
        $candidates = $this->candidates->forUser($userId);
        if ($candidates === []) {
            return null;
        }

        $ranked = $this->ranker->rank($phrase, $candidates, rememberedSlug: null);
        if ($ranked === null) {
            return null;
        }

        return new ResolvedIngredient($ranked->slug, $ranked->name, $ranked->departmentName, $ranked->confidence->value);
    }
}
