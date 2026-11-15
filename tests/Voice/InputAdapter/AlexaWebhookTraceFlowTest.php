<?php

namespace Repas\Tests\Voice\InputAdapter;


use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Repas\Tests\Helper\Alexa\AlexaTestAuthority;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Tests\Helper\Alexa\AlexaTestLeaf;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Tests\Helper\Alexa\ChainFetcherDouble;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\ControlledVoiceTraceFeature;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;
use Repas\Voice\Domain\Interface\AccessGrantRepository;
use Repas\Voice\Domain\Interface\AlexaRequestVerifier;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Interface\VoiceTraceFeature;
use Repas\Voice\Domain\Model\AccessGrant;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Infrastructure\Security\OpenSslAlexaRequestVerifier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;

/**
 * La trace des échanges avec Alexa de bout en bout (ADR 0029 décision 14) : le VRAI vérificateur avec de VRAIES signatures, les vrais bus, la vraie base. Interrupteur
 * allumé, l'échange est gardé (requête assainie, réponse, compte) ; coupé, la base n'est même pas interrogée ; une requête qui n'est pas d'Amazon n'est jamais gardée.
 */
class AlexaWebhookTraceFlowTest extends DatabaseTestCase
{
    private const string URL = '/api/voice/alexa';
    private const string SEED = 'trace';

    private static AlexaTestAuthority $authority;
    private static AlexaTestLeaf $leaf;

    private KernelBrowser $client;
    private DataPersister $persister;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$authority = AlexaTestAuthority::root();
        self::$leaf = self::$authority->issue();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::getContainer()->get('test.client');
        $this->client->disableReboot();
        static::getContainer()->set(AlexaRequestVerifier::class, new OpenSslAlexaRequestVerifier(
            new ChainFetcherDouble(self::$leaf->chainPem()),
            new class implements ClockInterface {
                public function now(): DatePoint
                {
                    return DatePoint::createFromInterface(new DateTimeImmutable());
                }

                public function sleep(float|int $seconds): void
                {
                }

                public function withTimeZone(DateTimeZone|string $timezone): static
                {
                    return $this;
                }
            },
            new NullLogger(),
            AlexaTestRequest::SKILL_ID,
            [self::$authority->trustFile()],
        ));
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testTheSwitchOffTouchesNoTraceTable(): void
    {
        // Given : un utilisateur lié, son pain ; la trace coupée (l'état de test, comme celui de la production par défaut)
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($user, true)->build());
        $counter = static::getContainer()->get(QueryCounter::class);

