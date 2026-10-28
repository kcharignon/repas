<?php

namespace Repas\Tests\User\InputAdapter;


use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

/**
 * Parcours des endpoints d'écriture de l'utilisateur avec les vrais bus et la vraie base : inscription publique par la seule adresse,
 * choix du mot de passe par le lien reçu, connexion par /api/login_check, même adresse deux fois, réglages et changement de mot de passe.
 */
class UserWriteApiFlowTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
    }

    public function testAVisitorRegistersWithHisAddressChoosesAPasswordLogsInAndReadsHisAccount(): void
    {
        // Given : le même noyau d'une requête à l'autre, pour lire le journal du mailer
        $this->client->disableReboot();

        // When : inscription sans jeton, avec la seule adresse
        $this->request('POST', '/api/v1/users', body: ['email' => 'visiteur@example.com', 'defaultServing' => 3]);

        // Then : 204 sans Location (la réponse est la même pour toute adresse), le compte ne peut pas encore se connecter
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($this->client->getResponse()->headers->has('Location'));
        $secret = $this->welcomeSecret();
        $this->login('visiteur@example.com', 'un-mot-de-passe');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());

        // When : il choisit son mot de passe avec le lien reçu (l'adresse est prouvée, le compte activé), puis se connecte
        $this->request('POST', '/api/v1/users/password-reset/confirm', body: ['token' => $secret, 'password' => 'un-mot-de-passe']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $token = $this->tokenFor('visiteur@example.com', 'un-mot-de-passe');
        $this->request('GET', '/api/v1/me', $token);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(
            ['email' => 'visiteur@example.com', 'roles' => ['ROLE_USER'], 'isAdmin' => false, 'defaultServing' => 3, 'appUpdate' => ['latest' => null, 'minimum' => null, 'outdated' => false]],
            array_diff_key($this->jsonBody(), ['id' => true]),
        );
        $id = $this->jsonBody()['id'];
        $this->request('GET', '/api/v1/users/' . $id, $token);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheEmailIsKeptInLowercaseAndLoginIgnoresTheCase(): void
    {
        // Given
        $this->client->disableReboot();

        // When : inscription avec des majuscules, puis choix du mot de passe
        $this->request('POST', '/api/v1/users', body: ['email' => 'Visiteur@Example.COM']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('POST', '/api/v1/users/password-reset/confirm', body: ['token' => $this->welcomeSecret(), 'password' => 'un-mot-de-passe']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : connexion avec n'importe quelle casse, l'email est stocké en minuscules, le nombre de personnes est celui par défaut
        foreach (['visiteur@example.com', 'VISITEUR@EXAMPLE.COM', 'Visiteur@Example.COM'] as $email) {
            $this->request('GET', '/api/v1/me', $this->tokenFor($email, 'un-mot-de-passe'));
            $this->assertSame(200, $this->client->getResponse()->getStatusCode(), $email);
            $this->assertSame(['visiteur@example.com', User::DEFAULT_SERVING], [$this->jsonBody()['email'], $this->jsonBody()['defaultServing']]);
        }

        // Then : le même email avec une autre casse est le même compte, la réponse est la même qu'une adresse nouvelle
        $this->request('POST', '/api/v1/users', body: ['email' => 'VISITEUR@example.com', 'defaultServing' => 2]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertSame(1, (int) static::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne('SELECT COUNT(*) FROM "user"'));
    }

    public function testRegisteringTwiceWithTheSameEmailKeepsTheFirstAccount(): void
    {
        // Given
        $this->client->disableReboot();
        $this->request('POST', '/api/v1/users', body: ['email' => 'visiteur@example.com', 'defaultServing' => 3]);
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $id = $connection->fetchOne('SELECT id FROM "user"');

        // When : une seconde inscription, avec un autre nombre de personnes et un mot de passe de l'ancien formulaire
        $this->request('POST', '/api/v1/users', body: ['email' => 'visiteur@example.com', 'password' => 'autre-mot-de-passe', 'defaultServing' => 9]);

        // Then : même réponse, le premier compte est intact et sans mot de passe
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('visiteur@example.com');
        $this->assertSame([(string) $id, 3, false], [$user->getId(), $user->getDefaultServing(), $user->hasPassword()]);
        $this->assertFalse($user->passwordMatch('autre-mot-de-passe'));
    }

    public function testTheServingChangesWithoutTheCurrentPasswordAndThePasswordStaysTheSame(): void
    {
        // Given
        $this->savedUser('visiteur@example.com');
        $token = $this->tokenFor('visiteur@example.com', 'test');

        // When
        $this->request('PUT', '/api/v1/me/settings', $token, ['defaultServing' => 7]);

        // Then
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/api/v1/me', $token);
        $this->assertSame(7, $this->jsonBody()['defaultServing']);
        $this->login('visiteur@example.com', 'test');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testThePasswordChangesWithTheCurrentOne(): void
    {
        // Given
        $this->savedUser('visiteur@example.com');
        $token = $this->tokenFor('visiteur@example.com', 'test');

        // When
        $this->request('PUT', '/api/v1/me/settings', $token, ['defaultServing' => 4, 'newPassword' => 'un-nouveau-mot-de-passe', 'actualPassword' => 'test']);

        // Then : l'ancien mot de passe ne connecte plus, le nouveau oui
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->login('visiteur@example.com', 'test');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->login('visiteur@example.com', 'un-nouveau-mot-de-passe');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('wrongCurrentPasswords')]
    public function testAWrongOrMissingCurrentPasswordChangesNothing(array $body): void
    {
        // Given
        $this->savedUser('visiteur@example.com');
        $token = $this->tokenFor('visiteur@example.com', 'test');

        // When
        $this->request('PUT', '/api/v1/me/settings', $token, ['defaultServing' => 9, 'newPassword' => 'un-nouveau-mot-de-passe'] + $body);

        // Then : ni le mot de passe ni le nombre de personnes ne bougent
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('error', $this->jsonBody());
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('visiteur@example.com');
        $this->assertSame(4, $user->getDefaultServing());
        $this->assertTrue($user->passwordMatch('test'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function wrongCurrentPasswords(): iterable
    {
        yield 'mot de passe actuel absent' => [[]];
        yield 'mot de passe actuel erroné' => [['actualPassword' => 'pas-le-bon']];
    }

    public function testChangingThePasswordRevokesTheTokensAlreadyIssued(): void
    {
        // Given : deux jetons émis avant le changement (deux appareils)
        $this->savedUser('visiteur@example.com');
        $firstDevice = $this->tokenFor('visiteur@example.com', 'test');
        $secondDevice = $this->tokenFor('visiteur@example.com', 'test');

        // When : un appareil change le mot de passe
        $this->request('PUT', '/api/v1/me/settings', $firstDevice, ['defaultServing' => 4, 'newPassword' => 'un-nouveau-mot-de-passe', 'actualPassword' => 'test']);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Then : les deux anciens jetons sont refusés, un jeton émis avec le nouveau mot de passe est accepté
        foreach ([$firstDevice, $secondDevice] as $token) {
            $this->request('GET', '/api/v1/me', $token);
            $this->assertSame(401, $this->client->getResponse()->getStatusCode());
            $this->assertSame(['error' => ['message' => 'Invalid token.']], $this->jsonBody());
        }
        $this->request('GET', '/api/v1/me', $this->tokenFor('visiteur@example.com', 'un-nouveau-mot-de-passe'));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testATokenWithoutPasswordFingerprintIsRefused(): void
    {
        // Given : un jeton signé par le serveur mais émis avant la règle (aucune empreinte de mot de passe)
        $user = $this->savedUser('visiteur@example.com');
        $token = static::getContainer()->get(JWTEncoderInterface::class)->encode(['username' => $user->getEmail(), 'iat' => time(), 'exp' => time() + 3600]);

        // When
        $this->request('GET', '/api/v1/me', $token);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'Invalid token.']], $this->jsonBody());
    }

    public function testAUserCannotReadAnotherAccountButAnAdministratorCan(): void
    {
        // Given
        $other = $this->savedUser('other@example.com');
        $this->savedUser('visiteur@example.com');
        $this->savedUser('admin@example.com', admin: true);

        // When
        $this->request('GET', '/api/v1/users/' . $other->getId(), $this->tokenFor('visiteur@example.com', 'test'));

        // Then
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // When
        $this->request('GET', '/api/v1/users/' . $other->getId(), $this->tokenFor('admin@example.com', 'test'));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('other@example.com', $this->jsonBody()['email']);
    }

    /**
     * Le secret du dernier lien de bienvenue envoyé (le journal du mailer garde les messages de la dernière requête).
     */
    private function welcomeSecret(): string
    {
        $emails = array_values(array_filter(
            static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getMessages(),
            static fn(mixed $message): bool => $message instanceof Email,
        ));
        $this->assertNotSame([], $emails, 'aucun email envoyé');
        $this->assertSame(1, preg_match('#^https?://\S+/mot-de-passe-oublie/confirmation\?token=([0-9a-f]{64})&bienvenue=1$#m', end($emails)->getTextBody(), $matches));

        return $matches[1];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, ?string $token = null, ?array $body = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $this->client->request($method, $uri, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), server: $server);
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/login_check', content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
    }

    private function tokenFor(string $email, string $password): string
    {
        $this->login($email, $password);

        return $this->jsonBody()['token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function savedUser(string $email, bool $admin = false): User
    {
        $builder = new UserBuilder()->withEmail($email);
        $user = ($admin ? $builder->isAdmin() : $builder)->build();
        static::getContainer()->get(UserRepository::class)->save($user);

        return $user;
    }
}
