<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Shared\Application\Dto\IngredientSuggestion;
use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Dto\SpokenResolution;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;

/**
 * Adaptateur du port `SpokenIngredientResolver` : charge les ingrédients de l'utilisateur et leur habitude, puis laisse le service de
 * domaine `SpokenIngredientRanker` choisir (`rank`) ou, à défaut, proposer (`suggest`) — avec les MÊMES candidats, lus une seule fois. La mémoire de phrase (ce que l'utilisateur a déjà choisi,
 * ADR 0029 décision 11) est lue AVANT le classement et écrite par une commande sur le bus transactionnel (`remember`).
 */
final readonly class SpokenIngredientResolverAdapter implements SpokenIngredientResolver
{
    private SpokenIngredientRanker $ranker;

    public function __construct(
        private SpokenIngredientCandidates $candidates,
        private SpokenPhraseMemory $memory,
        private CommandBusInterface $commandBus,
    ) {
        $this->ranker = new SpokenIngredientRanker();
    }

    public function resolveOrSuggest(string $userId, string $phrase): SpokenResolution
    {
        // Une seule lecture des ingrédients de l'utilisateur, pour choisir comme pour proposer
        $candidates = $this->candidates->forUser($userId);
        if ($candidates === []) {
            return SpokenResolution::unknown();
        }

        $key = SpokenIngredientRanker::phraseKey($phrase);
        $remembered = $key === '' ? null : $this->memory->rememberedSlug($userId, $key);

        $ranked = $this->ranker->rank($phrase, $candidates, rememberedSlug: $remembered);
        if ($ranked !== null) {
            return SpokenResolution::found(new ResolvedIngredient($ranked->slug, $ranked->name, $ranked->departmentName, $ranked->confidence->value));
        }

        return SpokenResolution::unknown(array_map(
            static fn(RankedIngredient $suggested): IngredientSuggestion => new IngredientSuggestion($suggested->slug, $suggested->name, $suggested->departmentName),
            $this->ranker->suggest($phrase, $candidates),
        ));
    }

    public function remember(string $userId, string $phrase, string $ingredientSlug): void
    {
        $this->commandBus->dispatch(new RememberSpokenPhraseCommand($userId, $phrase, $ingredientSlug));
    }
}
