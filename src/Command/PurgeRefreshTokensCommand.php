<?php

namespace Repas\Command;


use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\PurgeExpiredRefreshTokens\PurgeExpiredRefreshTokensCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'repas:purge-refresh-tokens', description: 'Supprime les refresh tokens expirés (ADR 0015 §13) ; à planifier une fois par jour')]
class PurgeRefreshTokensCommand extends Command
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->commandBus->dispatch(new PurgeExpiredRefreshTokensCommand());
        (new SymfonyStyle($input, $output))->success(sprintf('%d refresh token(s) expiré(s) supprimé(s).', $deleted));

        return Command::SUCCESS;
    }
}
