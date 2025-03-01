<?php

namespace Repas\User\Application\UpdateUserStatistics;

use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class UpdateUserStatisticsHandler
{

    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function __invoke(UpdateUserStatisticsCommand $command): void
    {
        $user = $this->userRepository->findOneById($command->userId);

        $user->createIngredients($command->ingredients);
        $user->createRecipes($command->recipes);

        $this->userRepository->save($user);
    }
}