        // When : « Alexa, demande à Jarvis d'ajouter du pain »
        $counter->reset();
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then : la réponse est celle d'habitude, la table n'est ni écrite ni même nommée dans une requête
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame("J'ai commencé une nouvelle liste avec pain.", $this->speech());
        $this->assertSame([], array_values(array_filter($counter->statements(), static fn(string $sql): bool => str_contains($sql, 'voice_trace'))));
        $this->assertSame(0, $this->traces());
    }

    public function testTheSwitchOnKeepsTheExchangeWithoutAnyToken(): void
    {
        // Given : la trace allumée, un utilisateur lié, son pain
        static::getContainer()->set(VoiceTraceFeature::class, new ControlledVoiceTraceFeature(true));
        $user = $this->linkedUser();
        $this->persister->saveIngredient(new IngredientBuilder()->isBread()->withCreator($user, true)->build());
        $counter = static::getContainer()->get(QueryCounter::class);

        // When : « Alexa, demande à Jarvis d'ajouter du pain »
        $counter->reset();
        $this->say(AlexaTestRequest::addItem('du pain'));

        // Then : deux instructions de plus (l'insertion et la borne), et une ligne
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertCount(2, array_filter($counter->statements(), static fn(string $sql): bool => str_contains($sql, 'voice_trace')));
        $this->assertSame(1, $this->traces());
        $row = $this->connection()->fetchAssociative('SELECT * FROM voice_trace');
        $this->assertSame([$user->getId(), 'link-' . self::SEED, 'IntentRequest', 'AddItemIntent', 'ANSWERED', 'AddSpokenItem'], [$row['user_id'], $row['link_id'], $row['request_type'], $row['intent'], $row['outcome'], $row['decision']]);
        $this->assertSame('du pain', json_decode($row['request'], true)['request']['intent']['slots']['item']['value']);
        $this->assertSame($this->speech(), json_decode($row['response'], true)['response']['outputSpeech']['text']);

        // Then : ni le jeton d'accès, ni l'identifiant d'utilisateur Alexa, ni celui du skill ne sont dans la table
        $everything = (string) json_encode($this->connection()->fetchAllAssociative('SELECT * FROM voice_trace'));
        $this->assertStringNotContainsString($this->tokenOf(self::SEED), $everything);
        $this->assertStringNotContainsString(AlexaTestRequest::USER_ID, $everything);
        $this->assertStringNotContainsString(AlexaTestRequest::SKILL_ID, $everything);
    }

    public function testAnUnlinkedRequestIsKeptWithoutAccountAndNothingLeaksFromOneRequestToTheNext(): void
    {
        // Given : la trace allumée, un utilisateur lié
        static::getContainer()->set(VoiceTraceFeature::class, new ControlledVoiceTraceFeature(true));
        $this->linkedUser();

        // When : d'abord une demande liée, puis une sans jeton (le même controller, le même noyau)
        $this->say(AlexaTestRequest::launch());
        $this->signedPost(AlexaTestRequest::addItem('du pain')->withAccessToken(null));

        // Then : la deuxième garde la carte de liaison, sans compte ni décision de la première
        $rows = $this->connection()->fetchAllAssociative('SELECT user_id, link_id, outcome, decision FROM voice_trace ORDER BY id');
        $this->assertCount(2, $rows);
        $this->assertSame(['ANSWERED', 'AlexaDialogue'], [$rows[0]['outcome'], $rows[0]['decision']]);
        $this->assertSame([null, null, 'ACCOUNT_NOT_LINKED', null], [$rows[1]['user_id'], $rows[1]['link_id'], $rows[1]['outcome'], $rows[1]['decision']]);
    }

    public function testARequestThatDoesNotProveToComeFromAmazonIsNeverKept(): void
    {
        // Given
        static::getContainer()->set(VoiceTraceFeature::class, new ControlledVoiceTraceFeature(true));
        $this->linkedUser();
        $body = AlexaTestRequest::addItem('du pain')->withAccessToken($this->tokenOf(self::SEED))->toJson();

        // When : une signature qui n'est pas celle du corps
        $this->postRaw($body, AlexaTestRequest::CERT_CHAIN_URL, self::$leaf->sign($body . ' modifié'));

        // Then : 400 et rien n'est gardé
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->assertSame(0, $this->traces());
    }

    private function linkedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);
        static::getContainer()->get(VoiceLinkRepository::class)->save(VoiceLink::load([
            'id' => 'link-' . self::SEED,
            'user_id' => $user->getId(),
            'alexa_user_id' => null,
            'created_at' => new DateTimeImmutable('-1 day'),
            'last_used_at' => null,
            'revoked_at' => null,
        ]));
        static::getContainer()->get(AccessGrantRepository::class)->add(AccessGrant::issue(
            OpaqueToken::hash($this->tokenOf(self::SEED)),
            OpaqueToken::hash(AlexaTestClient::token('refresh-' . self::SEED)),
            'link-' . self::SEED,
            $user->getId(),
            new DateTimeImmutable(),
        ));

        return $user;
    }

    private function tokenOf(string $seed): string
    {
        return AlexaTestClient::token("access-$seed");
    }

    private function say(AlexaTestRequest $request): void
    {
        $this->signedPost($request->withAccessToken($this->tokenOf(self::SEED))->withAlexaUserId(AlexaTestRequest::USER_ID));
    }

    private function signedPost(AlexaTestRequest $request): void
    {
        $body = $request->toJson();

        $this->postRaw($body, AlexaTestRequest::CERT_CHAIN_URL, self::$leaf->sign($body));
    }

    private function postRaw(string $body, string $certChainUrl, string $signature): void
    {
        $this->client->request('POST', self::URL, content: $body, server: [
            'CONTENT_TYPE' => 'application/json;charset=UTF-8',
            'HTTP_SIGNATURECERTCHAINURL' => $certChainUrl,
            'HTTP_SIGNATURE_256' => $signature,
            'REMOTE_ADDR' => '203.0.113.' . random_int(1, 254),
        ]);
    }

    private function speech(): string
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['response']['outputSpeech']['text'];
    }

    private function traces(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM voice_trace');
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
