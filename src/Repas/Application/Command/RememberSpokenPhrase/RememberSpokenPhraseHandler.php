<?php

namespace Repas\Repas\Application\Command\RememberSpokenPhrase;

use Psr\Clock\ClockInterface;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\SpokenPhraseChoiceRepository;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retient le choix de l'utilisateur pour une phrase (ADR 0029 décision 11, VOX-303). Le slug reçu n'est jamais cru : traduit par les alias
 * de l'utilisateur, puis l'ingrédient doit être à lui (celui d'un autre est inconnu, `404`, rien n'est écrit). Une phrase sans mot qui
 * compte n'écrit rien. Limite connue : si l'utilisateur crée plus tard un ingrédient au nom exact d'une phrase retenue, la mémoire gagne
 * encore (la règle 3 de l'ADR l'emporte sur le nom identique).
 */
#[AsMessageHandler]
readonly class RememberSpokenPhraseHandler
{
    public function __construct(
        private SpokenPhraseChoiceRepository $choiceRepository,
        private IngredientRepository $ingredientRepository,
        private IngredientSlugResolver $slugResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws IngredientException
     */
    public function __invoke(RememberSpokenPhraseCommand $command): void
    {
        $key = SpokenIngredientRanker::phraseKey($command->phrase);
        if ($key === '') {
            return;
        }

        $ingredientSlug = $this->slugResolver->resolve($command->userId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        // L'ingrédient d'un autre ou inconnu est refusé (404) avant toute écriture
        $this->ingredientRepository->findPurchaseUnitSlugUsableBy($ingredientSlug, $command->userId);

        $this->choiceRepository->remember($command->userId, $key, $ingredientSlug, $this->clock->now());
    }
}
