<?php

namespace Repas\Repas\Application\Command\ChooseIngredientList;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Service\ListDestinationResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Règle le « produit à part » : la liste où va ce produit, quel que soit son rayon (ADR 0036 décisions 3 et 5). Trois valeurs : `default`
 * (comme son rayon : le choix est effacé), `main` (la liste principale) ou un type de liste de l'utilisateur. Le choix se fige à l'ajout :
 * aucune ligne déjà dans une liste ne bouge.
 */
#[AsMessageHandler]
readonly class ChooseIngredientListHandler
{
    public function __construct(
        private IngredientRepository $ingredientRepository,
        private ShoppingListTypeRepository $listTypeRepository,
        private IngredientSlugResolver $slugResolver,
    ) {
    }

    /**
     * @throws IngredientException le produit est inconnu ou à un autre utilisateur (administrateur compris)
     * @throws ShoppingListTypeException le type est inconnu ou à un autre utilisateur : rien n'est écrit
     */
    public function __invoke(ChooseIngredientListCommand $command): void
    {
        $slug = $this->slugResolver->resolve($command->userId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        $ingredient = $this->ingredientRepository->findOneBySlug($slug);
        $ingredient->assertOwnedBy($command->userId);

        $destination = match ($command->destination) {
            ChooseIngredientListCommand::DEFAULT => null,
            ChooseIngredientListCommand::MAIN => ListDestinationResolver::MAIN,
            default => $this->ownedTypeId($command->destination, $command->userId),
        };

        // Un choix qui ne change rien n'écrit rien
        if ($ingredient->getListDestination() === $destination) {
            return;
        }

        $this->ingredientRepository->save($ingredient->changeListDestination($destination));
    }

    /**
     * @throws ShoppingListTypeException
     */
    private function ownedTypeId(string $typeId, string $userId): string
    {
        $listType = $this->listTypeRepository->findOneById($typeId);
        // Le type d'un autre est un type inconnu (404), un administrateur compris
        $listType->assertOwnedBy($userId);

        return $listType->getId();
    }
}
