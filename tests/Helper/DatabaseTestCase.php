<?php

namespace Repas\Tests\Helper;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Boot the Symfony kernel
        self::bootKernel();

        // Get the EntityManager
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Reset the database schema
        $this->resetDatabaseSchema();
    }

    /**
     * @throws Exception
     */
    private function resetDatabaseSchema(): void
    {
        $connection = $this->entityManager->getConnection();
        $schemaTool = new SchemaTool($this->entityManager);

        // Drop the database (this will remove all tables)
        $connection->executeStatement('DROP SCHEMA public CASCADE;');
        $connection->executeStatement('CREATE SCHEMA public;');

        // Create the schema again
        $schemaTool->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata()
        );
    }
}
