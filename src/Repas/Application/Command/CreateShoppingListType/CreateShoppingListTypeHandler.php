<?php

namespace Repas\Repas\Application\Command\CreateShoppingListType;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Shared\Domain\Tool\StringTool;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Crée un type de liste de courses (ADR 0036) pour l'utilisateur : un nom, aucun rayon (on les rattache ensuite, `UpdateShoppingListType`).
 */
#[AsMessageHandler]
readonly class CreateShoppingListTypeHandler
{
    public function __construct(
        private ShoppingListTypeRepository $listTypeRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ShoppingListTypeException le nom est invalide, le compte a déjà `ShoppingListType::MAX_PER_OWNER` types, ou un type de ce nom
     */
    public function __invoke(CreateShoppingListTypeCommand $command): void
    {
        $listType = ShoppingListType::create(
            id: $command->id,
            ownerId: $command->userId,
            name: StringTool::capitalizeFirst($command->name),
            createdAt: $this->clock->now(),
        );

        // Le plafond d'abord : rien n'est écrit au-delà
        if ($this->listTypeRepository->ownerHasAtLeast($command->userId, ShoppingListType::MAX_PER_OWNER)) {
            throw ShoppingListTypeException::limitReached();
        }
        if ($this->listTypeRepository->ownerHasTypeNamed($command->userId, $listType->getSluggedName())) {
            throw ShoppingListTypeException::nameAlreadyUsed();
        }

        $this->listTypeRepository->save($listType);
    }
}
