<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\QueryBusWebTestCase;
use Repas\User\Application\Query\IsEmailAlreadyUsed\IsEmailAlreadyUsedQuery;
use Repas\User\Domain\Interface\UserRepository;

class RegistrationViewControllerTest extends QueryBusWebTestCase
{
    public function testFormIsDisplayed(): void
    {
        // When
        $crawler = $this->client->request('GET', '/registration');

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(1, $crawler->filter('input[name="register_new_user[email]"]'));
        $this->assertSame(0, $this->queryBus->countAskedQueries());
    }

    public function testValidSubmissionCreatesTheUserAndLogsHimIn(): void
    {
        // Given
        $this->queryBus->willReturn(IsEmailAlreadyUsedQuery::class, false);

        // When
        $this->client->request('POST', '/registration', $this->payload('nouveau@example.com'), server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect());
        $query = $this->queryBus->getLastAskedQuery();
        $this->assertInstanceOf(IsEmailAlreadyUsedQuery::class, $query);
        $this->assertSame('nouveau@example.com', $query->email);
        $created = static::getContainer()->get(UserRepository::class)->findOneByEmail('nouveau@example.com');
        $this->assertSame(3, $created->getDefaultServing());
        $this->client->followRedirect();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('/user/' . $created->getId(), $this->client->getResponse()->getContent());
    }

    public function testRefusesAnEmailAlreadyUsed(): void
    {
        // Given
        $this->queryBus->willReturn(IsEmailAlreadyUsedQuery::class, true);

        // When
        $crawler = $this->client->request('POST', '/registration', $this->payload('pris@example.com'), server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Cette adresse email est déjà utilisée.', $crawler->text());
    }

    public function testRefusesDifferentPasswords(): void
    {
        // Given
        $this->queryBus->willReturn(IsEmailAlreadyUsedQuery::class, false);

        // When
        $crawler = $this->client->request('POST', '/registration', $this->payload('nouveau@example.com', repeat: 'autre'), server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Les mots de passe ne correspondent pas.', $crawler->text());
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function payload(string $email, string $repeat = 'mot-de-passe'): array
    {
        return ['register_new_user' => [
            'email' => $email,
            'plainPassword' => 'mot-de-passe',
            'plainPasswordRepeat' => $repeat,
            'defaultServing' => '3',
            '_token' => 'csrf-token',
        ]];
    }
}
