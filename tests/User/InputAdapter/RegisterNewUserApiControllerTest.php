<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;

class RegisterNewUserApiControllerTest extends QueryBusWebTestCase
{
    public function testAnAnonymousVisitorRegistersWithHisAddressAloneAndGetsNoContent(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post(['email' => 'alexiane@example.com']);

        // Then : 204 sans corps ni Location, une seule commande, le nombre de personnes par défaut
        $response = $this->client->getResponse();
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertFalse($response->headers->has('Location'));
        $this->assertCount(1, $commandBus->dispatched());
        $command = $commandBus->dispatched()[0];
        $this->assertInstanceOf(RegisterNewUserCommand::class, $command);
        $this->assertSame(['alexiane@example.com', 4], [$command->email, $command->defaultServing]);
        $this->assertNotSame('', $command->userId);
    }

    public function testTheNumberOfPeopleIsKeptWhenItIsGiven(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post(['email' => 'alexiane@example.com', 'defaultServing' => 2]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame(2, $commandBus->dispatched()[0]->defaultServing);
    }

    /**
     * @param mixed $password ce que l'ancien formulaire (ou n'importe quoi d'autre) envoie dans `password`
     */
    #[DataProvider('passwordsSentByOldClients')]
    public function testAPasswordSentByAnOldClientIsAcceptedAndIgnoredWhateverItIs(mixed $password): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->post(['email' => 'alexiane@example.com', 'password' => $password, 'defaultServing' => 3]);

        // Then : même réponse qu'avec l'adresse seule ; il ne traverse pas le bus et n'est pas jugé
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertCount(1, $commandBus->dispatched());
        $command = $commandBus->dispatched()[0];
        $this->assertSame(['alexiane@example.com', 3], [$command->email, $command->defaultServing]);
        $this->assertStringNotContainsString(is_string($password) && $password !== '' ? $password : "\0absent", serialize($command));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function passwordsSentByOldClients(): iterable
    {
        yield 'un mot de passe valable' => ['un-mot-de-passe'];
        yield 'un mot de passe trop court' => ['court'];
        yield 'un mot de passe trop long' => [str_repeat('a', 5000)];
        yield 'vide' => [''];
        yield 'nul' => [null];
        yield 'un nombre' => [12345678];
        yield 'une liste' => [['x']];
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
        $valid = ['email' => 'alexiane@example.com', 'defaultServing' => 4];

        yield 'sans email' => [['email' => ''] + $valid];
        yield 'email invalide' => [['email' => 'pas-un-email'] + $valid];
        yield 'email trop long' => [['email' => str_repeat('a', 250) . '@example.com'] + $valid];
        yield 'aucune personne' => [['defaultServing' => 0] + $valid];
        yield 'trop de personnes' => [['defaultServing' => 101] + $valid];
        yield 'un nombre de personnes décimal' => [['defaultServing' => 2.5] + $valid];
        yield 'un nombre de personnes en texte' => [['defaultServing' => '2'] + $valid];
        yield 'un nombre de personnes booléen' => [['defaultServing' => true] + $valid];
        yield 'un nombre de personnes nul' => [['defaultServing' => null] + $valid];
        yield 'email de mauvais type' => [['email' => ['x']] + $valid];
    }

    public function testAMalformedAddressIsRefusedWhateverTheStateOfAnyAccount(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When : une adresse mal formée n'apprend rien sur un compte, elle reste refusée en 422
        $this->post(['email' => 'alexiane@']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
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
