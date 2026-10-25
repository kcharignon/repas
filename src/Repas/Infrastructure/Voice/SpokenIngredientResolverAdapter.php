<?php

namespace Repas\Repas\Infrastructure\Voice;

use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Application\Loader\SpokenPhraseMemory;
use Repas\Repas\Domain\Model\Confidence;
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
            // Le classeur hésite entre des ingrédients qui se valent : on ne devine pas, on les rend pour que l'utilisateur départage (le rayon)
            $ties = $ranked->confidence === Confidence::UNSURE ? $this->ranker->ties($phrase, $candidates, $remembered) : [];
            if ($ties !== []) {
                return SpokenResolution::tied(array_map(self::suggestion(...), $ties));
            }

            return SpokenResolution::found(new ResolvedIngredient($ranked->slug, $ranked->name, $ranked->departmentName, $ranked->confidence->value));
        }

        return SpokenResolution::unknown(array_map(self::suggestion(...), $this->ranker->suggest($phrase, $candidates)));
    }

    private static function suggestion(RankedIngredient $ranked): IngredientSuggestion
    {
        return new IngredientSuggestion($ranked->slug, $ranked->name, $ranked->departmentName, $ranked->departmentSlug);
    }

    public function remember(string $userId, string $phrase, string $ingredientSlug): void
    {
        $this->commandBus->dispatch(new RememberSpokenPhraseCommand($userId, $phrase, $ingredientSlug));
    }
}
