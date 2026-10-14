<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;

class RegisterNewUserApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAnonymousVisitorRegistersAndGetsTheLocation(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post(['email' => 'alexiane@example.com', 'password' => 'un-mot-de-passe', 'defaultServing' => 4]);

        // Then
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $commandBus->dispatched());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(RegisterNewUserCommand::class, $command);
        $this->assertSame(['alexiane@example.com', 'un-mot-de-passe', 4], [$command->email, $command->passwordPlainText, $command->defaultServing]);
        $this->assertSame('/api/v1/users/' . $command->userId, $this->client->getResponse()->headers->get('Location'));
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
        $valid = ['email' => 'alexiane@example.com', 'password' => 'un-mot-de-passe', 'defaultServing' => 4];

        yield 'sans email' => [['email' => ''] + $valid];
        yield 'email invalide' => [['email' => 'pas-un-email'] + $valid];
        yield 'email trop long' => [['email' => str_repeat('a', 250) . '@example.com'] + $valid];
        yield 'mot de passe trop court' => [['password' => 'court'] + $valid];
        yield 'mot de passe trop long' => [['password' => str_repeat('a', 4097)] + $valid];
        yield 'aucune personne' => [['defaultServing' => 0] + $valid];
        yield 'trop de personnes' => [['defaultServing' => 101] + $valid];
        yield 'email de mauvais type' => [['email' => ['x']] + $valid];
    }

    public function testTheOtherUserRoutesStayClosedToAnAnonymousVisitor(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('GET', '/api/v1/users');

        // Then : seul `POST /api/v1/users` est public
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): void
    {
        $this->client->request('POST', '/api/v1/users', content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }
}
