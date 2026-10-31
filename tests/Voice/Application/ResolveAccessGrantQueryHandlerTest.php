<?php

namespace Repas\Tests\Voice\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryLoader\AccessGrantInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\UserInMemoryLoader;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolveAccessGrantQuery;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolveAccessGrantQueryHandler;
use Repas\Voice\Application\Query\ResolveAccessGrant\ResolvedAccessGrantDTO;
use Repas\User\Application\OpenHostService\UserAccountQueryOHS;
use Repas\Voice\Infrastructure\Acl\ActiveUserQueryAdapter;
use Repas\Voice\Domain\Model\OpaqueToken;

class ResolveAccessGrantQueryHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-25 12:00:00');
    }

    public function testTheLoaderIsAskedWithTheHashAndTheDateNeverTheTokenAndItsAnswerIsReturned(): void
    {
        // Given
        $token = AlexaTestClient::token('access');
        $grant = new ResolvedAccessGrantDTO('user-1', 'link-1');
        $loader = new AccessGrantInMemoryLoader($grant);

        // When
        $result = $this->handler($loader, ['user-1'])(new ResolveAccessGrantQuery($token));

        // Then
        $this->assertSame($grant, $result);
        $this->assertSame([[OpaqueToken::hash($token), $this->now]], $loader->asked);
        $this->assertStringNotContainsString($token, json_encode($loader->asked, JSON_THROW_ON_ERROR));
    }

    public function testAnUnknownTokenResolvesToNothing(): void
    {
        $this->assertNull($this->handler(new AccessGrantInMemoryLoader(null), ['user-1'])(new ResolveAccessGrantQuery(AlexaTestClient::token('access'))));
    }

    public function testATokenThatIsNotOneOfOursResolvesToNothingWithoutAskingTheLoader(): void
    {
        foreach (['', 'short', 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.c2lnbmF0dXJl', str_repeat('a', 44)] as $token) {
            // Given
            $loader = new AccessGrantInMemoryLoader(new ResolvedAccessGrantDTO('user-1', 'link-1'));

            // When / Then
            $this->assertNull($this->handler($loader, ['user-1'])(new ResolveAccessGrantQuery($token)), $token);
            $this->assertSame([], $loader->asked, $token);
        }
    }

    public function testAnAccountThatIsNotActiveResolvesToNothing(): void
    {
        // Given : le jeton est valable, mais le compte est désactivé, en attente ou supprimé
        $loader = new AccessGrantInMemoryLoader(new ResolvedAccessGrantDTO('user-1', 'link-1'));

        // When / Then
        $this->assertNull($this->handler($loader, [])(new ResolveAccessGrantQuery(AlexaTestClient::token('access'))));
    }

    /**
     * @param list<string> $activeUserIds
     */
    private function handler(AccessGrantInMemoryLoader $loader, array $activeUserIds): ResolveAccessGrantQueryHandler
    {
        return new ResolveAccessGrantQueryHandler($loader, new ActiveUserQueryAdapter(new UserAccountQueryOHS(new UserInMemoryLoader(activeUserIds: $activeUserIds))), new FrozenClock($this->now));
    }
}
