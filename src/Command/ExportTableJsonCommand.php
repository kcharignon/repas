<?php

namespace Repas\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'repas:export-table:json',
    description: 'Exporte toutes les données d\'une table PostgreSQL en JSON.',
)]
class ExportTableJsonCommand extends Command
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::REQUIRED, 'Le nom exact de la table PostgreSQL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tableName = $input->getArgument('table');

        // Vérifier si la table existe
        $schemaCheckSql = "SELECT to_regclass(:table) IS NOT NULL AS exists";
        $tableExists = $this->connection->fetchOne($schemaCheckSql, ['table' => $tableName]);

        if (!$tableExists) {
            $output->writeln("<error>La table '$tableName' n'existe pas dans la base de données.</error>");
            return Command::FAILURE;
        }

        // Récupérer toutes les données de la table
        $sql = "SELECT * FROM $tableName";
        $data = $this->connection->fetchAllAssociative($sql);

        foreach ($data as $rowKey => $row) {
            foreach ($row as $valueKey => $value) {
                if (json_validate($value)) {
                    $data[$rowKey][$valueKey] = json_decode($value, true);
                }
            }
        }

        if (empty($data)) {
            $output->writeln("<comment>Aucune donnée trouvée dans la table '$tableName'.</comment>");
            return Command::SUCCESS;
        }

        // Convertir en JSON
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Création sécurisée du répertoire
        $filesystem = new Filesystem();
        $exportDir = 'var/export';

        if (!$filesystem->exists($exportDir)) {
            $filesystem->mkdir($exportDir, 0777);
        }

        $filePath = "$exportDir/$tableName.json";

        try {
            $filesystem->dumpFile($filePath, $jsonData);
            $output->writeln("<info>Données exportées avec succès dans '$filePath'</info>");
        } catch (\Exception $e) {
            $output->writeln("<error>Erreur lors de l'écriture du fichier : {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
