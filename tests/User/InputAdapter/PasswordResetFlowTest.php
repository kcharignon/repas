<?php

namespace Repas\Tests\User\InputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Service\EmailVerificationIssuer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Mot de passe oublié de bout en bout (ADR 0026), avec les vrais bus, la vraie base et le vrai notifier : demande du lien,
 * email reçu, nouveau mot de passe, connexions fermées, compte en attente activé. Le transport est `null://null` en test : les
 * messages sont lus dans le journal du mailer.
 */
class PasswordResetFlowTest extends DatabaseTestCase
{
    private const string EMAIL = 'alexiane@example.com';
    private const string OLD_PASSWORD = 'ancien-mot-de-passe';
    private const string NEW_PASSWORD = 'nouveau-mot-de-passe';

    private KernelBrowser $client;
    /** @var list<Email> les emails partis depuis le début du test (le mailer vide son journal entre deux requêtes) */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        // Le même noyau d'une requête à l'autre : le journal du mailer garde les messages envoyés
        $this->client->disableReboot();
    }

    public function testAForgottenPasswordIsReplacedByTheLinkReceivedByEmailAndEveryConnectionIsClosed(): void
    {
        // Given : un compte actif, connecté sur un appareil
        $this->createUser(self::EMAIL);
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::OLD_PASSWORD]);
        $this->assertSame(200, $this->statusCode());
        $this->assertSame(1, $this->refreshTokenCount());

        // When : l'utilisateur dit qu'il a oublié son mot de passe
        $this->post('/api/v1/users/password-reset/send', ['email' => self::EMAIL]);

        // Then : 204 sans corps, un email est parti vers l'adresse, au lien du front
        $this->assertSame([204, ''], [$this->statusCode(), $this->client->getResponse()->getContent()]);
        $this->assertCount(1, $this->emails);
        $this->assertSame(self::EMAIL, $this->emails[0]->getTo()[0]->getAddress());
        $this->assertSame('Choisis un nouveau mot de passe', $this->emails[0]->getSubject());
        $secret = $this->secretOf($this->emails[0]);

        // When : il choisit un nouveau mot de passe
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);

        // Then : l'ancien mot de passe ne marche plus, le nouveau si ; la connexion de l'autre appareil est fermée
        $this->assertSame(204, $this->statusCode());
        $this->assertSame(0, $this->refreshTokenCount(), 'les connexions du compte sont fermées');
        $this->assertSame(0, $this->resetTokenCount());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::OLD_PASSWORD]);
        $this->assertSame(401, $this->statusCode());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(200, $this->statusCode());
        $this->assertArrayHasKey('token', $this->jsonBody());
    }

    public function testTheJwtIssuedBeforeTheResetIsRevoked(): void
    {
        // Given
        $this->createUser(self::EMAIL);
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::OLD_PASSWORD]);
        $oldJwt = $this->jsonBody()['token'];
        $this->get('/api/v1/me', $oldJwt);
        $this->assertSame(200, $this->statusCode());
        $secret = $this->requestLink(self::EMAIL);

        // When
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);

        // Then : la version des identifiants a monté
        $this->get('/api/v1/me', $oldJwt);
        $this->assertSame(401, $this->statusCode());
    }

    public function testALinkWorksOnlyOnce(): void
    {
        // Given
        $this->createUser(self::EMAIL);
        $secret = $this->requestLink(self::EMAIL);
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(204, $this->statusCode());

        // When
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => 'un-autre-mot-de-passe']);

        // Then
        $this->assertSame(422, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'INVALID_PASSWORD_RESET_TOKEN']], $this->jsonBody());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(200, $this->statusCode());
    }

    public function testAnUnknownLinkAndAnExpiredLinkGetTheSameAnswerAndNothingChanges(): void
    {
        // Given
        $this->createUser(self::EMAIL);
        $secret = $this->requestLink(self::EMAIL);

        // When : un lien inconnu
        $this->post('/api/v1/users/password-reset/confirm', ['token' => 'inconnu', 'password' => self::NEW_PASSWORD]);
        $unknown = [$this->statusCode(), $this->client->getResponse()->getContent()];

        // When : le lien a plus de 30 minutes
        $this->connection()->executeStatement("UPDATE password_reset_token SET expires_at = NOW() - INTERVAL '1 minute'");
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);

        // Then : même réponse, on ne dit pas lequel des deux cas
        $this->assertSame($unknown, [$this->statusCode(), $this->client->getResponse()->getContent()]);
        $this->assertSame(422, $this->statusCode());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::OLD_PASSWORD]);
        $this->assertSame(200, $this->statusCode());
    }

    public function testAShortPasswordIsRefusedAndTheLinkIsKept(): void
    {
        // Given
        $this->createUser(self::EMAIL);
        $secret = $this->requestLink(self::EMAIL);

        // When
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => '1234567']);

        // Then : 422 de validation, le lien reste utilisable avec un mot de passe valable
        $this->assertSame(422, $this->statusCode());
        $this->assertSame(1, $this->resetTokenCount());
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(204, $this->statusCode());
    }

    public function testAskingAgainReplacesTheOldLinkOnceTheCooldownIsOver(): void
    {
        // Given
        $this->createUser(self::EMAIL);
        $firstSecret = $this->requestLink(self::EMAIL);

        // When : nouvelle demande immédiate
        $this->post('/api/v1/users/password-reset/send', ['email' => self::EMAIL]);

        // Then : même réponse, mais rien n'est renvoyé pendant la minute de grâce
        $this->assertSame(204, $this->statusCode());
        $this->assertCount(1, $this->emails);

        // When : le premier lien a plus d'une minute
        $this->connection()->executeStatement("UPDATE password_reset_token SET issued_at = NOW() - INTERVAL '2 minutes'");
        $this->post('/api/v1/users/password-reset/send', ['email' => 'Alexiane@Example.com']);

        // Then : un second email part, avec un autre lien ; l'ancien ne marche plus
        $this->assertSame(204, $this->statusCode());
        $this->assertCount(2, $this->emails);
        $secondSecret = $this->secretOf($this->emails[1]);
        $this->assertNotSame($firstSecret, $secondSecret);
        $this->assertSame(1, $this->resetTokenCount());
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $firstSecret, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(422, $this->statusCode());
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secondSecret, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(204, $this->statusCode());
    }

    public function testAnUnknownAndADisabledAddressGetTheSameAnswerAsAnActiveOneAndNothingIsWritten(): void
    {
        // Given
        $this->createUser('actif@example.com');
        $this->createUser('desactive@example.com', disabled: true);

        // When
        $this->post('/api/v1/users/password-reset/send', ['email' => 'actif@example.com']);
        $active = [$this->statusCode(), $this->client->getResponse()->getContent(), $this->headersWithoutDate()];
        $emailsAfterActive = count($this->emails);
        $this->post('/api/v1/users/password-reset/send', ['email' => 'inconnu@example.com']);
        $unknown = [$this->statusCode(), $this->client->getResponse()->getContent(), $this->headersWithoutDate()];
        $this->post('/api/v1/users/password-reset/send', ['email' => 'desactive@example.com']);
        $disabled = [$this->statusCode(), $this->client->getResponse()->getContent(), $this->headersWithoutDate()];

        // Then : rien ne distingue les trois, seul le compte actif a reçu un email et un jeton
        $this->assertSame([204, ''], array_slice($active, 0, 2));
        $this->assertEquals($active, $unknown);
        $this->assertEquals($active, $disabled);
        $this->assertSame(1, $emailsAfterActive);
        $this->assertCount(1, $this->emails);
        $this->assertSame(1, $this->resetTokenCount());
    }

    public function testADisabledAccountThatStillHoldsALinkGetsTheAnswerOfAnInvalidLinkAndStaysUntouched(): void
    {
        // Given : le lien a été émis avant la désactivation
        $this->createUser(self::EMAIL);
        $secret = $this->requestLink(self::EMAIL);
        $this->connection()->executeStatement("UPDATE \"user\" SET status = 'DISABLED'");
        $password = $this->connection()->fetchOne('SELECT password FROM "user"');

        // When
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);

        // Then : même réponse qu'un lien invalide, rien n'est écrit (le lien reste, il expirera)
        $this->assertSame(422, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'INVALID_PASSWORD_RESET_TOKEN']], $this->jsonBody());
        $this->assertSame(['DISABLED', $password, 1], [
            $this->connection()->fetchOne('SELECT status FROM "user"'),
            $this->connection()->fetchOne('SELECT password FROM "user"'),
            $this->resetTokenCount(),
        ]);
    }

    public function testAPendingAccountWithAnOldPasswordIsActivatedByTheLinkAndTheOldPasswordNoLongerWorks(): void
    {
        // Given : un compte en attente de l'ancien formulaire d'inscription, avec son ancien mot de passe
        $this->createPendingUserOfTheOldForm(self::EMAIL, 'ancien-mot-de-passe');
        $verificationSecret = $this->secretOf($this->emails[0], 'confirmation-email');
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => 'ancien-mot-de-passe']);
        $this->assertSame(403, $this->statusCode());

        // When : la personne demande un mot de passe oublié, puis choisit le sien
        $secret = $this->requestLink(self::EMAIL, emailIndex: 1);
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);

        // Then : l'adresse est confirmée, le compte est actif, l'ancien mot de passe est remplacé
        $this->assertSame(204, $this->statusCode());
        $this->assertSame('ACTIVE', $this->connection()->fetchOne('SELECT status FROM "user"'));
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => 'ancien-mot-de-passe']);
        $this->assertSame(401, $this->statusCode());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(200, $this->statusCode());
        // Le lien de l'inscription ne sert plus
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM email_verification_token'));
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $verificationSecret]);
        $this->assertSame(422, $this->statusCode());
    }

    public function testAnAccountWithoutAUsablePasswordChoosesAFirstOne(): void
    {
        // Given : un compte dont le mot de passe est verrouillé (créé par une connexion Google, à venir)
        $this->createUser(self::EMAIL, password: '!');
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => '!']);
        $this->assertSame(401, $this->statusCode());

        // When
        $secret = $this->requestLink(self::EMAIL);
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);

        // Then
        $this->assertSame(204, $this->statusCode());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(200, $this->statusCode());
    }

    public function testTheProtectedAccountResetsItsOwnPassword(): void
    {
        // Given
        $this->createUser(User::PROTECTED_EMAIL);

        // When
        $secret = $this->requestLink(User::PROTECTED_EMAIL);
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::NEW_PASSWORD]);

        // Then
        $this->assertSame(204, $this->statusCode());
        $this->post('/api/login_check', ['email' => User::PROTECTED_EMAIL, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(200, $this->statusCode());
    }

    public function testAnAddressConfirmationLinkCannotResetAPasswordAndAResetLinkCannotConfirmAnAddress(): void
    {
        // Given : un compte en attente (ancien formulaire), avec son lien de confirmation d'adresse ; et un lien de réinitialisation
        $this->createPendingUserOfTheOldForm(self::EMAIL, self::OLD_PASSWORD);
        $verificationSecret = $this->secretOf($this->emails[0], 'confirmation-email');
        $resetSecret = $this->requestLink(self::EMAIL, emailIndex: 1);

        // When & Then : chaque lien n'ouvre que sa propre porte
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $verificationSecret, 'password' => self::NEW_PASSWORD]);
        $this->assertSame(422, $this->statusCode());
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $resetSecret]);
        $this->assertSame(422, $this->statusCode());
        $this->assertSame('PENDING', $this->connection()->fetchOne('SELECT status FROM "user"'));
    }

    /**
     * Un compte tel que l'ancien formulaire d'inscription le créait : en attente, avec le mot de passe saisi, et le lien de confirmation
     * d'adresse envoyé (l'inscription par la seule adresse ne pose plus de mot de passe, ADR 0020 amendé).
     */
    private function createPendingUserOfTheOldForm(string $email, string $password): void
    {
        $hasher = static::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class);
        $user = new UserBuilder()->withEmail($email)->withPassword($hasher->hash($password))->isPending()->build();
        static::getContainer()->get(UserRepository::class)->save($user);
        static::getContainer()->get(EmailVerificationIssuer::class)->issueFor($user);
        $this->collectEmails();
        // Aucune requête ne vide ici le journal du mailer : on le fait, pour ne pas compter ce message une seconde fois
        static::getContainer()->get('mailer.message_logger_listener')->reset();
    }

    private function createUser(string $email, bool $disabled = false, string $password = self::OLD_PASSWORD): void
    {
        $hasher = static::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class);
        $user = User::create(
            id: bin2hex(random_bytes(8)),
            email: $email,
            roles: ['ROLE_USER'],
            password: $password === '!' ? '!' : $hasher->hash($password),
            defaultServing: 3,
            createdAt: new DateTimeImmutable(),
        );
        if ($disabled) {
            $user->changeStatus(\Repas\User\Domain\Model\UserStatus::DISABLED);
        }
        static::getContainer()->get(UserRepository::class)->save($user);
    }

    /**
     * Demande le lien et renvoie son secret, lu dans l'email parti (le `$emailIndex`-ième email du test, 0 par défaut).
     */
    private function requestLink(string $email, int $emailIndex = 0): string
    {
        $this->post('/api/v1/users/password-reset/send', ['email' => $email]);
        $this->assertSame(204, $this->statusCode());
        $this->assertCount($emailIndex + 1, $this->emails);

        return $this->secretOf($this->emails[$emailIndex]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->collectEmails();
    }

    private function get(string $uri, string $jwt): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
    }

    private function collectEmails(): void
    {
        $this->emails = [...$this->emails, ...array_values(array_filter(
            static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getMessages(),
            static fn(mixed $message): bool => $message instanceof Email,
        ))];
    }

    private function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * Les en-têtes de la dernière réponse, sans `date` : elle change à la seconde, et deux réponses de part et d'autre d'une seconde ne
     * seraient plus « les mêmes » alors que rien ne les distingue.
     *
     * @return array<string, array<int, string|null>>
     */
    private function headersWithoutDate(): array
    {
        $headers = $this->client->getResponse()->headers->all();
        unset($headers['date']);

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function secretOf(Email $email, string $path = 'mot-de-passe-oublie/confirmation'): string
    {
        $this->assertSame(1, preg_match('#^https?://\S+/' . preg_quote($path, '#') . '\?token=([0-9a-f]{64})$#m', $email->getTextBody(), $matches));

        return $matches[1];
    }

    private function resetTokenCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM password_reset_token');
    }

    private function refreshTokenCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM refresh_token');
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
