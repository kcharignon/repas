<?php

namespace Repas\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption; // Ajouté
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
        $this
            ->setName(self::$defaultName)
            ->setDescription(self::$defaultDescription)
            ->addOption(
                'ignore-migrations',
                null,
                InputOption::VALUE_NONE,
                'Ignore complètement la suppression, la génération et l’exécution des migrations'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectDir = $this->getApplication()->getKernel()->getProjectDir();
        $io->info("Folder: $projectDir");

        // On va décider si on ignore ou non les migrations
        $ignoreMigrations = $input->getOption('ignore-migrations');

        // On supprime le répertoire migrations/* seulement si on n'ignore pas
        if (!$ignoreMigrations) {
            shell_exec('rm -rf migrations/*.php');
        }

        // Construire la liste des commandes
        $commands['Supprimer la base de données'] = ['php', 'bin/console', 'doctrine:database:drop', '--force'];
        $commands['Créer la base de données'] = ['php', 'bin/console', 'doctrine:database:create'];

        if (!$ignoreMigrations) {
            $commands['Supprimer les migrations'] = ['rm', '-rf', 'migrations/*'];
            $commands['Générer une nouvelle migration'] = ['php', 'bin/console', 'doctrine:migrations:diff'];
        }

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
