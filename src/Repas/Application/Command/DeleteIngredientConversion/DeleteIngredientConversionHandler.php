<?php

namespace Repas\Repas\Application\Command\DeleteIngredientConversion;

use Repas\Repas\Application\Service\IngredientOwnConversions;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class DeleteIngredientConversionHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private ConversionRepository $conversionRepository,
        private IngredientSlugResolver $slugResolver,
        private IngredientOwnConversions $ownConversions,
    ) {
    }

    /**
     * Retire une conversion PROPRE de l'ingrédient de l'utilisateur, sauf si l'unité des recettes ne se convertirait plus alors en l'unité
     * d'achat (rien n'est écrit). Une conversion universelle ou d'un autre ingrédient est inconnue : ce chemin ne la retire jamais.
     *
     * @throws IngredientException
     * @throws ConversionException inconnue ou pas à cet ingrédient, ou conversion nécessaire à l'ingrédient
     */
    public function __invoke(DeleteIngredientConversionCommand $command): void
    {
        $slug = $this->slugResolver->resolve($command->userId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $ingredient->assertOwnedBy($command->userId);

        $conversion = $this->ownConversions->ownConversion($ingredient, $command->conversionId);
        $this->ownConversions->assertRecipesStillConvert($ingredient, $this->ownConversions->conversionsAfter($ingredient, $conversion->getId(), null));

        $this->conversionRepository->delete($conversion);
        $this->ownConversions->refreshCompatibleUnits($ingredient);
    }
}
