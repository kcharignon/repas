<?php

namespace Repas\Repas\Application\Command\UpdateIngredientConversion;

use Repas\Repas\Application\Service\IngredientOwnConversions;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Conversion;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateIngredientConversionHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private ConversionRepository $conversionRepository,
        private IngredientSlugResolver $slugResolver,
        private IngredientOwnConversions $ownConversions,
    ) {
    }

    /**
     * Remplace une conversion PROPRE de l'ingrédient de l'utilisateur. Rien n'est écrit si, avec le changement, l'unité des recettes ne se
     * convertissait plus en l'unité d'achat. Une conversion d'un autre ingrédient ou universelle est inconnue.
     *
     * @throws IngredientException
     * @throws UnitException
     * @throws ConversionException inconnue ou pas à cet ingrédient, unités identiques, paire déjà prise, ou conversion nécessaire à l'ingrédient
     */
    public function __invoke(UpdateIngredientConversionCommand $command): void
    {
        $slug = $this->slugResolver->resolve($command->userId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $ingredient->assertOwnedBy($command->userId);

        $conversion = $this->ownConversions->ownConversion($ingredient, $command->conversionId);
        [$start, $end] = $this->ownConversions->units($command->startUnitSlug, $command->endUnitSlug, $command->coefficient);
        $this->ownConversions->assertPairIsFree($ingredient, $start, $end, $conversion->getId());

        // Le jeu de conversions tel qu'il serait, jugé AVANT d'écrire
        $this->ownConversions->assertRecipesStillConvert($ingredient, $this->ownConversions->conversionsAfter(
            $ingredient,
            $conversion->getId(),
            Conversion::create($conversion->getId(), $start, $end, $command->coefficient, $ingredient),
        ));

        $conversion->update(startUnit: $start, endUnit: $end, coefficient: $command->coefficient, ingredient: $ingredient);
        $this->conversionRepository->save($conversion);
        $this->ownConversions->refreshCompatibleUnits($ingredient);
    }
}
