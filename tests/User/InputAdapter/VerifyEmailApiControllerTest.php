<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\VerifyEmail\VerifyEmailCommand;
use Repas\User\Domain\Model\EmailVerificationToken;

class VerifyEmailApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAnonymousVisitorConfirmsHisEmailAndOnlyTheHashReachesTheBus(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When : route publique, aucun jeton
        $this->post(['token' => 'the-secret']);

        // Then : le secret ne traverse pas le bus, seul son hachage (ADR 0015 §1)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new VerifyEmailCommand(EmailVerificationToken::hash('the-secret'))], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRefusesAnInvalidBody(array $body): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post($body);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'sans jeton' => [['token' => '']];
        yield 'jeton trop long' => [['token' => str_repeat('a', 257)]];
        yield 'jeton de mauvais type' => [['token' => ['x']]];
        yield 'corps vide' => [[]];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): void
    {
        $this->client->request('POST', '/api/v1/users/email-verification/confirm', content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }
}
