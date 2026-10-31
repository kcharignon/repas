<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Application\Command\RememberSpokenRecipePhrase\RememberSpokenRecipePhraseCommand;
use Repas\Repas\Domain\Interface\RecipePhraseCandidates;
use Repas\Repas\Domain\Interface\SpokenRecipePhraseChoiceRepository;
use Repas\Repas\Domain\Model\RecipePhraseResolution;
use Repas\Repas\Domain\Service\RecipePhraseResolver;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Application\Interface\CommandBusInterface;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : quelle recette d'un utilisateur désigne une phrase (ADR 0029 décision 13,
 * VOX-305), quelles sont les plus proches quand aucune n'est sûre, et retenir son choix pour la suite. Pour un appelant qui écrit et en tire un
 * choix dans sa transaction. Des identifiants et du texte seulement, jamais un agrégat. Jumeau de `IngredientPhraseCommandOHS`, mais SÉPARÉ : une
 * recherche de recette ne regarde jamais les ingrédients, et inversement.
 */
final readonly class RecipePhraseCommandOHS
{
    public function __construct(
        private RecipePhraseCandidates $candidates,
        private SpokenRecipePhraseChoiceRepository $memory,
        private RecipePhraseResolver $resolver,
        private CommandBusInterface $commandBus,
    ) {
    }

    /**
     * Charge les recettes de l'utilisateur UNE fois, puis : la mémoire de phrase passe avant tout classement ; sinon le classeur choisit s'il
     * est sûr ou probable ; sinon — rien d'assez proche, ou plusieurs noms qui se touchent, on ne devine pas une recette — les trois plus proches
     * sont proposées. Jamais la recette d'un autre utilisateur.
     *
     * @param string $phrase la phrase reconnue (« hachis parmentier », « le gratin »), sans le mot « repas » ni « recette »
     */
    public function resolveOrSuggest(string $userId, string $phrase): RecipePhraseResolution
    {
        // Une seule lecture des recettes de l'utilisateur, pour choisir comme pour proposer
        $candidates = $this->candidates->forUser($userId);
        if ($candidates === []) {
            return $this->resolver->resolve($phrase, [], null);
        }

        $key = SpokenIngredientRanker::phraseKey($phrase);
        $remembered = $key === '' ? null : $this->memory->rememberedId($userId, $key);

        return $this->resolver->resolve($phrase, $candidates, $remembered);
    }

    /**
     * Retient que cette phrase désigne cette recette : la prochaine fois, `resolveOrSuggest()` la choisit tout de suite. N'écrit rien si la phrase
     * n'a aucun mot qui compte.
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException la recette n'existe pas ou est à un autre utilisateur (rien n'est écrit)
     */
    public function remember(string $userId, string $phrase, string $recipeId): void
    {
        $this->commandBus->dispatch(new RememberSpokenRecipePhraseCommand($userId, $phrase, $recipeId));
    }
}
