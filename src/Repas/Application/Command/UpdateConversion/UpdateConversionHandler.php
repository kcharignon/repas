<?php

namespace Repas\Repas\Application\Command\UpdateConversion;

use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateConversionHandler
{

    public function __construct(
        private ConversionRepository $conversionRepository,
        private UnitRepository $unitRepository,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    /**
     * Une conversion propre à un ingrédient d'un autre est inconnue, et on ne la rattache pas à l'ingrédient d'un autre : un administrateur
     * n'a de passe-droit que sur les conversions générales et sur celles de SES ingrédients (ADR 0028, amendement, D4).
     *
     * Une conversion universelle (sans ingrédient) reste universelle et garde sa paire d'unités : seul son coefficient se corrige. Elle ne
     * devient jamais propre à un ingrédient, et sa paire ne change pas (ce serait la supprimer : le code ne retire aucune conversion
     * universelle, ni ici ni ailleurs).
     *
     * @throws ConversionException
     * @throws IngredientException
     */
    public function __invoke(UpdateConversionCommand $command): void
    {
        $conversion = $this->conversionRepository->findById($command->id);
        if ($conversion->getIngredient() !== null && !$conversion->getIngredient()->isOwnedBy($command->userId)) {
            throw ConversionException::notFound($command->id);
        }
        if ($conversion->getIngredient() === null && (
            ($command->ingredientSlug ?? '') !== ''
            || $command->startUnitSlug !== $conversion->getStartUnit()->getSlug()
            || $command->endUnitSlug !== $conversion->getEndUnit()->getSlug()
        )) {
            throw ConversionException::universalIsFixed();
        }
        $startUnit = $this->unitRepository->findOneBySlug($command->startUnitSlug);
        $endUnit = $this->unitRepository->findOneBySlug($command->endUnitSlug);
        $ingredient = $command->ingredientSlug ? $this->ingredientRepository->findOneBySlug($command->ingredientSlug) : null;
        $ingredient?->assertOwnedBy($command->userId);

        $conversion->update(
            startUnit: $startUnit,
            endUnit: $endUnit,
            coefficient: $command->coefficient,
            ingredient: $ingredient,
        );

        $this->conversionRepository->save($conversion);
    }
}
