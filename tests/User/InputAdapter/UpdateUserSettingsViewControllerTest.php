<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Application\Query\GetCurrentUser\GetCurrentUserQuery;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class UpdateUserSettingsViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsPrefilledFromTheCurrentUserDTO(): void
    {
        // Given
        $user = $this->savedUser();
        $this->queryBus->willReturn(GetCurrentUserQuery::class, new CurrentUserDTO($user->getId(), $user->getEmail(), ['ROLE_USER'], false, 6));
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('GET', '/user/' . $user->getId());

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(GetCurrentUserQuery::class, $query);
        $this->assertSame($user->getId(), $query->userId);
        $this->assertSame('6', $crawler->filter('input[name="user_setting[defaultServing]"]')->attr('value'));
    }

    public function testChangesTheServingWithoutAskingForThePassword(): void
    {
        // Given
        $user = $this->savedUser();
        $this->programCurrentUser($user);
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/user/' . $user->getId(), $this->payload(serving: '7'), server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(7, static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->getDefaultServing());
    }

    public function testChangesThePasswordWhenTheCurrentOneIsRight(): void
    {
        // Given : le mot de passe des utilisateurs de test est « test »
        $user = $this->savedUser();
        $this->programCurrentUser($user);
        $this->client->loginUser($user);

        // When
        $this->client->request('POST', '/user/' . $user->getId(), $this->payload(actual: 'test', new: 'nouveau', repeat: 'nouveau'), server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $saved = static::getContainer()->get(UserRepository::class)->findOneById($user->getId());
        $this->assertTrue($saved->passwordMatch('nouveau'));
    }

    public function testRefusesAWrongCurrentPassword(): void
    {
        // Given
        $user = $this->savedUser();
        $this->programCurrentUser($user);
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('POST', '/user/' . $user->getId(), $this->payload(actual: 'faux', new: 'nouveau', repeat: 'nouveau'), server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Mot de passe actuel invalide', $crawler->text());
        $this->assertTrue(static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->passwordMatch('test'));
    }

    public function testRefusesDifferentNewPasswords(): void
    {
        // Given
        $user = $this->savedUser();
        $this->programCurrentUser($user);
        $this->client->loginUser($user);

        // When
        $crawler = $this->client->request('POST', '/user/' . $user->getId(), $this->payload(actual: 'test', new: 'nouveau', repeat: 'autre'), server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertStringContainsString('Les mots de passe ne correspondent pas', $crawler->text());
        $this->assertTrue(static::getContainer()->get(UserRepository::class)->findOneById($user->getId())->passwordMatch('test'));
    }

    public function testRefusesTheSettingsOfAnotherUser(): void
    {
        // Given
        $other = $this->savedUser();
        $this->client->loginUser($this->savedUser());

        // When
        $this->client->request('GET', '/user/' . $other->getId());

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    private function programCurrentUser(User $user): void
    {
        $this->queryBus->willReturn(GetCurrentUserQuery::class, new CurrentUserDTO($user->getId(), $user->getEmail(), ['ROLE_USER'], false, $user->getDefaultServing()));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function payload(string $actual = '', string $new = '', string $repeat = '', string $serving = '4'): array
    {
        return ['user_setting' => [
            'actualPassword' => $actual,
            'newPassword' => $new,
            'newPasswordRepeat' => $repeat,
            'defaultServing' => $serving,
            '_token' => 'csrf-token',
        ]];
    }
}
