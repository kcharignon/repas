<?php

namespace Repas\Repas\Application\Command\CreateIngredientConversion;

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
readonly class CreateIngredientConversionHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private ConversionRepository $conversionRepository,
        private IngredientSlugResolver $slugResolver,
        private IngredientOwnConversions $ownConversions,
    ) {
    }

    /**
     * Ajoute une conversion PROPRE à l'ingrédient de l'utilisateur, vers n'importe quelle unité, dans la limite de
     * `Conversion::MAX_PER_INGREDIENT` par ingrédient. Elle ne peut que relier davantage d'unités : la règle « recettes -> achat » n'a pas à
     * être rejugée. Jamais une conversion universelle.
     *
     * @throws IngredientException l'ingrédient n'est pas à l'utilisateur (inconnu, un administrateur compris), ou coefficient invalide
     * @throws UnitException
     * @throws ConversionException deux unités identiques, la paire existe déjà en propre, ou l'ingrédient a déjà toutes ses conversions
     */
    public function __invoke(CreateIngredientConversionCommand $command): void
    {
        // Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) désigne l'ingrédient actuel (ADR 0028 décision 4)
        $slug = $this->slugResolver->resolve($command->userId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $ingredient->assertOwnedBy($command->userId);

        // Le plafond d'abord : un ingrédient qui l'a atteint ne coûte rien de plus
        if ($this->conversionRepository->ingredientHasAtLeast($ingredient, Conversion::MAX_PER_INGREDIENT)) {
            throw ConversionException::limitReached();
        }

        [$start, $end] = $this->ownConversions->units($command->startUnitSlug, $command->endUnitSlug, $command->coefficient);
        $this->ownConversions->assertPairIsFree($ingredient, $start, $end);

        $this->conversionRepository->save(Conversion::create($command->id, $start, $end, $command->coefficient, $ingredient));
        $this->ownConversions->refreshCompatibleUnits($ingredient);
    }
}
