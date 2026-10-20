<?php

namespace Repas\Tests\User\InputAdapter;


use DateTimeImmutable;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\IssueRefreshToken\IssueRefreshTokenCommand;
use Repas\User\Application\RecordUserLogin\RecordUserLoginCommand;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

/**
 * `POST /api/login_check` et la dernière connexion du compte (ADR 0021, amendement du 2026-10-20), avec un bus de commandes
 * simulé : seule la connexion RÉUSSIE envoie la commande d'enregistrement, et un refus n'envoie rien. L'effet en base, avec les
 * vrais bus, est dans `LastLoginFlowTest`.
 */
class LoginRecordsLastLoginApiTest extends QueryBusWebTestCase
{
    private const string PASSWORD = 'test';

    public function testASuccessfulLoginRecordsTheLastLoginOfTheAccount(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->login($user->getEmail(), self::PASSWORD);

        // Then : la session est ouverte (cookie) et la connexion est enregistrée, pour le compte qui s'est connecté
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertEquals(
            [IssueRefreshTokenCommand::class, RecordUserLoginCommand::class],
            array_map(static fn(object $command): string => $command::class, $commandBus->dispatched()),
        );
        $this->assertEquals(new RecordUserLoginCommand($user->getId()), $commandBus->dispatched()[1]);
    }

    public function testALoginWithinTenMinutesOfTheLastOneRecordsNothing(): void
    {
        // Given : le compte s'est connecté il y a trois minutes
        $user = $this->savedUser();
        static::getContainer()->get(UserRepository::class)->recordLogin($user->getId(), new DateTimeImmutable('-3 minutes'));
        $this->entityManager->clear(); // la requête qui suit lit la base, pas l'entité déjà chargée par le test
        $commandBus = $this->mockCommandBus();

        // When
        $this->login($user->getEmail(), self::PASSWORD);

        // Then : connecté, mais l'enregistrement n'est même pas demandé au bus
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame([IssueRefreshTokenCommand::class], array_map(static fn(object $command): string => $command::class, $commandBus->dispatched()));
    }

    public function testAWrongPasswordRecordsNothing(): void
    {
        // Given
        $user = $this->savedUser();
        $commandBus = $this->mockCommandBus();

        // When
        $this->login($user->getEmail(), 'pas-le-mot-de-passe');

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnUnknownAccountRecordsNothing(): void
    {
        // Given
        $commandBus = $this->mockCommandBus();

        // When
        $this->login('personne@example.com', self::PASSWORD);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testADisabledAccountWithTheRightPasswordRecordsNothing(): void
    {
        // Given
        $user = $this->savedUserWith(new UserBuilder()->isDisabled());
        $commandBus = $this->mockCommandBus();

        // When
        $this->login($user->getEmail(), self::PASSWORD);

        // Then : refusé sans dire pourquoi (ADR 0008 §7), et rien n'est écrit
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $commandBus->dispatched());
    }

    public function testAnAccountWithAnUnconfirmedEmailRecordsNothing(): void
    {
        // Given
        $user = $this->savedUserWith(new UserBuilder()->isPending());
        $commandBus = $this->mockCommandBus();

        // When
        $this->login($user->getEmail(), self::PASSWORD);

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'EMAIL_NOT_VERIFIED']], $this->jsonBody());
        $this->assertSame([], $commandBus->dispatched());
    }

    private function savedUserWith(UserBuilder $builder): User
    {
        $user = $builder->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }
}
