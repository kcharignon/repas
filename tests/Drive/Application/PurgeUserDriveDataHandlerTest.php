<?php

namespace Repas\Tests\Drive\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\PurgeUserDriveData\PurgeUserDriveDataCommand;
use Repas\Drive\Application\Command\PurgeUserDriveData\PurgeUserDriveDataHandler;
use Repas\Drive\Domain\Model\RayonVote;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerPreference;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\InMemoryRepository\ChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RayonVoteInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RetailerPreferenceInMemoryRepository;

class PurgeUserDriveDataHandlerTest extends TestCase
{
    public function testTheSettingsChoicesRayonVotesAndRetailerPreferenceOfTheDeletedUserAreErasedAndTheOthersKept(): void
    {
        // Given
        $now = new DateTimeImmutable('2026-10-30 10:00:00');
        $settings = new DriveSettingsInMemoryRepository([
            new DriveSettingsBuilder()->withUserId('departing')->build(),
            new DriveSettingsBuilder()->withUserId('staying')->build(),
        ]);
        $choices = new ChoiceInMemoryRepository([
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('farine')->build(),
            new ChoiceBuilder()->withUserId('departing')->withIngredientSlug('sel')->build(),
            new ChoiceBuilder()->withUserId('staying')->build(),
        ]);
        $rayonVotes = new RayonVoteInMemoryRepository();
        $rayonVotes->record('departing', Retailer::LECLERC, [new RayonVote('boulangerie', 4001), new RayonVote('epicerie', 4002)]);
        $rayonVotes->record('staying', Retailer::LECLERC, [new RayonVote('boulangerie', 4001)]);
        $preferences = new RetailerPreferenceInMemoryRepository([
            RetailerPreference::create('departing', $now)->chooseDefault(Retailer::LECLERC, $now),
            RetailerPreference::create('staying', $now)->rememberLastUsed(Retailer::LECLERC, $now),
        ]);

        // When
        new PurgeUserDriveDataHandler($settings, $choices, $rayonVotes, $preferences)(new PurgeUserDriveDataCommand('departing'));

        // Then
        $this->assertNull($settings->findOneByUser('departing', Retailer::LECLERC));
        $this->assertNotNull($settings->findOneByUser('staying', Retailer::LECLERC));
        $this->assertSame(['staying'], array_map(static fn($choice) => $choice->getUserId(), $choices->all()));
        $this->assertSame(['departing'], $choices->productsDeletedForUsers);
        $this->assertSame(['staying|leclerc|boulangerie|4001'], $rayonVotes->all());
        $this->assertNull($preferences->findOneByUser('departing'));
        $this->assertNotNull($preferences->findOneByUser('staying'));
    }

    public function testAUserWithoutDriveDataIsNotAnError(): void
    {
        // Given
        $settings = new DriveSettingsInMemoryRepository();
        $choices = new ChoiceInMemoryRepository();
        $rayonVotes = new RayonVoteInMemoryRepository();
        $preferences = new RetailerPreferenceInMemoryRepository();

        // When
        new PurgeUserDriveDataHandler($settings, $choices, $rayonVotes, $preferences)(new PurgeUserDriveDataCommand('nobody'));

        // Then
        $this->assertSame([], $settings->all());
        $this->assertSame([], $choices->all());
        $this->assertSame([], $rayonVotes->all());
        $this->assertSame([], $preferences->all());
    }
}
