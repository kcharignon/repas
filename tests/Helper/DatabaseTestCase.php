<?php

namespace Repas\Tests\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

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

    private function resetDatabaseSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);

        // Drop the existing schema
        $schemaTool->dropSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata()
        );

        // Create the schema again
        $schemaTool->createSchema(
            $this->entityManager->getMetadataFactory()->getAllMetadata()
        );
    }
}
