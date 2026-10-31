<?php

namespace Repas\Tests\User\Console;


use PHPUnit\Framework\TestCase;
use Repas\User\Infrastructure\Console\PurgeRefreshTokensCommand;
use Repas\Tests\Helper\MockCommandBus;
use Repas\User\Application\PurgeExpiredRefreshTokens\PurgeExpiredRefreshTokensCommand;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeRefreshTokensCommandTest extends TestCase
{
    public function testItDispatchesThePurgeAndReportsTheCount(): void
    {
        // Given
        $commandBus = new MockCommandBus()->willReturn(PurgeExpiredRefreshTokensCommand::class, 3);
        $tester = new CommandTester(new PurgeRefreshTokensCommand($commandBus));

        // When
        $status = $tester->execute([]);

        // Then
        $this->assertSame(0, $status);
        $this->assertEquals([new PurgeExpiredRefreshTokensCommand()], $commandBus->dispatched());
        $this->assertStringContainsString('3 refresh token(s) expiré(s) supprimé(s).', $tester->getDisplay());
    }
}
