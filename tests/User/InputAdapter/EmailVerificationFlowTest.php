<?php

namespace Repas\Tests\User\InputAdapter;


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
 * Confirmation d'adresse email de bout en bout (ADR 0020), avec les vrais bus, la vraie base et le vrai notifier, pour un compte en attente de l'ancien formulaire : inscription,
 * connexion refusée tant que l'adresse n'est pas confirmée, lien reçu par email, confirmation, connexion. Le transport est
 * `null://null` en test : les messages sont lus dans le journal du mailer.
 */
class EmailVerificationFlowTest extends DatabaseTestCase
{
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

    public function testAPendingVisitorOfTheOldFormIsActivatedByTheLinkButTheOldPasswordOpensNothing(): void
    {
        // Given : un compte en attente de l'ancien formulaire, avec le mot de passe choisi AVANT la confirmation
        $this->registerLikeTheOldForm('alexiane@example.com', 'un-mot-de-passe');

        // Then : le compte attend, un email est parti vers l'adresse saisie
        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame('alexiane@example.com', $emails[0]->getTo()[0]->getAddress());
        $secret = $this->secretOf($emails[0]);

        // When : connexion avant confirmation
        $this->post('/api/login_check', ['email' => 'alexiane@example.com', 'password' => 'un-mot-de-passe']);

        // Then : le mot de passe est bon, l'adresse pas confirmée
        $this->assertSame(403, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'EMAIL_NOT_VERIFIED']], $this->jsonBody());
        $this->assertSame(0, $this->refreshTokenCount());

        // When : le lien est confirmé
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $secret]);

        // Then : l'adresse est confirmée et le compte actif, mais le mot de passe d'avant la confirmation ne sert plus (ADR 0020 amendé) :
        // celui qui l'a posé n'était peut-être pas le propriétaire de la boîte ; le propriétaire en choisit un par « mot de passe oublié »
        $this->assertSame(204, $this->statusCode());
        $this->assertSame('ACTIVE', $this->connection()->fetchOne('SELECT status FROM "user"'));
        $this->assertSame('!', $this->connection()->fetchOne('SELECT password FROM "user"'));
        $this->post('/api/login_check', ['email' => 'alexiane@example.com', 'password' => 'un-mot-de-passe']);
        $this->assertSame(401, $this->statusCode());
        $this->assertSame(0, $this->verificationTokenCount());
        $this->assertSame(0, $this->refreshTokenCount());
    }

    public function testAWrongPasswordDoesNotRevealThatTheAccountIsPending(): void
    {
        // Given
        $this->registerLikeTheOldForm('alexiane@example.com', 'un-mot-de-passe');

        // When
        $this->post('/api/login_check', ['email' => 'alexiane@example.com', 'password' => 'pas-le-bon']);

        // Then : même réponse qu'un compte inconnu
        $this->assertSame(401, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'Invalid credentials.']], $this->jsonBody());
    }

    public function testALinkWorksOnlyOnce(): void
    {
        // Given
        $this->registerLikeTheOldForm('alexiane@example.com', 'un-mot-de-passe');
        $secret = $this->secretOf($this->sentEmails()[0]);
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $secret]);
        $this->assertSame(204, $this->statusCode());

        // When
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $secret]);

        // Then
        $this->assertSame(422, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'INVALID_EMAIL_VERIFICATION_TOKEN']], $this->jsonBody());
    }

    public function testAnUnknownOrExpiredLinkIsRefusedAndTheAccountStaysPending(): void
    {
        // Given
        $this->registerLikeTheOldForm('alexiane@example.com', 'un-mot-de-passe');
        $secret = $this->secretOf($this->sentEmails()[0]);

        // When
        $this->post('/api/v1/users/email-verification/confirm', ['token' => 'inconnu']);

        // Then
        $this->assertSame(422, $this->statusCode());

        // When : le lien a plus de 24 heures
        $this->connection()->executeStatement("UPDATE email_verification_token SET expires_at = NOW() - INTERVAL '1 minute'");
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $secret]);

        // Then
        $this->assertSame(422, $this->statusCode());
        $this->post('/api/login_check', ['email' => 'alexiane@example.com', 'password' => 'un-mot-de-passe']);
        $this->assertSame(403, $this->statusCode());
    }

    public function testAskingForANewLinkReplacesTheOldOneOnceTheCooldownIsOver(): void
    {
        // Given : un lien envoyé à l'inscription
        $this->registerLikeTheOldForm('alexiane@example.com', 'un-mot-de-passe');
        $firstSecret = $this->secretOf($this->sentEmails()[0]);

        // When : renvoi immédiat
        $this->post('/api/v1/users/email-verification/send', ['email' => 'alexiane@example.com']);

        // Then : même réponse, mais rien n'est renvoyé pendant la minute de grâce
        $this->assertSame(204, $this->statusCode());
        $this->assertCount(1, $this->sentEmails());

        // When : le premier lien a plus d'une minute
        $this->connection()->executeStatement("UPDATE email_verification_token SET issued_at = NOW() - INTERVAL '2 minutes'");
        $this->post('/api/v1/users/email-verification/send', ['email' => 'Alexiane@Example.com']);

        // Then : un second email part, avec un autre lien ; l'ancien ne marche plus
        $this->assertSame(204, $this->statusCode());
        $this->assertCount(2, $this->sentEmails());
        $secondSecret = $this->secretOf($this->sentEmails()[1]);
        $this->assertNotSame($firstSecret, $secondSecret);
        $this->assertSame(1, $this->verificationTokenCount());
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $firstSecret]);
        $this->assertSame(422, $this->statusCode());
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $secondSecret]);
        $this->assertSame(204, $this->statusCode());
    }

    public function testAskingForALinkForAnUnknownOrConfirmedAddressAnswersTheSameAndSendsNothing(): void
    {
        // Given : un compte déjà actif (créé hors inscription)
        $this->connection()->executeStatement(
            "INSERT INTO \"user\" (id, email, password, roles, default_serving, status, statistics, credentials_version) VALUES ('active-id', 'actif@example.com', 'x', '[]', 2, 'ACTIVE', '{}', 0)",
        );

        // When
        $this->post('/api/v1/users/email-verification/send', ['email' => 'inconnu@example.com']);
        $unknownStatus = $this->statusCode();
        $unknownBody = $this->client->getResponse()->getContent();
        $this->post('/api/v1/users/email-verification/send', ['email' => 'actif@example.com']);

        // Then : 204 dans les deux cas, aucun email, rien ne distingue les adresses
        $this->assertSame([204, ''], [$unknownStatus, $unknownBody]);
        $this->assertSame([204, ''], [$this->statusCode(), $this->client->getResponse()->getContent()]);
        $this->assertSame([], $this->sentEmails());
    }

    public function testRegisteringAgainWithAPendingAddressWritesNoPasswordAndTheAccountStaysTheFirstOne(): void
    {
        // Given : un compte en attente de l'ancien formulaire
        $this->registerLikeTheOldForm('alexiane@example.com', 'un-mot-de-passe');
        $password = $this->connection()->fetchOne('SELECT password FROM "user"');

        // When : quelqu'un d'autre tente la même adresse, avec son propre mot de passe
        $this->post('/api/v1/users', ['email' => 'alexiane@example.com', 'password' => 'autre-mot-de-passe', 'defaultServing' => 9]);

        // Then : la même réponse qu'une adresse nouvelle ; rien n'écrase le compte (ni son mot de passe, ni son nombre de personnes)
        $this->assertSame([204, ''], [$this->statusCode(), $this->client->getResponse()->getContent()]);
        $this->assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM "user"'));
        $this->assertSame([$password, 3], [$this->connection()->fetchOne('SELECT password FROM "user"'), (int) $this->connection()->fetchOne('SELECT default_serving FROM "user"')]);
    }

    /**
     * Un compte tel que l'ancien formulaire d'inscription le créait : en attente, avec le mot de passe saisi, et le lien de confirmation
     * d'adresse envoyé. L'inscription par la seule adresse ne pose plus de mot de passe (ADR 0020 amendé, parcours dans
     * `RegistrationByLinkFlowTest`) ; ce lien de confirmation ne sert plus qu'à ces comptes-là.
     */
    private function registerLikeTheOldForm(string $email, string $password): void
    {
        $hasher = static::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class);
        $user = new UserBuilder()->withEmail($email)->withServing(3)->withPassword($hasher->hash($password))->isPending()->build();
        static::getContainer()->get(UserRepository::class)->save($user);
        static::getContainer()->get(EmailVerificationIssuer::class)->issueFor($user);
        $this->emails = [...$this->emails, ...$this->emailsOfTheLastRequest()];
        // Aucune requête ne vide ici le journal du mailer : on le fait, pour ne pas compter ce message une seconde fois
        static::getContainer()->get('mailer.message_logger_listener')->reset();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        $this->emails = [...$this->emails, ...$this->emailsOfTheLastRequest()];
    }

    private function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return $this->emails;
    }

    /**
     * @return list<Email>
     */
    private function emailsOfTheLastRequest(): array
    {
        return array_values(array_filter(
            static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getMessages(),
            static fn(mixed $message): bool => $message instanceof Email,
        ));
    }

    private function secretOf(Email $email): string
    {
        $this->assertSame(1, preg_match('#^https?://\S+/confirmation-email\?token=([0-9a-f]{64})$#m', $email->getTextBody(), $matches));

        return $matches[1];
    }

    private function verificationTokenCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM email_verification_token');
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
