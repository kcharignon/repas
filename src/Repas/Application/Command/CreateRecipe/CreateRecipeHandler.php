<?php

namespace Repas\Repas\Application\Command\CreateRecipe;

use Repas\Shared\Domain\Event\RecipeCreatedEvent;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateRecipeHandler
{
    public function __construct(
        private RecipeTypeRepository $typeRepository,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
        private RecipeRepository $recipeRepository,
        private ConversionService $conversionService,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws RecipeException
     * @throws IngredientException une unité sans chemin de conversion vers l'unité d'achat de son ingrédient
     */
    public function __invoke(CreateRecipeCommand $command): void
    {
        $type = $this->typeRepository->findOneBySlug($command->typeSlug);

        $recipe = Recipe::create(
            id: $command->id,
            name: $command->name,
            servings: $command->serving,
            authorId: $command->authorId,
            recipeType: $type,
            rows: $command->rows->map(fn(CreateRecipeRowSubCommand $subCmd) => RecipeRow::create(
                id: UuidGenerator::new(),
                recipeId: $command->id,
                ingredient: $this->ingredientRepository->findOneBySlug($subCmd->ingredientSlug),
                quantity: $subCmd->quantity,
                unit: $this->unitRepository->findOneBySlug($subCmd->unitSlug),
            )),
            originalId: null,
        );

        foreach ($recipe->getRows() as $row) {
            $this->conversionService->assertConvertibleToPurchaseUnit($row->getIngredient(), $row->getUnit());
        }

        $this->recipeRepository->save($recipe);

        $this->eventDispatcher->dispatch(new RecipeCreatedEvent($command->authorId, $recipe->getId()));
    }
}
