<?php

namespace Repas\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'repas:reset-opcache',
    description: 'Réinitialise l\'OPcache PHP',
)]
class ResetOpCacheCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!function_exists('opcache_reset')) {
            $output->writeln('La fonction opcache_reset() n\'est pas disponible sur ce serveur.');
            return Command::FAILURE;
        }

        if (opcache_reset()) {
            $output->writeln('OPcache a été réinitialisé avec succès.');
            return Command::SUCCESS;
        } else {
            $output->writeln('La réinitialisation d\'OPcache a échoué.');
            return Command::FAILURE;
        }
    }
}
