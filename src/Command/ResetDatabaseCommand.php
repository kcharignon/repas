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
    protected static string $defaultName = 'repas:reset-database';
    protected static string $defaultDescription = 'Réinitialise complètement la base de données, recrée les migrations et recharge les fixtures';

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
            'Supprimer la base de données' => ['tool' => 'process', 'cmd' => ['php', 'bin/console', 'doctrine:database:drop', '--force']],
            'Créer la base de données' => ['tool' => 'process', 'cmd' => ['php', 'bin/console', 'doctrine:database:create']],
            'Supprimer les migrations' => ['tool' => 'process', 'cmd' => ['rm', '-rf', 'migrations/*']],
            'Générer une nouvelle migration' => ['tool' => 'process', 'cmd' => ['php', 'bin/console', 'doctrine:migrations:diff']],
            'Appliquer les migrations' => ['tool' => 'process', 'cmd' => ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']],
            'Charger les fixtures' => ['tool' => 'process', 'cmd' => ['php', 'bin/console', 'doctrine:fixtures:load', '--no-interaction']],
        ];

        foreach ($commands as $description => $command) {
            $io->section($description);
            if ($command['tool'] == 'process') {
                $command = $command['cmd'];
                $process = new Process($command);
                $process->setTimeout(3600); // Timeout de 1 heure

                try {
                    $io->comment($process->getCommandLine());
                    $process->mustRun();
                    $io->success($description . ' : Succès');
                } catch (ProcessFailedException $exception) {
                    $io->error($description . ' : Échec');
                    $io->error($exception->getMessage());
                    return Command::FAILURE;
                }
            } else {
                $command = $command['cmd'];
                try {
                    $io->comment($process->getCommandLine());
                    shell_exec(implode(' ', $command));
                    $io->success($description . ' : Succès');
                } catch (ProcessFailedException $exception) {
                    $io->error($description . ' : Échec');
                    $io->error($exception->getMessage());
                    return Command::FAILURE;
                }
            }
        }

        $io->success('La base de données a été réinitialisée avec succès !');
        return Command::SUCCESS;
    }
}
