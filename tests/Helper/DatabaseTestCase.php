<?php

namespace Repas\Tests\Helper;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected ?EntityManagerInterface $entityManager;
    protected ModelCache $modelCacheMock;
    protected array $backupServices = [];

    protected function mockService(string $id, ?object $mock): void
    {
        $this->backupServices[$id] = static::getContainer()->get($id);
        static::getContainer()->set($id, $mock);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Boot the Symfony kernel
        self::bootKernel();

        // Get the EntityManager
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->modelCacheMock = $this->createMock(ModelCache::class);
        // Remplacer le service dans le conteneur
        self::getContainer()->set(ModelCache::class, $this->modelCacheMock);
//        $modelCache = self::getContainer()->get(ModelCache::class);
//        $modelCache->reset();

        // Reset the database schema
        $this->resetDatabaseSchema();

        $this->loadFixtures();
    }

    private function resetDataBaseSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function loadFixtures(): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'doctrine:fixtures:load',
            '--env' => 'test',
            '--purge-with-truncate' => true,
            '--no-interaction' => true,
        ]);

        $output = new NullOutput();
        $application->run($input, $output);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
        $this->entityManager = null;

        // On restore les services
        foreach ($this->backupServices as $id => $service) {
            static::getContainer()->set($id, $service);
        }
    }
}
