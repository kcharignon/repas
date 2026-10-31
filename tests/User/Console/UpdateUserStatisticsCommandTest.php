<?php

namespace Repas\Tests\User\Console;

use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\MockCommandBus;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Application\RecountUserStatistics\RecountedStatistics;
use Repas\User\Application\RecountUserStatistics\RecountUserStatisticsCommand;
use Repas\User\Infrastructure\Console\UpdateUserStatisticsCommand;
use Symfony\Component\Console\Tester\CommandTester;

class UpdateUserStatisticsCommandTest extends TestCase
{
    public function testEveryAccountIsRecountedAndTheResultIsShown(): void
    {
        // Given
        $users = new UserInMemoryRepository([
            new UserBuilder()->withId('user-id')->withEmail('user@example.com')->build(),
            new UserBuilder()->withId('other-id')->withEmail('other@example.com')->build(),
        ]);
        $bus = new MockCommandBus()->willAnswer(
            RecountUserStatisticsCommand::class,
            static fn(RecountUserStatisticsCommand $command): RecountedStatistics => new RecountedStatistics($command->userId === 'user-id' ? 2 : 1, 3),
        );
        $tester = new CommandTester(new UpdateUserStatisticsCommand($users, $bus));

        // When
        $status = $tester->execute([]);

        // Then
        $this->assertSame(0, $status);
        $this->assertEquals([new RecountUserStatisticsCommand('user-id'), new RecountUserStatisticsCommand('other-id')], $bus->dispatched());
        $output = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString('user@example.com : 2 ingredient(s) et 3 recipes', $output);
        $this->assertStringContainsString('other@example.com : 1 ingredient(s) et 3 recipes', $output);
        $this->assertStringContainsString('Les statistiques utilisateurs ont été initialisées avec succès.', $output);
    }
}
