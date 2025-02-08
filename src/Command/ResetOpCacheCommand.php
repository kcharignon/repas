<?php

namespace Repas\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ResetOpCacheCommand extends Command
{
    protected static $defaultName = 'repas:reset-opcache';
    protected static $defaultDescription = 'Réinitialise l\'OPcache PHP';

    protected function configure(): void
    {
        $this->setName(self::$defaultName)
            ->setDescription(self::$defaultDescription);
    }

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
