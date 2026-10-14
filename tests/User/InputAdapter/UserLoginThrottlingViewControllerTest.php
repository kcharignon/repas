<?php

namespace Repas\Tests\User\InputAdapter;


use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Le formulaire de connexion Twig a le même plafond que l'API (`login_throttling` du firewall `main`) : 5 échecs par
 * 15 minutes pour un couple IP + email, puis un message d'attente, même avec le bon mot de passe.
 */
class UserLoginThrottlingViewControllerTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
        static::getContainer()->get(UserRepository::class)->save(new UserBuilder()->withEmail('alexiane@example.com')->withPassword(password_hash('test', PASSWORD_BCRYPT, ['cost' => 4]))->build());
    }

    public function testTheSixthAttemptShowsAWaitMessageEvenWithTheRightPassword(): void
    {
        // When
        for ($i = 1; $i <= 5; $i++) {
            $this->submit('alexiane@example.com', 'mauvais');
            $this->assertTrue($this->client->getResponse()->isRedirect('/login'), "échec $i");
        }
        $this->submit('alexiane@example.com', 'test');
        $crawler = $this->client->followRedirect();

        // Then : renvoyé au formulaire, pas connecté, avec le message de patience
        $this->assertSame('/login', parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH));
        $this->assertStringContainsString('Trop de tentatives', $crawler->filter('body')->text());
    }

    public function testTheRightPasswordLogsInBeforeTheLimit(): void
    {
        // When
        for ($i = 1; $i <= 4; $i++) {
            $this->submit('alexiane@example.com', 'mauvais');
        }
        $this->submit('alexiane@example.com', 'test');

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/department'));
    }

    private function submit(string $email, string $password): void
    {
        $this->client->request('POST', '/login', ['email' => $email, 'password' => $password, '_csrf_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
