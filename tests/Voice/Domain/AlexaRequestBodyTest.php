<?php

namespace Repas\Tests\Voice\Domain;


use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\AlexaRequestBody;

/**
 * Ce que la vérification lit dans le corps d'Alexa : l'horodatage (à 150 s) et l'identifiant du skill, aux deux endroits où Alexa
 * l'écrit. Les modèles de corps sont des requêtes synthétiques (aucun identifiant réel).
 */
class AlexaRequestBodyTest extends TestCase
{
    private const string SKILL = 'amzn1.ask.skill.00000000-test-0000-0000-000000000001';
    private const string NOW = '2026-10-25T10:00:00Z';

    private static function json(string $timestamp = self::NOW, ?string $sessionApplication = self::SKILL, ?string $contextApplication = self::SKILL): string
    {
        $data = ['version' => '1.0', 'request' => ['type' => 'LaunchRequest', 'timestamp' => $timestamp]];
        if ($sessionApplication !== null) {
            $data['session'] = ['application' => ['applicationId' => $sessionApplication]];
        }
        if ($contextApplication !== null) {
            $data['context'] = ['System' => ['application' => ['applicationId' => $contextApplication]]];
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /**
     * @return iterable<string, array{string, bool}> l'écart de l'horloge avec l'horodatage de la requête, et si elle est fraîche
     */
    public static function clockOffsets(): iterable
    {
        yield 'même seconde' => ['2026-10-25T10:00:00Z', true];
        yield '149 s après la requête' => ['2026-10-25T10:02:29Z', true];
        yield '150 s après la requête' => ['2026-10-25T10:02:30Z', true];
        yield '150,5 s après la requête' => ['2026-10-25T10:02:30.5Z', false];
        yield '151 s après la requête' => ['2026-10-25T10:02:31Z', false];
        yield '149 s avant la requête' => ['2026-10-25T09:57:31Z', true];
        yield '150 s avant la requête' => ['2026-10-25T09:57:30Z', true];
        yield '150,000001 s avant la requête' => ['2026-10-25T09:57:29.999999Z', false];
        yield '151 s avant la requête' => ['2026-10-25T09:57:29Z', false];
        yield 'une heure après' => ['2026-10-25T11:00:00Z', false];
        yield 'la veille' => ['2026-10-24T10:00:00Z', false];
    }

    #[DataProvider('clockOffsets')]
    public function testTheRequestMustBeWithin150SecondsOfTheClock(string $clock, bool $fresh): void
    {
        // Given
        $body = AlexaRequestBody::tryFrom(self::json());

        // When / Then
        $this->assertNotNull($body);
        $this->assertSame($fresh, $body->isFreshAt(self::at($clock)));
    }

    public function testAFractionOfASecondAndAnOffsetAreUnderstood(): void
    {
        // Given : 10:00:00,250 UTC écrit à l'heure d'été de Paris
        $body = AlexaRequestBody::tryFrom(self::json('2026-10-25T11:00:00.250+01:00'));

        // When / Then
        $this->assertNotNull($body);
        $this->assertTrue($body->isFreshAt(self::at('2026-10-25T10:02:30.250Z')));
        $this->assertFalse($body->isFreshAt(self::at('2026-10-25T10:02:30.251Z')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableBodies(): iterable
    {
        yield 'vide' => [''];
        yield 'pas du JSON' => ['not json'];
        yield 'JSON tronqué' => ['{"request": {"timestamp": "2026-10-25T10:00:00Z"'];
        yield 'un objet vide' => ['{}'];
        yield 'une liste' => ['[]'];
        yield 'une chaîne' => ['"text"'];
        yield 'un nombre' => ['123'];
        yield 'null' => ['null'];
        yield 'sans request' => [json_encode(['session' => ['application' => ['applicationId' => self::SKILL]]], JSON_THROW_ON_ERROR)];
        yield 'request qui n\'est pas un objet' => [json_encode(['request' => 'x', 'session' => ['application' => ['applicationId' => self::SKILL]]], JSON_THROW_ON_ERROR)];
        yield 'sans horodatage' => [json_encode(['request' => ['type' => 'LaunchRequest'], 'session' => ['application' => ['applicationId' => self::SKILL]]], JSON_THROW_ON_ERROR)];
        yield 'horodatage nul' => [json_encode(['request' => ['timestamp' => null], 'session' => ['application' => ['applicationId' => self::SKILL]]], JSON_THROW_ON_ERROR)];
        yield 'horodatage numérique' => [json_encode(['request' => ['timestamp' => 1793008800], 'session' => ['application' => ['applicationId' => self::SKILL]]], JSON_THROW_ON_ERROR)];
        yield 'horodatage sans fuseau' => [self::json('2026-10-25T10:00:00')];
        yield 'horodatage sans heure' => [self::json('2026-10-25')];
        yield 'horodatage avec espace' => [self::json('2026-10-25 10:00:00Z')];
        yield 'horodatage en minuscules' => [self::json('2026-10-25t10:00:00z')];
        yield 'horodatage de texte' => [self::json('tomorrow')];
        yield 'horodatage relatif' => [self::json('+1 day')];
        yield 'horodatage avec ordures' => [self::json('2026-10-25T10:00:00Z garbage')];
        yield 'jour qui n\'existe pas' => [self::json('2026-02-31T10:00:00Z')];
        yield 'mois 13' => [self::json('2026-13-01T10:00:00Z')];
        yield 'heure 25' => [self::json('2026-10-25T25:00:00Z')];
        yield 'fraction trop fine' => [self::json('2026-10-25T10:00:00.1234567Z')];
        yield 'aucun identifiant de skill' => [self::json(sessionApplication: null, contextApplication: null)];
        yield 'identifiant vide' => [self::json(sessionApplication: '', contextApplication: null)];
        yield 'identifiant qui n\'est pas une chaîne' => [json_encode(['request' => ['timestamp' => self::NOW], 'session' => ['application' => ['applicationId' => ['x']]]], JSON_THROW_ON_ERROR)];
        yield 'application qui n\'est pas un objet' => [json_encode(['request' => ['timestamp' => self::NOW], 'session' => ['application' => 'x'], 'context' => ['System' => ['application' => ['applicationId' => self::SKILL]]]], JSON_THROW_ON_ERROR)];
        yield 'session nulle' => [json_encode(['request' => ['timestamp' => self::NOW], 'session' => null, 'context' => ['System' => ['application' => ['applicationId' => self::SKILL]]]], JSON_THROW_ON_ERROR)];
        yield 'trop profond' => [str_repeat('{"a":', 40) . '1' . str_repeat('}', 40)];
    }

    #[DataProvider('unreadableBodies')]
    public function testABodyThatIsNotACompleteAlexaRequestIsRefused(string $rawBody): void
    {
        // When / Then
        $this->assertNull(AlexaRequestBody::tryFrom($rawBody));
    }

    public function testTheSkillIdentifierIsReadInTheSessionOrInTheContext(): void
    {
        // Given
        $inSession = AlexaRequestBody::tryFrom(self::json(contextApplication: null));
        $inContext = AlexaRequestBody::tryFrom(self::json(sessionApplication: null));
        $inBoth = AlexaRequestBody::tryFrom(self::json());

        // When / Then
        foreach ([$inSession, $inContext, $inBoth] as $body) {
            $this->assertNotNull($body);
            $this->assertTrue($body->isAddressedTo(self::SKILL));
            $this->assertFalse($body->isAddressedTo('amzn1.ask.skill.other'));
        }
    }

    public function testEveryIdentifierMustBeOurSkill(): void
    {
        // Given : le contexte dit notre skill, la session un autre (ou l'inverse)
        $sessionOther = AlexaRequestBody::tryFrom(self::json(sessionApplication: 'amzn1.ask.skill.other'));
        $contextOther = AlexaRequestBody::tryFrom(self::json(contextApplication: 'amzn1.ask.skill.other'));

        // When / Then
        $this->assertNotNull($sessionOther);
        $this->assertNotNull($contextOther);
        $this->assertFalse($sessionOther->isAddressedTo(self::SKILL));
        $this->assertFalse($contextOther->isAddressedTo(self::SKILL));
    }

    public function testAnEmptySkillIdentifierIsNeverOurSkill(): void
    {
        // Given
        $body = AlexaRequestBody::tryFrom(self::json());

        // When / Then
        $this->assertNotNull($body);
        $this->assertFalse($body->isAddressedTo(''));
    }

    public function testTheIdentifierIsComparedExactly(): void
    {
        // Given
        $body = AlexaRequestBody::tryFrom(self::json());

        // When / Then
        $this->assertNotNull($body);
        $this->assertFalse($body->isAddressedTo(strtoupper(self::SKILL)));
        $this->assertFalse($body->isAddressedTo(self::SKILL . ' '));
        $this->assertFalse($body->isAddressedTo(substr(self::SKILL, 0, -1)));
    }
}
