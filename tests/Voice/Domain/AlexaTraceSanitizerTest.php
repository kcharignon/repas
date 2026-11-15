<?php

namespace Repas\Tests\Voice\Domain;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\VoiceTrace;
use Repas\Voice\Domain\Service\AlexaTraceSanitizer;
use stdClass;

/**
 * Ce que la trace des échanges avec Alexa a le droit de garder (ADR 0029 décision 14) : une LISTE BLANCHE. Aucun jeton, aucun identifiant d'utilisateur
 * ou d'appareil n'en sort, quels que soient les champs que le corps porte ; les textes, la profondeur et la taille sont bornés.
 */
class AlexaTraceSanitizerTest extends TestCase
{
    private const string ACCESS_TOKEN = 'FACTICE-ACCESS-TOKEN-A-NE-JAMAIS-GARDER';
    private const string API_ACCESS_TOKEN = 'FACTICE-API-ACCESS-TOKEN-A-NE-JAMAIS-GARDER';
    private const string SIGNATURE = 'FACTICE-SIGNATURE-256-A-NE-JAMAIS-GARDER';
    private const string DEVICE_ID = 'FACTICE-DEVICE-ID-A-NE-JAMAIS-GARDER';

    private AlexaTraceSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new AlexaTraceSanitizer();
    }

    public function testARequestKeepsTheIntentTheHeardSlotsAndWhyAnEntityWasNotRecognized(): void
    {
        // Given : « divers » dit à la question du rayon, qu'Amazon n'a pas rapproché du rayon `divers`
        $body = $this->decoded(AlexaTestRequest::answerDepartment('divers', null)->at(new DateTimeImmutable('2026-11-15 10:00:00 UTC')));

        // When
        $traced = $this->sanitizer->request($body);

        // Then : le type, l'intention, la séance neuve, et ce qui explique la non-reconnaissance (code de la résolution d'entité)
        $this->assertSame(['IntentRequest', 'AnswerDepartmentIntent', true], [$traced->type, $traced->intent, $traced->sessionNew]);
        $this->assertSame([
            'request' => [
                'type' => 'IntentRequest',
                'requestId' => 'amzn1.echo-api.request.00000000-test',
                'timestamp' => '2026-11-15T10:00:00Z',
                'locale' => 'fr-FR',
                'intent' => [
                    'name' => 'AnswerDepartmentIntent',
                    'confirmationStatus' => 'NONE',
                    'slots' => ['department' => [
                        'name' => 'department',
                        'value' => 'divers',
                        'resolutions' => ['resolutionsPerAuthority' => [['authority' => 'DEPARTMENT', 'status' => ['code' => 'ER_SUCCESS_NO_MATCH']]]],
                    ]],
                ],
            ],
            'session' => ['new' => true, 'sessionId' => 'amzn1.echo-api.session.00000000-test'],
        ], $traced->payload);
    }

    public function testAMatchedSlotKeepsTheValuesAmazonResolvedItTo(): void
    {
        // Given
        $body = $this->decoded(AlexaTestRequest::answerDepartment('fruits et légumes', 'fruit-et-legume'));

        // When
        $slot = $this->sanitizer->request($body)->payload['request']['intent']['slots']['department'];

        // Then
        $this->assertSame([
            'resolutionsPerAuthority' => [[
                'authority' => 'DEPARTMENT',
                'status' => ['code' => 'ER_SUCCESS_MATCH'],
                'values' => [['value' => ['name' => 'fruits et légumes', 'id' => 'fruit-et-legume']]],
            ]],
        ], $slot['resolutions']);
    }

    public function testNoTokenNorIdentifierOfTheUserNorOfTheDeviceNorSignatureLeavesATrace(): void
    {
        // Given : un corps qui porte tout ce qui ne doit jamais être gardé, aux endroits où Amazon l'écrit
        $body = $this->decoded(AlexaTestRequest::addItem('du pain')->withAccessToken(self::ACCESS_TOKEN)->withAlexaUserId('amzn1.ask.account.FACTICE-USER-A-NE-JAMAIS-GARDER'));
        $body['context']['System']['apiAccessToken'] = self::API_ACCESS_TOKEN;
        $body['context']['System']['apiEndpoint'] = 'https://api.eu.amazonalexa.com';
        $body['context']['System']['device'] = ['deviceId' => self::DEVICE_ID, 'supportedInterfaces' => []];
        $body['context']['System']['user']['permissions']['consentToken'] = 'FACTICE-CONSENT-A-NE-JAMAIS-GARDER';
        $body['session']['user']['accessToken'] = self::ACCESS_TOKEN;
        $body['Signature-256'] = self::SIGNATURE;
        $body['SignatureCertChainUrl'] = 'https://s3.amazonaws.com/echo.api/echo-api-cert-FACTICE-A-NE-JAMAIS-GARDER.pem';
        $body['request']['apiAccessToken'] = self::API_ACCESS_TOKEN;
        $body['request']['intent']['slots']['item']['accessToken'] = self::ACCESS_TOKEN;

        // When
        $traced = $this->sanitizer->request($body);

        // Then : la requête assainie n'en cite rien, ni en clair ni sous une autre clé
        $written = (string) json_encode($traced, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([self::ACCESS_TOKEN, self::API_ACCESS_TOKEN, self::SIGNATURE, self::DEVICE_ID, 'FACTICE', 'api.eu.amazonalexa.com', 'accessToken', 'userId', 'deviceId', 'applicationId', 'echo-api-cert', 'amzn1.ask.skill', 'amzn1.ask.account'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $written, $forbidden);
        }
        // … et ce qui est utile y est : la phrase dite
        $this->assertSame('du pain', $traced->payload['request']['intent']['slots']['item']['value']);
    }

    public function testTheSessionKeepsTheDialogueStateTheAnswerToItNeeds(): void
    {
        // Given : la séance porte l'état du dialogue (la phrase entendue, les propositions)
        $state = ['step' => 'department', 'phrase' => 'ciboulette', 'options' => [['slug' => 'persil', 'name' => 'Persil']], 'name' => 'ciboulette', 'misses' => 0];
        $body = $this->decoded(AlexaTestRequest::answerDepartment('divers', null)->withSessionAttributes(['dialogue' => $state]));

        // When
        $traced = $this->sanitizer->request($body);

        // Then : la séance est ouverte et l'état est gardé tel quel
        $this->assertFalse($traced->sessionNew);
        $this->assertSame(['dialogue' => $state], $traced->payload['session']['attributes']);
    }

    public function testASessionEndedRequestKeepsItsReasonAndItsError(): void
    {
        // Given
        $body = ['version' => '1.0', 'request' => ['type' => 'SessionEndedRequest', 'reason' => 'ERROR', 'error' => ['type' => 'INVALID_RESPONSE', 'message' => 'Réponse invalide', 'extra' => 'ignoré'], 'locale' => 'fr-FR']];

        // When
        $traced = $this->sanitizer->request($body);

        // Then
        $this->assertSame(['SessionEndedRequest', null, null], [$traced->type, $traced->intent, $traced->sessionNew]);
        $this->assertSame(['request' => ['type' => 'SessionEndedRequest', 'locale' => 'fr-FR', 'reason' => 'ERROR', 'error' => ['type' => 'INVALID_RESPONSE', 'message' => 'Réponse invalide']]], $traced->payload);
    }

    public function testAnythingThatIsNotAnObjectGivesAnEmptyTrace(): void
    {
        foreach ([null, 'texte', 12, [], ['request' => 'pas un objet', 'session' => 7]] as $decoded) {
            // When
            $traced = $this->sanitizer->request($decoded);

            // Then
            $this->assertSame([null, null, null, []], [$traced->type, $traced->intent, $traced->sessionNew, $traced->payload], (string) json_encode($decoded));
        }
    }

    public function testEveryTextIsCutAtFiveHundredCharactersWithoutBreakingAnAccent(): void
    {
        // Given : une phrase de 800 caractères accentués, et un caractère nul que le JSONB ne saurait stocker
        $long = str_repeat('é', 800);
        $body = $this->decoded(AlexaTestRequest::addItem($long));
        $body['request']['locale'] = "fr\0-FR";

        // When
        $traced = $this->sanitizer->request($body);

        // Then
        $this->assertSame(VoiceTrace::MAX_TEXT_LENGTH, mb_strlen($traced->payload['request']['intent']['slots']['item']['value']));
        $this->assertSame(str_repeat('é', VoiceTrace::MAX_TEXT_LENGTH), $traced->payload['request']['intent']['slots']['item']['value']);
        $this->assertSame('fr-FR', $traced->payload['request']['locale']);
    }

    public function testTheAttributesOfTheSessionAreBoundedInDepthAndInNumberOfEntries(): void
    {
        // Given : un arbre de 12 niveaux et un tableau de 80 entrées
        $deep = 'fond';
        for ($level = 0; $level < 12; $level++) {
            $deep = ['niveau' => $deep];
        }
        $body = $this->decoded(AlexaTestRequest::yes()->withSessionAttributes(['profond' => $deep, 'large' => range(1, 80)]));

        // When
        $attributes = $this->sanitizer->request($body)->payload['session']['attributes'];

        // Then : la profondeur est coupée par un marqueur, le tableau garde ses 50 premières entrées
        $this->assertCount(50, $attributes['large']);
        $this->assertSame(50, $attributes['large'][49]);
        $this->assertStringNotContainsString('fond"', (string) json_encode($attributes['profond']));
        $this->assertStringContainsString('…', (string) json_encode($attributes['profond'], JSON_UNESCAPED_UNICODE));
    }

    public function testAJsonThatIsTooBigKeepsWhatFitsInOrderAndSaysItWasCut(): void
    {
        // Given : des attributs de séance énormes (20 tableaux de 50 textes de 500 caractères)
        $huge = [];
        for ($key = 0; $key < 20; $key++) {
            $huge['bloc' . $key] = array_fill(0, 50, str_repeat('x', 500));
        }
        $body = $this->decoded(AlexaTestRequest::addItem('du pain')->withSessionAttributes($huge));

        // When
        $traced = $this->sanitizer->request($body);

        // Then : tient dans la taille permise, garde la requête en entier et le début des attributs, et le marqueur le dit
        $encoded = (string) json_encode($traced->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertLessThanOrEqual(VoiceTrace::MAX_JSON_BYTES, strlen($encoded));
        $this->assertTrue($traced->payload['truncated']);
        $this->assertSame('du pain', $traced->payload['request']['intent']['slots']['item']['value']);
        $this->assertArrayHasKey('bloc0', $traced->payload['session']['attributes']);
        $this->assertArrayNotHasKey('bloc19', $traced->payload['session']['attributes']);
        $this->assertSame(['IntentRequest', 'AddItemIntent'], [$traced->type, $traced->intent], 'le type et l\'intention sont lus avant toute réduction');
    }

    public function testASmallJsonIsNeverMarkedAsCut(): void
    {
        // When
        $traced = $this->sanitizer->request($this->decoded(AlexaTestRequest::addItem('du pain')));

        // Then
        $this->assertArrayNotHasKey('truncated', $traced->payload);
    }

    public function testTheResponseIsCopiedBoundedWithItsEmptyObjects(): void
    {
        // Given : la réponse d'une séance ouverte (`sessionAttributes` est un objet vide) et un texte trop long
        $response = ['version' => '1.0', 'sessionAttributes' => new stdClass(), 'response' => [
            'outputSpeech' => ['type' => 'PlainText', 'text' => str_repeat('a', 700)],
            'shouldEndSession' => false,
        ]];

        // When
        $copy = $this->sanitizer->response($response);

        // Then
        $this->assertSame([], $copy['sessionAttributes']);
        $this->assertSame(VoiceTrace::MAX_TEXT_LENGTH, mb_strlen($copy['response']['outputSpeech']['text']));
        $this->assertFalse($copy['response']['shouldEndSession']);
    }

    public function testTheResponseOfALinkAccountCardKeepsTheCard(): void
    {
        // Given
        $response = ['version' => '1.0', 'response' => ['outputSpeech' => ['type' => 'PlainText', 'text' => 'Lie ton compte'], 'card' => ['type' => 'LinkAccount'], 'shouldEndSession' => true]];

        // Then
        $this->assertSame($response, $this->sanitizer->response($response));
    }

    /**
     * Le corps tel que le webhook le décode : le JSON d'Amazon, relu (`{}` devient `[]`).
     *
     * @return array<string, mixed>
     */
    private function decoded(AlexaTestRequest $request): array
    {
        return json_decode($request->toJson(), true, flags: JSON_THROW_ON_ERROR);
    }
}
