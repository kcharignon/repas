<?php

namespace Repas\Tests\User\InputAdapter;


use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledIngredientSeedingFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SeedingDataset;
use Repas\Tests\Helper\SeedingMeasures;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\PasswordResetTokenRepository;
use Repas\User\Domain\Model\EmailVerificationToken;
use Repas\User\Domain\Model\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

/**
 * L'amorçage des ingrédients d'un compte à son ACTIVATION (ING-008), de bout en bout : vraies routes, JWT, vrais bus, vraie base, vrai écouteur.
 * Inscription, confirmation de l'adresse, lien de mot de passe oublié, statut fixé par l'administrateur : chaque chemin qui rend un compte actif
 * lui donne ses ingrédients dans la MÊME transaction ; l'inscription seule ne copie rien (abus) ; l'interrupteur coupé ne casse rien ; un
 * amorçage qui échoue annule l'activation ; un compte n'est jamais amorcé deux fois ; la suppression du compte efface tout.
 *
 * L'interrupteur est coupé dans l'environnement de test (comme en production par défaut) : les tests qui amorcent allument un double.
 */
class UserActivationSeedingFlowTest extends DatabaseTestCase
{
    use SeedingMeasures;

    private const string PASSWORD = 'un-mot-de-passe';

    private KernelBrowser $client;
    private SeedingDataset $dataset;
    /** @var list<Email> */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        // Le même noyau d'une requête à l'autre : le double de l'interrupteur et le journal du mailer survivent
        $this->client->disableReboot();
        $this->dataset = SeedingDataset::create($this->entityManager, new DataPersister(static::getContainer()));
        $this->dataset->referenceData();
    }

    public function testAVisitorWhoConfirmsTheirAddressReceivesTheBaseIngredientsAndTheRegistrationAloneCopiesNothing(): void
    {
        // Given : l'interrupteur est allumé
        $this->switchOn();
        $connection = $this->connection();
        $ingredientsBefore = (int) $connection->fetchOne('SELECT count(*) FROM ingredient');

        // When : l'inscription publique
        $this->post('/api/v1/users', ['email' => 'alexiane@example.com', 'password' => self::PASSWORD, 'defaultServing' => 3]);

        // Then : le compte attend ; AUCUNE ligne d'ingrédient n'a été écrite (un flot d'inscriptions jamais confirmées ne coûte rien)
        $this->assertSame(201, $this->statusCode());
        $userId = $this->userId('alexiane@example.com');
        $this->assertSame('PENDING', $this->statusOf($userId));
        $this->assertSame($ingredientsBefore, (int) $connection->fetchOne('SELECT count(*) FROM ingredient'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$userId]));
        $secret = $this->secretOf($this->emails[0]);

        // When : la confirmation de l'adresse
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $secret]);

        // Then : le compte est actif et a SES ingrédients : les huit « de base », avec leurs conversions propres, un alias par copie
        $this->assertSame(204, $this->statusCode());
        $this->assertSame('ACTIVE', $this->statusOf($userId));
        $this->assertSame($this->sortedBase(), array_keys($this->copiesOf($userId)));
        $this->assertSame(8, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ? AND adopted_from IS NOT NULL AND NOT is_base', [$userId]));
        $this->assertSame(['activation'], $connection->fetchFirstColumn('SELECT DISTINCT source FROM ingredient_alias WHERE user_id = ?', [$userId]));
        $this->assertSame(3, (int) $connection->fetchOne('SELECT count(*) FROM conversion c JOIN ingredient i ON i.slug = c.ingredient WHERE i.creator = ?', [$userId]));
        // Les statistiques n'ont pas bougé : un amorçage n'est pas une création
        $this->assertSame(0, (int) (json_decode($connection->fetchOne('SELECT statistics::text FROM "user" WHERE id = ?', [$userId]), true)['ingredients'] ?? 0));
        // Les ingrédients du catalogue n'ont pas bougé : ils sont toujours les douze du propriétaire, et aucun n'est sans créateur
        $this->assertSame(12, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$this->dataset->ownerId]));

        // When : il se connecte et ouvre un de ses ingrédients, puis son rayon
        $jwt = $this->login('alexiane@example.com');
        $copy = $this->copiesOf($userId)['beurre'];
        $this->get('/api/v1/ingredients/' . $copy, $jwt);
        $own = $this->statusCode();
        $body = $this->jsonBody();
        $this->get('/api/v1/departments/rayon-a/ingredients', $jwt);
        $slugs = array_column($this->jsonBody()['member'], 'slug');

        // Then : sa copie est à lui, modifiable, jamais « de base » ; dans son rayon, ses copies remplacent les anciens communs de même nom
        // (les « spécifiques » ne lui sont pas copiés)
        $this->assertSame(200, $own);
        $this->assertFalse($body['isBase'], 'une copie reçue n\'est jamais « de base »');
        $this->assertTrue($body['canEdit']);
        $this->assertContains($this->copiesOf($userId)['tomate'], $slugs);
        $this->assertNotContains('tomate', $slugs);
        $this->assertNotContains('farine', $slugs);
    }

    public function testTheForgottenPasswordLinkThatActivatesAPendingAccountSeedsItToo(): void
    {
        // Given
        $this->switchOn();
        $userId = $this->savedPendingUser('oubli@example.com');
        $secret = 'le-secret-de-reinitialisation';
        static::getContainer()->get(PasswordResetTokenRepository::class)->add(PasswordResetToken::issue(UuidGenerator::new(), $userId, PasswordResetToken::hash($secret), new DateTimeImmutable()));

        // When
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => 'un-nouveau-mot-de-passe']);

        // Then
        $this->assertSame(204, $this->statusCode());
        $this->assertSame('ACTIVE', $this->statusOf($userId));
        $this->assertSame($this->sortedBase(), array_keys($this->copiesOf($userId)));
    }

    public function testAnAlreadyActiveAccountResettingItsPasswordIsNotSeeded(): void
    {
        // Given : un compte actif qui n'a pas été amorcé (activé quand l'interrupteur était coupé)
        $this->switchOn();
        $user = new UserBuilder()->withEmail('actif@example.com')->build();
        new DataPersister(static::getContainer())->saveUser($user);
        $secret = 'le-secret';
        static::getContainer()->get(PasswordResetTokenRepository::class)->add(PasswordResetToken::issue(UuidGenerator::new(), $user->getId(), PasswordResetToken::hash($secret), new DateTimeImmutable()));

        // When
        $this->post('/api/v1/users/password-reset/confirm', ['token' => $secret, 'password' => 'un-nouveau-mot-de-passe']);

        // Then : un compte actif qui le reste n'émet rien ; c'est le rattrapage qui le servira
        $this->assertSame(204, $this->statusCode());
        $this->assertSame([], $this->copiesOf($user->getId()));
    }

    public function testTheAdministratorActivatingAPendingOrADisabledAccountSeedsItAndReactivatingNeverCopiesAgain(): void
    {
        // Given
        $this->switchOn();
        $admin = new UserBuilder()->withEmail('admin-test@example.com')->isAdmin()->build();
        new DataPersister(static::getContainer())->saveUser($admin);
        $adminJwt = $this->login('admin-test@example.com');
        $pendingId = $this->savedPendingUser('en-attente@example.com');

        // When : PENDING -> ACTIVE
        $this->changeStatus($pendingId, 'ACTIVE', $adminJwt);

        // Then
        $this->assertSame(204, $this->statusCode());
        $this->assertSame($this->sortedBase(), array_keys($this->copiesOf($pendingId)));

        // When : DISABLED, puis ACTIVE de nouveau
        $this->changeStatus($pendingId, 'DISABLED', $adminJwt);
        $afterDisable = $this->ingredientStateOf($pendingId);
        $this->changeStatus($pendingId, 'ACTIVE', $adminJwt);

        // Then : rien n'est écrit la seconde fois (somme de contrôle de tout ce qui lui appartient, versions comprises)
        $this->assertSame(204, $this->statusCode());
        $this->assertSame($afterDisable, $this->ingredientStateOf($pendingId));
        $this->assertCount(8, $this->copiesOf($pendingId));

        // When : un compte désactivé qui n'a jamais rien reçu est activé
        $this->dataset->adoption->connection->executeStatement("UPDATE \"user\" SET status = 'DISABLED' WHERE id = ?", [$this->dataset->freshId]);
        $this->changeStatus($this->dataset->freshId, 'ACTIVE', $adminJwt);

        // Then : il est servi
        $this->assertSame($this->sortedBase(), array_keys($this->copiesOf($this->dataset->freshId)));
    }

    public function testTheCatalogOwnerIsNeverSeededEvenWhenActivated(): void
    {
        // Given : le propriétaire est remis en attente puis activé par un administrateur
        $this->switchOn();
        $admin = new UserBuilder()->withEmail('admin-test@example.com')->isAdmin()->build();
        new DataPersister(static::getContainer())->saveUser($admin);
        $adminJwt = $this->login('admin-test@example.com');
        $ownerBefore = $this->dataset->adoption->hashOfUser($this->dataset->ownerId);
        $this->dataset->adoption->connection->executeStatement("UPDATE \"user\" SET status = 'PENDING' WHERE id = ?", [$this->dataset->ownerId]);

        // When
        $this->changeStatus($this->dataset->ownerId, 'ACTIVE', $adminJwt);

        // Then : ses ingrédients sont les siens, il n'a ni copie ni alias
        $this->assertSame(204, $this->statusCode());
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$this->dataset->ownerId]));
        $this->assertSame($ownerBefore, $this->dataset->adoption->hashOfUser($this->dataset->ownerId));
    }

    public function testASecondAdministratorIsAnOrdinaryAccountAndIsSeeded(): void
    {
        // Given : un administrateur qui n'est pas le propriétaire du catalogue, en attente
        $this->switchOn();
        $user = new UserBuilder()->withEmail('second-admin@example.com')->isAdmin()->isPending()->build();
        new DataPersister(static::getContainer())->saveUser($user);

        // When
        $this->confirmWithRepositoryToken($user->getId());

        // Then
        $this->assertSame($this->sortedBase(), array_keys($this->copiesOf($user->getId())));
    }

    public function testWithTheSwitchOffTheActivationSucceedsAndCopiesNothing(): void
    {
        // Given : l'interrupteur est coupé (celui de l'environnement de test, comme la production par défaut)
        $this->assertFalse(static::getContainer()->get(IngredientSeedingFeature::class)->isEnabled());
        $userId = $this->savedPendingUser('coupe@example.com');
        $ingredientsBefore = (int) $this->connection()->fetchOne('SELECT count(*) FROM ingredient');

        // When
        $this->confirmWithRepositoryToken($userId);

        // Then : actif, aucun ingrédient de plus, aucun alias, il reste « non amorcé » (le rattrapage le servira)
        $this->assertSame('ACTIVE', $this->statusOf($userId));
        $this->assertSame($ingredientsBefore, (int) $this->connection()->fetchOne('SELECT count(*) FROM ingredient'));
        $this->assertSame([], $this->copiesOf($userId));
    }

    public function testAFailingSeedingCancelsTheActivationAndTheLinkCanBeUsedAgain(): void
    {
        // Given : une faute au moment de copier les conversions (après les alias et les copies d'ingrédients)
        $this->switchOn();
        $userId = $this->savedPendingUser('echec@example.com');
        $secret = $this->issueVerificationToken($userId);
        $connection = $this->connection();
        $connection->executeStatement(
            "CREATE FUNCTION test_boom() RETURNS trigger AS \$\$ BEGIN IF NEW.ingredient LIKE '%" . $userId . "' THEN RAISE EXCEPTION 'boom'; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql",
        );
        $connection->executeStatement('CREATE TRIGGER test_boom BEFORE INSERT ON conversion FOR EACH ROW EXECUTE FUNCTION test_boom()');
        $before = $this->dataset->adoption->hashOfUser($userId);

        // When
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $secret]);

        // Then : l'erreur est claire pour la personne qui réessaie, rien n'est à moitié fait : le compte reste en attente, aucune copie, le lien n'est pas consommé
        $this->assertGreaterThanOrEqual(500, $this->statusCode());
        $this->assertSame('PENDING', $this->statusOf($userId));
        $this->assertSame($before, $this->dataset->adoption->hashOfUser($userId));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$userId]));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM email_verification_token WHERE user_id = ?', [$userId]));

        // When : la faute est levée et la personne réessaie avec le même lien
        $connection->executeStatement('DROP TRIGGER test_boom ON conversion');
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $secret]);

        // Then
        $this->assertSame(204, $this->statusCode());
        $this->assertSame('ACTIVE', $this->statusOf($userId));
        $this->assertCount(8, $this->copiesOf($userId));
    }

    public function testWhenNothingIsMarkedBaseTheActivationSucceedsAndTheAccountStaysUnseededUntilACatchUp(): void
    {
        // Given : le classement n'est pas encore posé
        $this->switchOn();
        $this->dataset->adoption->connection->executeStatement('UPDATE ingredient SET is_base = FALSE');
        $userId = $this->savedPendingUser('sans-classement@example.com');

        // When
        $this->confirmWithRepositoryToken($userId);

        // Then : actif, rien copié, pas marqué amorcé
        $this->assertSame('ACTIVE', $this->statusOf($userId));
        $this->assertSame([], $this->copiesOf($userId));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$userId]));

        // When : le propriétaire classe, une nouvelle activation d'un autre compte est servie normalement
        $this->dataset->markBase(SeedingDataset::BASE);
        $other = $this->savedPendingUser('apres-classement@example.com');
        $this->confirmWithRepositoryToken($other);

        // Then : le premier reste non amorcé (c'est le rattrapage qui le servira), le second a ses huit
        $this->assertSame([], $this->copiesOf($userId));
        $this->assertCount(8, $this->copiesOf($other));
    }

    public function testAnAccountActivatedWhileTheSwitchWasOffIsServedByTheCatchUpCommandLater(): void
    {
        // Given : activé interrupteur coupé
        $userId = $this->savedPendingUser('plus-tard@example.com');
        $this->confirmWithRepositoryToken($userId);
        $this->assertSame([], $this->copiesOf($userId));

        // When : le rattrapage, interrupteur toujours coupé
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel)->find('repas:ingredients:seed'));
        $status = $tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [$userId]]);

        // Then
        $this->assertSame(0, $status, $tester->getDisplay());
        $this->assertSame($this->sortedBase(), array_keys($this->copiesOf($userId)));
    }

    public function testDeletingASeededAccountErasesEverythingItReceivedAndLeavesTheCatalogAlone(): void
    {
        // Given : un compte amorcé par son activation, et un administrateur
        $this->switchOn();
        $userId = $this->savedPendingUser('a-supprimer@example.com');
        $this->confirmWithRepositoryToken($userId);
        $this->assertCount(8, $this->copiesOf($userId));
        $admin = new UserBuilder()->withEmail('admin-test@example.com')->isAdmin()->build();
        new DataPersister(static::getContainer())->saveUser($admin);
        $adminJwt = $this->login('admin-test@example.com');
        $catalogBefore = $this->catalogHash();
        $connection = $this->connection();

        // When
        $this->client->request('DELETE', '/api/v1/users/' . $userId, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $adminJwt]);

        // Then : plus d'ingrédient, de conversion ni d'alias à lui ; le catalogue est intact
        $this->assertSame(204, $this->statusCode());
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$userId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient_alias WHERE user_id = ?', [$userId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient LIKE ?', ['%' . $userId]));
        $this->assertSame($catalogBefore, $this->catalogHash());
    }

    // --- Aides

    private function switchOn(): void
    {
        static::getContainer()->set(IngredientSeedingFeature::class, new ControlledIngredientSeedingFeature(true));
    }

    /**
     * @return list<string> les slugs des ingrédients « de base » du jeu, triés
     */
    private function sortedBase(): array
    {
        $slugs = SeedingDataset::BASE;
        sort($slugs);

        return $slugs;
    }

    /**
     * Tout ce que l'amorçage écrit pour un compte (ingrédients, alias, conversions), en une somme de contrôle : sans la ligne du compte, dont le statut change.
     */
    private function ingredientStateOf(string $userId): string
    {
        $connection = $this->connection();

        return md5(implode("\n", [
            ...$connection->fetchFirstColumn('SELECT t::text FROM ingredient t WHERE t.creator = ? ORDER BY t.slug', [$userId]),
            ...$connection->fetchFirstColumn('SELECT t::text FROM ingredient_alias t WHERE t.user_id = ? ORDER BY t.alias_slug', [$userId]),
            ...$connection->fetchFirstColumn('SELECT t::text FROM conversion t WHERE t.ingredient IN (SELECT slug FROM ingredient WHERE creator = ?) ORDER BY t.id', [$userId]),
        ]));
    }

    private function savedPendingUser(string $email): string
    {
        $user = new UserBuilder()->withEmail($email)->isPending()->build();
        new DataPersister(static::getContainer())->saveUser($user);

        return $user->getId();
    }

    private function issueVerificationToken(string $userId): string
    {
        $secret = bin2hex(random_bytes(16));
        static::getContainer()->get(EmailVerificationTokenRepository::class)->add(EmailVerificationToken::issue(UuidGenerator::new(), $userId, EmailVerificationToken::hash($secret), new DateTimeImmutable()));

        return $secret;
    }

    private function confirmWithRepositoryToken(string $userId): void
    {
        $this->post('/api/v1/users/email-verification/confirm', ['token' => $this->issueVerificationToken($userId)]);
        $this->assertSame(204, $this->statusCode());
    }

    private function userId(string $email): string
    {
        return (string) $this->connection()->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
    }

    private function statusOf(string $userId): string
    {
        return (string) $this->connection()->fetchOne('SELECT status FROM "user" WHERE id = ?', [$userId]);
    }

    private function login(string $email): string
    {
        $this->post('/api/login_check', ['email' => $email, 'password' => $email === 'alexiane@example.com' ? self::PASSWORD : 'test']);
        $this->assertSame(200, $this->statusCode(), $email);

        return $this->jsonBody()['token'];
    }

    private function changeStatus(string $userId, string $status, string $jwt): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/users/' . $userId . '/status',
            content: json_encode(['status' => $status], JSON_THROW_ON_ERROR),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );
    }

    private function get(string $uri, string $jwt): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, content: json_encode($body, JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
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
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function secretOf(Email $email): string
    {
        $this->assertSame(1, preg_match('#^https?://\S+/confirmation-email\?token=([0-9a-f]{64})$#m', $email->getTextBody(), $matches));

        return $matches[1];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
