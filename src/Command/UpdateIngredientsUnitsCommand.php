<?php

namespace Repas\Command;

use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'repas:update-ingredients-units',
    description: 'Calcul les unités compatibles pour un ingredient (unités accessibles via conversion)',
)]
class UpdateIngredientsUnitsCommand extends Command
{
    public function __construct(
        private readonly IngredientRepository $ingredientRepository,
        private readonly ConversionService $conversionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ingredients = $this->ingredientRepository->findAll();

        foreach ($ingredients as $ingredient) {
            /** @var Ingredient $ingredient */
            $initialUnits = $ingredient->getCompatibleUnits();

            // Récupération des unités compatibles à partir des unités de cuisson et d'achat
            $compatibleFromCooking = $this->conversionService->getConvertibleUnits($ingredient, $ingredient->getDefaultCookingUnit());
            $compatibleFromPurchase = $this->conversionService->getConvertibleUnits($ingredient, $ingredient->getDefaultPurchaseUnit());

            // Fusionner les unités sans doublons et stocker les slugs uniquement
            $allCompatibleUnit = $compatibleFromCooking
                ->merge($compatibleFromPurchase)
                ->uniqueObject(fn(Unit $unit) => $unit->getSlug());

            // Vérifier si une mise à jour est nécessaire
            $status = $initialUnits->equalsCanonical($allCompatibleUnit, fn(Unit $unit) => $unit->getSlug()) ? 'OK' : 'UPDATED';

            // Mise à jour de l'ingrédient si nécessaire
            if ($status === 'UPDATED') {
                $ingredient->setCompatibleUnits($allCompatibleUnit);
                $this->ingredientRepository->save($ingredient);
            }

            // Formatage de l'affichage aligné
            $unitSlugs = $allCompatibleUnit->map(fn(Unit $unit) => $unit->getSlug())->implode(', ');
            $output->writeln(sprintf("%-7s : %-25s - [%s]", $status, $ingredient->getName(), $unitSlugs));
        }

        return Command::SUCCESS;
    }
}
