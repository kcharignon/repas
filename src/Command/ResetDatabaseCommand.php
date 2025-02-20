<?php

namespace Repas\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'repas:reset-database',
    description: 'Réinitialise complètement la base de données, rejoue les migrations et recharge les fixtures',
)]
class ResetDatabaseCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectDir = $this->getApplication()->getKernel()->getProjectDir();
        $io->info("Folder: $projectDir");


        // Construire la liste des commandes
        $commands['Supprimer la base de données'] = ['php', 'bin/console', 'doctrine:database:drop', '--force'];
        $commands['Créer la base de données'] = ['php', 'bin/console', 'doctrine:database:create'];

        $commands['Appliquer les migrations'] = ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'];
        $commands['Charger les fixtures'] = ['php', 'bin/console', 'doctrine:fixtures:load', '--no-interaction', '--group=dev'];

        // Exécuter les commandes
        foreach ($commands as $description => $command) {
            $io->section($description);

            $process = new Process($command, $projectDir);
            $process->setTimeout(60);

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
