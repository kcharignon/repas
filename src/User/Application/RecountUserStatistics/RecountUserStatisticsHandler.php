<?php

namespace Repas\User\Application\RecountUserStatistics;

use Repas\User\Application\Port\CreatedContentCommandPort;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class RecountUserStatisticsHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private CreatedContentCommandPort $content,
    ) {
    }

    public function __invoke(RecountUserStatisticsCommand $command): RecountedStatistics
    {
        $user = $this->userRepository->findOneById($command->userId);

        $ingredients = $this->content->createdIngredients($command->userId);
        $recipes = $this->content->recipes($command->userId);

        $statistics = $user->getStatistics();
        $statistics['ingredients'] = $ingredients;
        $statistics['recipes'] = $recipes;
        $user->setStatistics($statistics);

        $this->userRepository->save($user);

        return new RecountedStatistics($ingredients, $recipes);
    }
}
