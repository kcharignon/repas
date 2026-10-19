<?php

namespace Repas\Tests\User\InputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\Tests\Helper\RateLimiterTimeTravel;
use Repas\User\Application\UpdateUserAppVersion\UpdateUserAppVersionCommand;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\User;

/**
 * `PUT /api/v1/users/me/app-version` (DRV-637) avec un double du bus de commandes. Le limiteur `app_version` (une écriture par
 * minute et par utilisateur) est le vrai, en mémoire (`test.rate_limiter_pool`) ; le noyau est gardé d'une requête à l'autre
 * quand un test en fait plusieurs (`disableReboot`).
 */
class UpdateUserAppVersionApiControllerTest extends QueryBusWebTestCase
{
    private const string PATH = '/api/v1/users/me/app-version';

    public function testTheConnectedUserAnnouncesHisAppVersion(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertEquals([new UpdateUserAppVersionCommand($user->getId(), AppPlatform::ANDROID, '0.1.8')], $commandBus->dispatched());
    }

    public function testTheUserComesFromTheTokenNeverFromTheBody(): void
    {
        // Given
        $user = $this->savedUser();
        $other = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8', 'userId' => $other->getId(), 'id' => $other->getId()]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUserAppVersionCommand($user->getId(), AppPlatform::ANDROID, '0.1.8')], $commandBus->dispatched());
    }

    public function testNothingButTheVersionIsRead(): void
    {
        // Given : une application qui enverrait plus que demandé
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8', 'deviceId' => 'appareil-test', 'model' => 'Telephone-Test']);

        // Then : ni identifiant d'appareil ni modèle de téléphone n'arrivent jusqu'à la commande
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUserAppVersionCommand($user->getId(), AppPlatform::ANDROID, '0.1.8')], $commandBus->dispatched());
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
        $this->put($this->savedUser(), $body);

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
        yield 'version à quatre nombres' => [['platform' => 'android', 'version' => '0.1.8.1']];
        yield 'version avec suffixe' => [['platform' => 'android', 'version' => '0.1.8-dev']];
        yield 'version de debug' => [['platform' => 'android', 'version' => '0.0.0-dev']];
        yield 'version à deux nombres' => [['platform' => 'android', 'version' => '0.1']];
        yield 'version avec un espace' => [['platform' => 'android', 'version' => '0.1.8 ']];
        yield 'version avec un saut de ligne' => [['platform' => 'android', 'version' => "0.1.8\n"]];
        yield 'version de 21 caractères' => [['platform' => 'android', 'version' => '1.1.12345678901234567']];
        yield 'version vide' => [['platform' => 'android', 'version' => '']];
        yield 'version absente' => [['platform' => 'android']];
        yield 'version nombre' => [['platform' => 'android', 'version' => 8]];
        yield 'plateforme inconnue' => [['platform' => 'ios', 'version' => '0.1.8']];
        yield 'plateforme en majuscules' => [['platform' => 'ANDROID', 'version' => '0.1.8']];
        yield 'plateforme vide' => [['platform' => '', 'version' => '0.1.8']];
        yield 'plateforme absente' => [['version' => '0.1.8']];
        yield 'corps vide' => [[]];
    }

    public function testRefusesAnUnauthenticatedRequest(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', self::PATH, content: '{"platform": "android", "version": "0.1.8"}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesTheTokenOfADisabledAccount(): void
    {
        // Given : un jeton encore valable, mais le compte a été désactivé (ADR 0021 §4)
        $user = new UserBuilder()->isDisabled()->build();
        static::getContainer()->get(UserRepository::class)->save($user);
        $commandBus = $this->mockCommandBus();

        // When
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testRefusesTheTokenOfADeletedAccount(): void
    {
        // Given : un jeton émis avant la suppression du compte
        $user = $this->savedUser();
        $bearer = $this->bearerFor($user);
        static::getContainer()->get(UserRepository::class)->delete($user);
        $commandBus = $this->mockCommandBus();

        // When
        $this->client->request('PUT', self::PATH, content: '{"platform": "android", "version": "0.1.8"}', server: $bearer + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testASecondCallInTheMinuteAnswers204ButWritesNothing(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();
        $this->client->disableReboot();

        // When : deux démarrages de l'application dans la minute
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8']);
        $first = $this->client->getResponse()->getStatusCode();
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8']);

        // Then : aucune erreur visible, une seule commande
        $this->assertSame(204, $first);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertFalse($this->client->getResponse()->headers->has('Retry-After'));
        $this->assertCount(1, $commandBus->dispatched());
    }

    public function testTheNextMinuteWritesAgain(): void
    {
        // Given : une écriture il y a un peu plus d'une minute
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();
        $this->client->disableReboot();
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8']);
        new RateLimiterTimeTravel(static::getContainer()->get('test.rate_limiter_pool'))->advance('app_version', 'user-' . $user->getId(), 61);

        // When : l'application a été mise à jour
        $this->put($user, ['platform' => 'android', 'version' => '0.1.10']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([
            new UpdateUserAppVersionCommand($user->getId(), AppPlatform::ANDROID, '0.1.8'),
            new UpdateUserAppVersionCommand($user->getId(), AppPlatform::ANDROID, '0.1.10'),
        ], $commandBus->dispatched());
    }

    public function testTheLimitIsPerUser(): void
    {
        // Given : un premier compte vient d'écrire
        $first = $this->savedUser();
        $second = $this->savedUser();
        $commandBus = $this->mockCommandBus();
        $this->client->disableReboot();
        $this->put($first, ['platform' => 'android', 'version' => '0.1.8']);

        // When : un autre compte, depuis la même adresse (la famille partage la box)
        $this->put($second, ['platform' => 'android', 'version' => '0.1.8']);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([
            new UpdateUserAppVersionCommand($first->getId(), AppPlatform::ANDROID, '0.1.8'),
            new UpdateUserAppVersionCommand($second->getId(), AppPlatform::ANDROID, '0.1.8'),
        ], $commandBus->dispatched());
    }

    public function testAnInvalidBodyDoesNotUseTheMinute(): void
    {
        // Given : un premier appel au corps invalide
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();
        $this->client->disableReboot();
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8-dev']);
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // When
        $this->put($user, ['platform' => 'android', 'version' => '0.1.8']);

        // Then : l'appel valide qui suit est écrit
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertEquals([new UpdateUserAppVersionCommand($user->getId(), AppPlatform::ANDROID, '0.1.8')], $commandBus->dispatched());
    }

    public function testRefusedRequestsConsumeNothing(): void
    {
        // Given : un appel sans jeton
        $this->mockCommandBus();
        $this->client->disableReboot();

        // When
        $this->client->request('PUT', self::PATH, content: '{"platform": "android", "version": "0.1.8"}', server: ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], static::getContainer()->get('test.rate_limiter_pool')->getValues());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(User $user, array $body): void
    {
        $this->client->request('PUT', self::PATH, content: json_encode($body, JSON_THROW_ON_ERROR), server: $this->bearerFor($user) + ['CONTENT_TYPE' => 'application/json']);
    }
}
