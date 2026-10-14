<?php

namespace Repas\User\Application\RecordCompletedShoppingList;

use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RecordCompletedShoppingListHandler
{

    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @throws UserException
     */
    public function __invoke(RecordCompletedShoppingListCommand $command): void
    {
        $user = $this->userRepository->findOneById($command->userId);

        $user->completedShoppingList(
            $command->shoppingListId,
            $command->createdAt,
            $command->mealCount,
            $command->ingredientCount,
            $command->rowCount,
        );

        $this->userRepository->save($user);
    }
}
