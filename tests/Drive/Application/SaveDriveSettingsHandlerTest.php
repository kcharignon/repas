<?php

namespace Repas\Tests\Drive\Application;


use Repas\Drive\Domain\Exception\DriveFeatureException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\SaveDriveSettings\SaveDriveSettingsCommand;
use Repas\Drive\Application\Command\SaveDriveSettings\SaveDriveSettingsHandler;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;

class SaveDriveSettingsHandlerTest extends TestCase
{
    private const string URL = 'https://exemple.leclercdrive.fr/magasin-123456-123456';
    private const string OTHER_URL = 'https://autre.leclercdrive.fr/magasin-654321-654321';

    private DateTimeImmutable $now;
    private DriveSettingsInMemoryRepository $repository;
    private SaveDriveSettingsHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-18 12:00:00');
        $this->repository = new DriveSettingsInMemoryRepository();
        $this->handler = new SaveDriveSettingsHandler($this->repository, new FrozenClock($this->now), new ControlledDriveFeature());
    }

    public function testTheFirstSaveCreatesTheSettingsOfTheUser(): void
    {
        // When
        ($this->handler)(new SaveDriveSettingsCommand('user-1', Retailer::LECLERC, '123456', 'Ville-Test', self::URL));

        // Then
        $settings = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertNotNull($settings);
        $this->assertSame(['123456', 'Ville-Test', self::URL], [$settings->getStore()->id, $settings->getStore()->label, $settings->getStore()->baseUrl]);
        $this->assertEquals($this->now, $settings->getUpdatedAt());
    }

    public function testChangingTheStoreReplacesThePreviousOne(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // When
        ($this->handler)(new SaveDriveSettingsCommand('user-1', Retailer::LECLERC, '654321', null, self::OTHER_URL));

        // Then : un seul réglage, le nouveau magasin
        $this->assertCount(1, $this->repository->all());
        $settings = $this->repository->findOneByUser('user-1', Retailer::LECLERC);
        $this->assertSame(['654321', null, self::OTHER_URL], [$settings->getStore()->id, $settings->getStore()->label, $settings->getStore()->baseUrl]);
        $this->assertEquals($this->now, $settings->getUpdatedAt());
    }

    public function testTheSettingsOfAnotherUserAreLeftAlone(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-2')->build());

        // When
        ($this->handler)(new SaveDriveSettingsCommand('user-1', Retailer::LECLERC, '654321', null, self::OTHER_URL));

        // Then
        $this->assertCount(2, $this->repository->all());
        $this->assertSame('123456', $this->repository->findOneByUser('user-2', Retailer::LECLERC)->getStore()->id);
    }

    public function testAnInvalidStoreIsRefusedAndNothingIsSaved(): void
    {
        // Given
        $this->repository->save(new DriveSettingsBuilder()->withUserId('user-1')->build());

        // Then
        $this->expectExceptionObject(DriveException::storeInvalid());

        // When : l'identifiant n'est pas celui de l'adresse
        try {
            ($this->handler)(new SaveDriveSettingsCommand('user-1', Retailer::LECLERC, '999999', null, self::OTHER_URL));
        } finally {
            $this->assertSame('123456', $this->repository->findOneByUser('user-1', Retailer::LECLERC)->getStore()->id);
        }
    }

    public function testAnAddressOfAnotherHostIsRefused(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::storeInvalid());

        // When
        ($this->handler)(new SaveDriveSettingsCommand('user-1', Retailer::LECLERC, '123456', null, 'https://exemple.example.com/magasin-123456-123456'));
    }

    public function testADisabledRetailerSavesNothing(): void
    {
        // Given : l'enseigne n'est pas allumée (DRV-702)
        $handler = new SaveDriveSettingsHandler($this->repository, new FrozenClock($this->now), new ControlledDriveFeature(true, []));

        // Then
        $this->expectExceptionObject(DriveFeatureException::retailerDisabled());

        // When
        try {
            $handler(new SaveDriveSettingsCommand('user-1', Retailer::LECLERC, '123456', 'Ville-Test', self::URL));
        } finally {
            $this->assertSame([], $this->repository->all());
        }
    }
}
