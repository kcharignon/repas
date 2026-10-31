<?php

namespace Repas\User\Infrastructure\Console;

use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\RecountUserStatistics\RecountedStatistics;
use Repas\User\Application\RecountUserStatistics\RecountUserStatisticsCommand;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'repas:update-users-statistics',
    description: 'Initialize les statistiques pour tous les utilisateurs',
)]
class UpdateUserStatisticsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CommandBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->userRepository->findAll() as $user) {
            /** @var RecountedStatistics $recounted */
            $recounted = $this->commandBus->dispatch(new RecountUserStatisticsCommand($user->getId()));
            $io->info("{$user->getEmail()} : {$recounted->ingredients} ingredient(s) et {$recounted->recipes} recipes");
        }
        $io->success('Les statistiques utilisateurs ont été initialisées avec succès.');

        return Command::SUCCESS;
    }
}
