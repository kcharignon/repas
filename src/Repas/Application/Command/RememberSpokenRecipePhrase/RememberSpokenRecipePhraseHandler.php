<?php

namespace Repas\Repas\Application\Command\RememberSpokenRecipePhrase;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\SpokenRecipePhraseChoiceRepository;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retient le choix de l'utilisateur pour une phrase de recette (ADR 0029 décision 13, VOX-305). L'identifiant reçu n'est jamais cru : la recette doit
 * être à l'utilisateur (celle d'un autre est inconnue, `404`, rien n'est écrit). Une phrase sans mot qui compte n'écrit rien.
 */
#[AsMessageHandler]
readonly class RememberSpokenRecipePhraseHandler
{
    public function __construct(
        private SpokenRecipePhraseChoiceRepository $choiceRepository,
        private RecipeRepository $recipeRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RecipeException
     */
    public function __invoke(RememberSpokenRecipePhraseCommand $command): void
    {
        $key = SpokenIngredientRanker::phraseKey($command->phrase);
        if ($key === '') {
            return;
        }

        // La recette d'un autre ou inconnue est refusée (404) avant toute écriture
        $recipe = $this->recipeRepository->findOneById($command->recipeId);
        if ($recipe->getAuthorId() !== $command->userId) {
            throw RecipeException::notFound($command->recipeId);
        }

        $this->choiceRepository->remember($command->userId, $key, $recipe->getId(), $this->clock->now());
    }
}
