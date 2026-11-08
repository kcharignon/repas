<?php

namespace Repas\Repas\Application\Command\RemoveIngredientParent;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Recolle une partie : l'ingrédient redevient ordinaire, sans parent ni ratio (ADR 0038 décision 28). Idempotent : un ingrédient qui n'est pas
 * une partie répond comme la première fois, sans rien écrire. Recoller est toujours permis, même quand une liste contient l'ingrédient : ce que
 * la liste a gardé de la partie ne se perd jamais (décision 9).
 */
#[AsMessageHandler]
readonly class RemoveIngredientParentHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private IngredientSlugResolver $slugResolver,
    ) {
    }

    /**
     * @throws IngredientException l'ingrédient est inconnu ou à un autre utilisateur (administrateur compris)
     */
    public function __invoke(RemoveIngredientParentCommand $command): void
    {
        $slug = $this->slugResolver->resolve($command->userId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $ingredient->assertOwnedBy($command->userId);

        if (!$ingredient->isPart()) {
            return;
        }

        $this->ingredientRepository->save($ingredient->detachFromParent());
    }
}
