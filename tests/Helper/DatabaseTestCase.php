<?php

namespace Repas\Tests\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Boot the Symfony kernel
        self::bootKernel();

        // Récupérer l'EntityManager
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Créer la base de données et le schéma si nécessaire
        $this->createDatabaseAndSchema();
    }

    protected function createDatabaseAndSchema(): void
    {
        $connection = $this->entityManager->getConnection();
        $dbName = $connection->getDatabase();

        // Vérifiez si la base de données existe déjà
        $dbExists = $connection->getSchemaManager()->databaseExists($dbName);

        // Si la base de données n'existe pas, créez-la
        if (!$dbExists) {
            $connection->executeStatement('CREATE DATABASE ' . $dbName);
        }

        // Créer le schéma à partir des métadonnées des entités
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        // Supprimez complètement le schéma après chaque test
        $this->dropDatabaseSchema();

        parent::tearDown();
    }

    protected function dropDatabaseSchema(): void
    {
        $connection = $this->entityManager->getConnection();

        // Utiliser DROP SCHEMA pour supprimer tout le schéma (tables, contraintes, etc.)
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public'); // Recrée le schéma après suppression
    }
}
