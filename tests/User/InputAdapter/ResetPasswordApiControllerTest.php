<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\ResetPassword\ResetPasswordCommand;
use Repas\User\Domain\Model\PasswordResetToken;

class ResetPasswordApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAnonymousVisitorChoosesANewPasswordAndOnlyTheHashOfTheLinkReachesTheBus(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When : route publique, aucun jeton
        $this->post(['token' => 'the-secret', 'password' => 'un-mot-de-passe']);

        // Then : le secret du lien ne traverse pas le bus, seul son hachage (ADR 0015 §1)
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new ResetPasswordCommand(PasswordResetToken::hash('the-secret'), 'un-mot-de-passe')], $commandBus->dispatched());
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
        yield 'sans jeton' => [['token' => '', 'password' => 'un-mot-de-passe']];
        yield 'jeton trop long' => [['token' => str_repeat('a', 257), 'password' => 'un-mot-de-passe']];
        yield 'jeton de mauvais type' => [['token' => ['x'], 'password' => 'un-mot-de-passe']];
        yield 'sans mot de passe' => [['token' => 'the-secret', 'password' => '']];
        yield 'mot de passe de 7 caractères' => [['token' => 'the-secret', 'password' => '1234567']];
        yield 'mot de passe trop long' => [['token' => 'the-secret', 'password' => str_repeat('a', 4097)]];
        yield 'mot de passe de mauvais type' => [['token' => 'the-secret', 'password' => ['x']]];
        yield 'jeton seul' => [['token' => 'the-secret']];
        yield 'corps vide' => [[]];
    }

    public function testAPasswordOfEightCharactersIsAccepted(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post(['token' => 'the-secret', 'password' => '12345678']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
    }

    public function testOnlyPostIsAllowed(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('GET', '/api/v1/users/password-reset/confirm');

        // Then : la route publique est limitée à sa méthode
        $this->assertContains($this->client->getResponse()->getStatusCode(), [401, 405]);
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): void
    {
        $this->client->request('POST', '/api/v1/users/password-reset/confirm', content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }
}
