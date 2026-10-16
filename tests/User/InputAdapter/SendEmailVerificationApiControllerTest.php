<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\SendEmailVerification\SendEmailVerificationCommand;

class SendEmailVerificationApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAnonymousVisitorAsksForANewLink(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When : route publique, aucun jeton
        $this->post(['email' => 'alexiane@example.com']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new SendEmailVerificationCommand('alexiane@example.com')], $commandBus->dispatched());
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
        yield 'sans email' => [['email' => '']];
        yield 'email invalide' => [['email' => 'pas-un-email']];
        yield 'email trop long' => [['email' => str_repeat('a', 250) . '@example.com']];
        yield 'email de mauvais type' => [['email' => ['x']]];
        yield 'corps vide' => [[]];
    }

    public function testOnlyPostIsAllowed(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('GET', '/api/v1/users/email-verification/send');

        // Then : la route publique est limitée à sa méthode
        $this->assertContains($this->client->getResponse()->getStatusCode(), [401, 405]);
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): void
    {
        $this->client->request('POST', '/api/v1/users/email-verification/send', content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }
}
