<?php

namespace Repas\Repas\Application\Command\RemoveShoppingList;

use Psr\Clock\ClockInterface;
use Repas\Repas\Domain\Interface\ShoppingListArchiveRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RemoveShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private ShoppingListArchiveRepository $archiveRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Copie la liste dans les archives (ADR 0041), puis la supprime : la copie se fait avant, dans la même transaction du bus, donc une liste n'est jamais
     * supprimée sans sa trace, ni tracée sans être supprimée.
     */
    public function __invoke(RemoveShoppingListCommand $command): void
    {
        $shoppingList = $this->shoppingListRepository->findOneById($command->shoppingListId);

        $this->archiveRepository->archive(UuidGenerator::new(), $shoppingList->getId(), $command->cause, $this->clock->now());
        $this->shoppingListRepository->delete($shoppingList);
    }
}
