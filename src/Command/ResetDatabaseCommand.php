<?php

namespace Repas\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ResetDatabaseCommand extends Command
{
    protected static $defaultName = 'repas:reset-database';
    protected static $defaultDescription = 'Réinitialise complètement la base de données, recrée les migrations et recharge les fixtures';

    protected function configure(): void
    {
        $this->setName(self::$defaultName);
        $this->setDescription(self::$defaultDescription);
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectDir = $this->getApplication()->getKernel()->getProjectDir();
        $io->info("Folder: $projectDir");

        shell_exec('rm -rf migrations/*.php');

        $commands = [
            'Supprimer la base de données' => ['php', 'bin/console', 'doctrine:database:drop', '--force'],
            'Créer la base de données' => ['php', 'bin/console', 'doctrine:database:create'],
            'Supprimer les migrations' => ['rm', '-rf', 'migrations/*'],
            'Générer une nouvelle migration' => ['php', 'bin/console', 'doctrine:migrations:diff'],
            'Appliquer les migrations' => ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'],
            'Charger les fixtures' => ['php', 'bin/console', 'doctrine:fixtures:load', '--no-interaction'],
        ];

        foreach ($commands as $description => $command) {
            $io->section($description);
            $process = new Process($command);
            $process->setTimeout(60); // Timeout de 10 minutes

            try {
                $io->info(implode(' ', $command));
                $process->mustRun();
                $io->success($description . ' : Succès');
            } catch (ProcessFailedException $exception) {
                $io->error($description . ' : Échec');
                $io->error($exception->getMessage());
                return Command::FAILURE;
            }
        }

        $io->success('La base de données a été réinitialisée avec succès !');
        return Command::SUCCESS;
    }
}
