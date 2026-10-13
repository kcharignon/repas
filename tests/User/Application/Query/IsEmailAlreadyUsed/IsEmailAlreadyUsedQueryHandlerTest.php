<?php

namespace Repas\Tests\User\Application\Query\IsEmailAlreadyUsed;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\InMemoryLoader\UserInMemoryLoader;
use Repas\User\Application\Query\IsEmailAlreadyUsed\IsEmailAlreadyUsedQuery;
use Repas\User\Application\Query\IsEmailAlreadyUsed\IsEmailAlreadyUsedQueryHandler;

class IsEmailAlreadyUsedQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturns(): void
    {
        // Given
        $handler = new IsEmailAlreadyUsedQueryHandler(new UserInMemoryLoader(emailAlreadyUsed: true));

        // When
        $result = $handler(new IsEmailAlreadyUsedQuery('a@example.com'));

        // Then
        $this->assertTrue($result);
    }

    public function testForwardsTheEmailToTheLoader(): void
    {
        // Given
        $loader = new UserInMemoryLoader();
        $handler = new IsEmailAlreadyUsedQueryHandler($loader);

        // When
        $result = $handler(new IsEmailAlreadyUsedQuery('a@example.com'));

        // Then
        $this->assertFalse($result);
        $this->assertSame(['a@example.com'], $loader->emailExistsCalls);
    }
}
