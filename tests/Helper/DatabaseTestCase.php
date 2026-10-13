<?php

namespace Repas\Tests\Helper;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Repas\Shared\Infrastructure\Repository\ModelCache;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Socle des tests qui touchent la vraie base (ADR 0010).
 *
 * Le schéma est créé UNE SEULE FOIS par exécution de PHPUnit (premier test qui en a besoin), vide : aucune fixture.
 * Chaque test tourne ensuite dans une transaction annulée à la fin (DAMA doctrine-test-bundle, extension déclarée dans
 * phpunit.dist.xml), sur une connexion statique qui survit aux reboots du kernel (KernelBrowser).
 *
 * Un test crée ses propres données (builders + repositories, voir `DataPersister`) et n'asserte que sur elles. Les
 * `DataFixture` ne servent qu'au jeu de données de développement (`make fixtures-load-dev`).
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    private static bool $databasePrepared = false;

    protected ?EntityManagerInterface $entityManager;
    protected ModelCache $modelCacheMock;
    protected array $backupServices = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Hors transaction de test : DAMA n'ouvre la transaction qu'au début de chaque test.
        if (!self::$databasePrepared) {
            self::prepareDatabase();
            self::$databasePrepared = true;
        }
    }

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
    }

    /**
     * DAMA ouvre une transaction dès la première connexion statique : la préparation se fait donc avec le mode
     * statique coupé, sur une connexion normale dont les écritures sont réellement commitées, puis on le rétablit.
     */
    private static function prepareDatabase(): void
    {
        $keepStaticConnections = StaticDriver::isKeepStaticConnections();
        StaticDriver::setKeepStaticConnections(false);

        try {
            self::createSchema();
        } finally {
            StaticDriver::setKeepStaticConnections($keepStaticConnections);
        }
    }

    private static function createSchema(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $entityManager->clear();
        self::ensureKernelShutdown();
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
