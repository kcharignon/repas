<?php

namespace Repas\Tests\User\InputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Inscription par la seule adresse, de bout en bout (ADR 0020, amendement du 2026-10-28), avec les vrais bus, la vraie base, les vrais
 * plafonds et le vrai notifier : l'adresse, l'email de bienvenue, le choix du mot de passe par le lien, la connexion. Le transport est
 * `null://null` en test : les messages sont lus dans le journal du mailer.
 *
 * Le point central : la réponse de `POST /api/v1/users` est la MÊME pour une adresse nouvelle, un compte en attente, un compte actif et un
 * compte désactivé, et chaque cas qui écrit à l'adresse fait exactement un envoi.
 */
class RegistrationByLinkFlowTest extends DatabaseTestCase
{
    private const string EMAIL = 'visiteur@example.com';
    private const string PASSWORD = 'un-mot-de-passe';

    private KernelBrowser $client;
    /** @var list<Email> les emails partis depuis le début du test */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        // Le même noyau d'une requête à l'autre : le journal du mailer et l'état des plafonds gardent la mémoire des requêtes
        $this->client->disableReboot();
    }

    public function testTheAnswerIsTheSameForANewAddressAPendingAccountAnActiveAccountAndADisabledAccount(): void
    {
        // Given : un compte de chaque état
        $this->saveUser('en-attente@example.com', fn(UserBuilder $builder) => $builder->withoutPassword()->isPending());
        $this->saveUser('actif@example.com');
        $this->saveUser('desactive@example.com', fn(UserBuilder $builder) => $builder->isDisabled());

        // When
        $answers = [];
        foreach (['nouveau@example.com', 'en-attente@example.com', 'actif@example.com', 'desactive@example.com'] as $email) {
            $this->post('/api/v1/users', ['email' => $email, 'defaultServing' => 3]);
            $answers[$email] = [$this->statusCode(), $this->client->getResponse()->getContent(), $this->headersWithoutDate()];
        }

        // Then : même statut, même corps, mêmes en-têtes ; ni Location ni quoi que ce soit qui porte l'identifiant d'un compte
        $this->assertSame(204, $answers['nouveau@example.com'][0]);
        $this->assertSame('', $answers['nouveau@example.com'][1]);
        foreach ($answers as $email => $answer) {
            $this->assertEquals($answers['nouveau@example.com'], $answer, $email);
        }
        $this->assertArrayNotHasKey('location', $answers['nouveau@example.com'][2]);
    }

    public function testTheOldClientBodyWithAPasswordGivesTheSameAnswersAndTheSameEffects(): void
    {
        // Given
        $this->saveUser('actif@example.com');
        $this->saveUser('desactive@example.com', fn(UserBuilder $builder) => $builder->isDisabled());

        // When : le corps de l'ancien formulaire, pour une adresse nouvelle, un compte actif et un compte désactivé
        $answers = [];
        foreach (['nouveau@example.com', 'actif@example.com', 'desactive@example.com'] as $email) {
            $this->post('/api/v1/users', ['email' => $email, 'password' => 'mot-de-passe-de-l-ancien-formulaire', 'defaultServing' => 3]);
            $answers[$email] = [$this->statusCode(), $this->client->getResponse()->getContent(), $this->headersWithoutDate()];
        }

        // Then : accepté sans rien casser, et le mot de passe saisi n'est écrit nulle part
        foreach ($answers as $email => $answer) {
            $this->assertEquals([204, ''], array_slice($answer, 0, 2), $email);
        }
        $this->assertSame('!', $this->connection()->fetchOne('SELECT password FROM "user" WHERE email = ?', ['nouveau@example.com']));
        $this->assertCount(2, $this->emails, 'un email pour l\'adresse nouvelle, un pour le compte actif');
        $this->post('/api/login_check', ['email' => 'nouveau@example.com', 'password' => 'mot-de-passe-de-l-ancien-formulaire']);
        $this->assertSame(401, $this->statusCode());
    }

    public function testANewAddressGetsAPendingAccountWithoutPasswordAndAWelcomeEmail(): void
    {
        // When
        $this->post('/api/v1/users', ['email' => 'Visiteur@Example.COM', 'defaultServing' => 3]);

        // Then : un compte en attente, sans mot de passe, au nombre de personnes demandé
        $this->assertSame(204, $this->statusCode());
        $row = $this->connection()->fetchAssociative('SELECT email, status, password, default_serving, credentials_version FROM "user"');
        $this->assertSame([self::EMAIL, 'PENDING', '!', 3, 0], array_values($row));
        // …un seul email, à l'adresse, avec le lien qui ouvre l'écran de choix du mot de passe
        $this->assertCount(1, $this->emails);
        $this->assertSame(self::EMAIL, $this->emails[0]->getTo()[0]->getAddress());
        $this->assertSame('Bienvenue sur Repas : choisis ton mot de passe', $this->emails[0]->getSubject());
        $secret = $this->welcomeSecretOf($this->emails[0]);
        // …un lien de 24 heures, conservé haché
        $this->assertSame(86400, (int) $this->connection()->fetchOne('SELECT EXTRACT(EPOCH FROM (expires_at - issued_at)) FROM password_reset_token'));
        $this->assertSame(hash('sha256', $secret), $this->connection()->fetchOne('SELECT token_hash FROM password_reset_token'));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM email_verification_token'));

        // When : on tente de se connecter avec différentes saisies, dont le secret du lien et la valeur « sans mot de passe » du compte
        foreach (['', '!', self::PASSWORD, $secret, str_repeat('a', 72)] as $attempt) {
            $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => $attempt]);

            // Then : un mot de passe faux comme pour un compte inconnu, jamais « adresse non confirmée » (le mot de passe n'est pas bon)
            $this->assertContains($this->statusCode(), [400, 401], sprintf('mot de passe « %s »', substr($attempt, 0, 8)));
            $this->assertNotSame(200, $this->statusCode());
        }
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM refresh_token'));
    }

    public function testTheLinkChoosesThePasswordActivatesTheAccountAndWorksOnlyOnce(): void
    {
        // Given
        $this->post('/api/v1/users', ['email' => self::EMAIL]);
        $secret = $this->welcomeSecretOf($this->emails[0]);

        // When : il choisit son mot de passe
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::PASSWORD]);

        // Then : le compte est actif, le lien consommé, la connexion marche avec ce mot de passe et lui seul
        $this->assertSame([204, ''], [$this->statusCode(), $this->client->getResponse()->getContent()]);
        $this->assertSame('ACTIVE', $this->connection()->fetchOne('SELECT status FROM "user"'));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM password_reset_token'));
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame(200, $this->statusCode());
        $this->assertArrayHasKey('token', $this->jsonBody());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => 'un-autre-mot-de-passe']);
        $this->assertSame(401, $this->statusCode());

        // When : le même lien une seconde fois
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => 'un-autre-mot-de-passe']);

        // Then
        $this->assertSame(422, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'INVALID_PASSWORD_RESET_TOKEN']], $this->jsonBody());
    }

    public function testTheWelcomeLinkExpiresAfterTwentyFourHoursButNotBefore(): void
    {
        // Given
        $this->post('/api/v1/users', ['email' => self::EMAIL]);
        $secret = $this->welcomeSecretOf($this->emails[0]);

        // When : le lien a 23 heures, puis plus de 24
        $this->connection()->executeStatement("UPDATE password_reset_token SET issued_at = NOW() - INTERVAL '23 hours', expires_at = NOW() + INTERVAL '1 hour'");
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => '1234567']);
        $this->assertSame(422, $this->statusCode(), 'un mot de passe trop court est refusé sans consommer le lien');
        $this->connection()->executeStatement("UPDATE password_reset_token SET expires_at = NOW() - INTERVAL '1 minute'");
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => self::PASSWORD]);

        // Then : refusé, le compte reste en attente ; un nouveau lien s'obtient en saisissant l'adresse de nouveau
        $this->assertSame(422, $this->statusCode());
        $this->assertSame(['error' => ['message' => 'INVALID_PASSWORD_RESET_TOKEN']], $this->jsonBody());
        $this->assertSame('PENDING', $this->connection()->fetchOne('SELECT status FROM "user"'));
        $this->post('/api/v1/users', ['email' => self::EMAIL]);
        $this->assertCount(2, $this->emails);
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $this->welcomeSecretOf($this->emails[1]), 'password' => self::PASSWORD]);
        $this->assertSame(204, $this->statusCode());
    }

    public function testASecondRegistrationOfAPendingAddressSendsANewLinkAndTheOldOneStopsWorking(): void
    {
        // Given : un compte inscrit, dont le lien n'a pas servi
        $this->post('/api/v1/users', ['email' => self::EMAIL]);
        $first = $this->welcomeSecretOf($this->emails[0]);

        // When : la personne s'inscrit de nouveau (elle n'a pas reçu l'email, ou l'a perdu)
        $this->post('/api/v1/users', ['email' => self::EMAIL]);

        // Then : un nouvel email, un seul lien valable, le même compte
        $this->assertCount(2, $this->emails);
        $second = $this->welcomeSecretOf($this->emails[1]);
        $this->assertNotSame($first, $second);
        $this->assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM "user"'));
        $this->assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM password_reset_token'));
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $first, 'password' => self::PASSWORD]);
        $this->assertSame(422, $this->statusCode());
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $second, 'password' => self::PASSWORD]);
        $this->assertSame(204, $this->statusCode());
    }

    public function testAnActiveAccountIsToldItExistsAndNothingAboutItChanges(): void
    {
        // Given
        $this->saveUser(self::EMAIL);
        $before = $this->connection()->fetchAssociative('SELECT * FROM "user"');

        // When
        $this->post('/api/v1/users', ['email' => 'VISITEUR@example.com', 'password' => 'autre-mot-de-passe', 'defaultServing' => 9]);

        // Then : un rappel à l'adresse du compte, avec de quoi se connecter ; ni lien, ni compte de plus, ni mot de passe touché
        $this->assertSame(204, $this->statusCode());
        $this->assertCount(1, $this->emails);
        $this->assertSame(self::EMAIL, $this->emails[0]->getTo()[0]->getAddress());
        $this->assertSame('Tu as déjà un compte Repas', $this->emails[0]->getSubject());
        $this->assertStringContainsString('/connexion', $this->emails[0]->getTextBody());
        $this->assertStringContainsString('/mot-de-passe-oublie', $this->emails[0]->getTextBody());
        $this->assertStringNotContainsString('token=', $this->emails[0]->getTextBody());
        $this->assertEquals($before, $this->connection()->fetchAssociative('SELECT * FROM "user"'));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM password_reset_token'));
        $this->assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM "user"'));
    }

    public function testAnActiveAccountKeepsItsPasswordAndItsOpenSessionsWhenItsAddressIsRegisteredAgain(): void
    {
        // Given : un compte actif, connecté
        $this->saveUser(self::EMAIL);
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => 'test']);
        $this->assertSame(200, $this->statusCode());
        $jwt = $this->jsonBody()['token'];

        // When : son adresse est saisie de nouveau, avec un mot de passe de l'ancien formulaire
        $this->post('/api/v1/users', ['email' => self::EMAIL, 'password' => 'ancien-mot-de-passe']);

        // Then : le mot de passe saisi est ignoré, le sien marche toujours, sa session aussi
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => 'ancien-mot-de-passe']);
        $this->assertSame(401, $this->statusCode());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => 'test']);
        $this->assertSame(200, $this->statusCode());
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        $this->assertSame(200, $this->statusCode());
    }

    public function testADisabledAccountReceivesNothingIsNotReactivatedAndHoldsNoLink(): void
    {
        // Given
        $this->saveUser(self::EMAIL, fn(UserBuilder $builder) => $builder->isDisabled());
        $before = $this->connection()->fetchAssociative('SELECT * FROM "user"');

        // When
        $this->post('/api/v1/users', ['email' => self::EMAIL]);

        // Then : la même réponse, mais aucun email, aucun lien, aucun changement (ADR 0021)
        $this->assertSame([204, ''], [$this->statusCode(), $this->client->getResponse()->getContent()]);
        $this->assertSame([], $this->emails);
        $this->assertEquals($before, $this->connection()->fetchAssociative('SELECT * FROM "user"'));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM password_reset_token'));
    }

    /**
     * Le temps de réponse est dominé par l'envoi de l'email : chaque cas qui écrit à l'adresse en fait exactement un, et un seul.
     *
     */
    #[DataProvider('everyKindOfAddress')]
    public function testEveryAddressThatIsWrittenToCostsExactlyOneEmail(?callable $configure, bool $exists, int $emails): void
    {
        // Given
        if ($exists) {
            $this->saveUser(self::EMAIL, $configure);
        }

        // When
        $this->post('/api/v1/users', ['email' => self::EMAIL, 'password' => 'x', 'defaultServing' => 3]);

        // Then
        $this->assertSame(204, $this->statusCode());
        $this->assertCount($emails, $this->emails);
    }

    /**
     * @return iterable<string, array{?callable(UserBuilder): UserBuilder, bool, int}>
     */
    public static function everyKindOfAddress(): iterable
    {
        yield 'adresse nouvelle' => [null, false, 1];
        yield 'compte en attente sans mot de passe' => [static fn(UserBuilder $builder) => $builder->withoutPassword()->isPending(), true, 1];
        yield 'compte en attente de l\'ancien formulaire' => [static fn(UserBuilder $builder) => $builder->isPending(), true, 1];
        yield 'compte actif' => [null, true, 1];
        yield 'compte désactivé' => [static fn(UserBuilder $builder) => $builder->isDisabled(), true, 0];
    }

    public function testAMalformedAddressIsRefusedWhateverTheStateOfTheAccounts(): void
    {
        // Given
        $this->saveUser('actif@example.com');

        // When / Then : ce refus ne dit rien d'un compte
        foreach (['pas-un-email', 'actif@', '', 'actif@example.com' . str_repeat('a', 300)] as $email) {
            $this->post('/api/v1/users', ['email' => $email]);
            $this->assertSame(422, $this->statusCode(), $email);
        }
        $this->post('/api/v1/users', ['email' => 'actif@example.com', 'defaultServing' => 0]);
        $this->assertSame(422, $this->statusCode());
        $this->assertSame([], $this->emails);
    }

    /**
     * Le plafond par adresse saisie (6 par heure) et le plafond commun comptent chaque requête valide, que l'adresse ait un compte ou non,
     * dans l'état qu'il a : la septième demande pour une même adresse reçoit le même 429 dans les quatre cas.
     */
    #[DataProvider('everyKindOfAddress')]
    public function testTheSeventhRequestForTheSameAddressIsRefusedWhateverTheStateOfTheAccount(?callable $configure, bool $exists, int $emailsPerRequest): void
    {
        // Given
        if ($exists) {
            $this->saveUser(self::EMAIL, $configure);
        }

        // When : six demandes valent, depuis six adresses IP différentes, avec des écritures différentes de l'adresse
        foreach (['visiteur@example.com', 'VISITEUR@example.com', 'visiteur+a@example.com', 'visiteur@Example.com', 'Visiteur@Example.com', 'visiteur+b@example.com'] as $i => $spelling) {
            $this->post('/api/v1/users', ['email' => $spelling], address: '198.51.100.' . ($i + 1));
            $this->assertSame(204, $this->statusCode(), "demande $i");
        }
        $this->post('/api/v1/users', ['email' => self::EMAIL], address: '198.51.100.9');

        // Then : le même refus, quel que soit l'état du compte, avec le délai d'attente ; la septième n'a fait partir aucun email (quatre
        // des six écritures sont l'adresse du compte, les deux autres portent une étiquette `+x` : une autre adresse, donc une adresse nouvelle)
        $this->assertSame(429, $this->statusCode());
        $this->assertCount(4 * $emailsPerRequest, array_filter($this->emails, static fn(Email $email): bool => $email->getTo()[0]->getAddress() === self::EMAIL));
        $this->assertSame(['error' => ['message' => 'Too Many Requests']], $this->jsonBody());
        $this->assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(3601, (int) $this->client->getResponse()->headers->get('Retry-After'));
        // Une autre adresse n'est pas touchée
        $this->post('/api/v1/users', ['email' => 'quelquun-d-autre@example.com'], address: '198.51.100.10');
        $this->assertSame(204, $this->statusCode());
    }

    public function testRegistrationsFromOneAddressAreLimitedWhateverTheStateOfTheAccounts(): void
    {
        // Given : dix comptes de chaque état possible, dix inscriptions depuis la même adresse IP
        $this->saveUser('actif@example.com');
        $this->saveUser('desactive@example.com', fn(UserBuilder $builder) => $builder->isDisabled());
        $addresses = ['actif@example.com', 'desactive@example.com', 'nouveau1@example.com', 'nouveau2@example.com', 'nouveau3@example.com', 'actif@example.com', 'desactive@example.com', 'nouveau4@example.com', 'nouveau5@example.com', 'nouveau6@example.com'];
        foreach ($addresses as $i => $email) {
            $this->post('/api/v1/users', ['email' => $email], address: '203.0.113.5');
            $this->assertSame(204, $this->statusCode(), "inscription $i");
        }

        // When
        $this->post('/api/v1/users', ['email' => 'actif@example.com'], address: '203.0.113.5');

        // Then : la onzième est refusée pour un compte actif comme pour une adresse nouvelle
        $this->assertSame(429, $this->statusCode());
        $this->post('/api/v1/users', ['email' => 'nouveau-bis@example.com'], address: '203.0.113.5');
        $this->assertSame(429, $this->statusCode());
    }

    public function testTheResendOfAnAddressConfirmationLinkOfANewAccountSendsTheWelcomeLinkInstead(): void
    {
        // Given : un compte inscrit avec la seule adresse ; l'ancien bouton « Renvoyer le lien » du site appelle cette route
        $this->post('/api/v1/users', ['email' => self::EMAIL]);
        $this->connection()->executeStatement("UPDATE password_reset_token SET issued_at = NOW() - INTERVAL '2 minutes'");

        // When
        $this->post('/api/v1/users/email-verification/send', ['email' => self::EMAIL]);

        // Then : le lien qui part est celui du choix du mot de passe, pas une confirmation d'adresse qui activerait un compte sans mot de passe
        $this->assertSame(204, $this->statusCode());
        $this->assertCount(2, $this->emails);
        $this->assertSame('Bienvenue sur Repas : choisis ton mot de passe', $this->emails[1]->getSubject());
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM email_verification_token'));
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $this->welcomeSecretOf($this->emails[1]), 'password' => self::PASSWORD]);
        $this->assertSame(204, $this->statusCode());
    }

    public function testAnAdministratorWhoActivatesANewAccountLeavesItWithoutPasswordAndTheForgottenPasswordGivesItOne(): void
    {
        // Given : un compte inscrit avec la seule adresse, activé à la main par l'administrateur (ADR 0021)
        $this->saveUser('admin@example.com', fn(UserBuilder $builder) => $builder->isAdmin());
        $this->post('/api/v1/users', ['email' => self::EMAIL]);
        $this->post('/api/login_check', ['email' => 'admin@example.com', 'password' => 'test']);
        $adminJwt = $this->jsonBody()['token'];
        $id = $this->connection()->fetchOne('SELECT id FROM "user" WHERE email = ?', [self::EMAIL]);
        $this->client->request('PUT', '/api/v1/users/' . $id . '/status', content: json_encode(['status' => 'ACTIVE']), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $adminJwt]);
        $this->assertSame(204, $this->statusCode());
        $this->assertSame('ACTIVE', $this->connection()->fetchOne('SELECT status FROM "user" WHERE id = ?', [$id]));

        // When / Then : il n'a pas de mot de passe ; le mot de passe oublié lui en fait choisir un
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame(401, $this->statusCode());
        // Le lien de bienvenue date de plus d'une minute (sinon la demande est ignorée pendant le délai de grâce, ADR 0026)
        $this->connection()->executeStatement("UPDATE password_reset_token SET issued_at = NOW() - INTERVAL '2 minutes'");
        $this->post('/api/v1/users/password-reset/send', ['email' => self::EMAIL]);
        $this->assertSame(204, $this->statusCode());
        $reset = end($this->emails);
        $this->assertSame(1, preg_match('#token=([0-9a-f]{64})$#m', $reset->getTextBody(), $matches));
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $matches[1], 'password' => self::PASSWORD]);
        $this->assertSame(204, $this->statusCode());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame(200, $this->statusCode());
    }

    public function testAPendingAccountWithAnOldPasswordGetsTheWelcomeLinkAndTheChosenPasswordReplacesIt(): void
    {
        // Given : un compte en attente de l'ancien formulaire, avec son ancien mot de passe
        $hasher = static::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class);
        $this->saveUser(self::EMAIL, fn(UserBuilder $builder) => $builder->isPending()->withPassword($hasher->hash('ancien-mot-de-passe')));

        // When : l'adresse est saisie de nouveau, puis le mot de passe est choisi avec le lien de bienvenue
        $this->post('/api/v1/users', ['email' => self::EMAIL]);
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $this->welcomeSecretOf($this->emails[0]), 'password' => self::PASSWORD]);

        // Then : l'ancien mot de passe est remplacé par celui qui vient d'être choisi
        $this->assertSame(204, $this->statusCode());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => 'ancien-mot-de-passe']);
        $this->assertSame(401, $this->statusCode());
        $this->post('/api/login_check', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame(200, $this->statusCode());
    }

    /**
     * @param (callable(UserBuilder): UserBuilder)|null $configure
     */
    private function saveUser(string $email, ?callable $configure = null): void
    {
        $builder = new UserBuilder()->withEmail($email)->withServing(3);
        if ($configure !== null) {
            $configure($builder);
        }
        static::getContainer()->get(UserRepository::class)->save($builder->build());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $uri, array $body, string $address = '127.0.0.1'): void
    {
        $this->client->request('POST', $uri, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $address]);
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

    private function welcomeSecretOf(Email $email): string
    {
        $this->assertSame(1, preg_match('#^https?://\S+/mot-de-passe-oublie/confirmation\?token=([0-9a-f]{64})&bienvenue=1$#m', $email->getTextBody(), $matches));

        return $matches[1];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
