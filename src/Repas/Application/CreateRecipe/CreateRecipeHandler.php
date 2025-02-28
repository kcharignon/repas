<?php

namespace Repas\Repas\Application\CreateRecipe;

use Repas\Repas\Domain\Event\RecipeCreatedEvent;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class CreateRecipeHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private RecipeTypeRepository $typeRepository,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
        private RecipeRepository $recipeRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws RecipeException
     * @throws UserException
     */
    public function __invoke(CreateRecipeCommand $command): void
    {
        $author = $this->userRepository->findOneById($command->authorId);
        $type = $this->typeRepository->findOneBySlug($command->typeSlug);

        $recipe = Recipe::create(
            id: $command->id,
            name: $command->name,
            servings: $command->serving,
            author: $author,
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

        $this->recipeRepository->save($recipe);

        $this->eventDispatcher->dispatch(new RecipeCreatedEvent($author->getId(), $recipe->getId()));
    }
}
