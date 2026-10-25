<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaIntent;
use Repas\Voice\Domain\Model\AlexaRequest;

/**
 * Ce que le webhook lit d'une requête d'Alexa déjà vérifiée (VOX-204) : lecture tolérante, jamais d'exception.
 */
class AlexaRequestTest extends TestCase
{
    public function testAnIntentRequestIsReadWithItsPhraseLocaleSessionTokenAndUser(): void
    {
        // Given
        $body = AlexaTestRequest::addItem('des tomates')->withAccessToken('the-token')->withAlexaUserId('amzn1.ask.account.ME')->toArray();

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertSame(AlexaRequest::INTENT, $request->type);
        $this->assertSame(AlexaIntent::ADD_ITEM, $request->intent());
        $this->assertSame('des tomates', $request->phrase);
        $this->assertTrue($request->hasSupportedLocale());
        $this->assertTrue($request->isFirstOfSession);
        $this->assertFalse($request->isInOpenSession());
        $this->assertSame('the-token', $request->accessToken);
        $this->assertSame('amzn1.ask.account.ME', $request->alexaUserId);
    }

    public function testASessionThatIsNotNewIsOpen(): void
    {
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addItem()->inOpenSession()->toArray());

        $this->assertTrue($request->isInOpenSession());
    }

    #[DataProvider('sessionsThatAreNotExplicitlyOpen')]
    public function testAnythingButAnExplicitFalseIsADirectCall(mixed $new): void
    {
        // Given : `session.new` absent, `true`, ou d'une forme inattendue
        $body = AlexaTestRequest::addItem()->toArray();
        $body['session']['new'] = $new;

        // When / Then : la séance se fermera
        $this->assertFalse(AlexaRequest::fromDecoded($body)->isInOpenSession());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function sessionsThatAreNotExplicitlyOpen(): iterable
    {
        yield 'true' => [true];
        yield 'null' => [null];
        yield 'la chaîne false' => ['false'];
        yield 'zéro' => [0];
    }

    public function testAMissingSessionIsADirectCallAndTheTokenComesFromTheContext(): void
    {
        // Given
        $body = AlexaTestRequest::launch()->withAccessToken('ctx-token')->withTokenInContextOnly()->toArray();

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertArrayNotHasKey('session', $body);
        $this->assertFalse($request->isInOpenSession());
        $this->assertSame('ctx-token', $request->accessToken);
        $this->assertSame(AlexaTestRequest::USER_ID, $request->alexaUserId);
        $this->assertSame(AlexaRequest::LAUNCH, $request->type);
    }

    public function testTheSessionTokenWinsOverTheContextOne(): void
    {
        // Given
        $body = AlexaTestRequest::launch()->withAccessToken('session-token')->toArray();
        $body['context']['System']['user']['accessToken'] = 'context-token';

        // When / Then
        $this->assertSame('session-token', AlexaRequest::fromDecoded($body)->accessToken);
    }

    public function testNoTokenMeansNoToken(): void
    {
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->toArray())->accessToken);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAccessToken('')->toArray())->accessToken);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAccessToken(str_repeat('a', AlexaRequest::MAX_ACCESS_TOKEN_LENGTH + 1))->toArray())->accessToken);
    }

    public function testAnAlexaUserIdThatDoesNotFitTheColumnIsIgnored(): void
    {
        $this->assertSame(str_repeat('a', 255), AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAlexaUserId(str_repeat('a', 255))->toArray())->alexaUserId);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAlexaUserId(str_repeat('a', 256))->toArray())->alexaUserId);
        $this->assertNull(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withAlexaUserId(null)->toArray())->alexaUserId);
    }

    #[DataProvider('phrases')]
    public function testThePhraseIsCleanedOrDropped(?string $said, ?string $expected): void
    {
        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::addItem($said)->toArray());

        // Then
        $this->assertSame($expected, $request->phrase);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function phrases(): iterable
    {
        yield 'telle quelle' => ['du pain de mie', 'du pain de mie'];
        yield 'espaces réduits' => ["  du   pain \t de mie\n", 'du pain de mie'];
        yield 'caractères de contrôle' => ["du\u{0000}pain\u{200B}", 'du pain'];
        yield 'créneau sans valeur' => [null, null];
        yield 'vide' => ['', null];
        yield 'espaces seuls' => ['   ', null];
        yield 'point d\'interrogation d\'Alexa' => ['?', null];
        yield 'ponctuation seule' => ['… !', null];
        yield 'accents' => ['crème fraîche', 'crème fraîche'];
        yield 'chiffres' => ['3', '3'];
        yield 'cent caractères' => [str_repeat('a', 100), str_repeat('a', 100)];
        yield 'plus de cent caractères' => [str_repeat('a', 101), null];
    }

    public function testAPhraseThatIsNotAStringIsDropped(): void
    {
        // Given
        $body = AlexaTestRequest::addItem()->toArray();
        $body['request']['intent']['slots']['item']['value'] = ['du pain'];

        // When / Then
        $this->assertNull(AlexaRequest::fromDecoded($body)->phrase);
    }

    public function testThePhraseIsOnlyReadFromAnIntentRequest(): void
    {
        // Given : un créneau qui traîne dans un lancement
        $body = AlexaTestRequest::launch()->toArray();
        $body['request']['intent'] = ['name' => 'AddItemIntent', 'slots' => ['item' => ['value' => 'du pain']]];

        // When
        $request = AlexaRequest::fromDecoded($body);

        // Then
        $this->assertNull($request->phrase);
        $this->assertNull($request->intentName);
        $this->assertNull($request->intent());
    }

    public function testAnIntentTheSkillDoesNotDeclareIsKnownByNameButHasNoIntent(): void
    {
        // When
        $request = AlexaRequest::fromDecoded(AlexaTestRequest::intent('AMAZON.NavigateHomeIntent')->toArray());

        // Then
        $this->assertSame('AMAZON.NavigateHomeIntent', $request->intentName);
        $this->assertNull($request->intent());
    }

    public function testOnlyFrenchFromFranceIsSupported(): void
    {
        $this->assertTrue(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->toArray())->hasSupportedLocale());
        foreach (['fr-CA', 'en-US', 'fr-fr', 'fr', ''] as $locale) {
            $this->assertFalse(AlexaRequest::fromDecoded(AlexaTestRequest::launch()->withLocale($locale)->toArray())->hasSupportedLocale(), $locale);
        }

        $body = AlexaTestRequest::launch()->toArray();
        unset($body['request']['locale']);
        $this->assertFalse(AlexaRequest::fromDecoded($body)->hasSupportedLocale());
    }

    #[DataProvider('shapesThatAreNotARequest')]
    public function testAnythingStrangeIsReadWithoutAnException(mixed $decoded): void
    {
        // When
        $request = AlexaRequest::fromDecoded($decoded);

        // Then : un type vide, rien d'autre, une séance fermée par défaut
        $this->assertSame('', $request->type);
        $this->assertNull($request->intent());
        $this->assertNull($request->phrase);
        $this->assertNull($request->accessToken);
        $this->assertNull($request->alexaUserId);
        $this->assertFalse($request->hasSupportedLocale());
        $this->assertFalse($request->isInOpenSession());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function shapesThatAreNotARequest(): iterable
    {
        yield 'null' => [null];
        yield 'une chaîne' => ['IntentRequest'];
        yield 'un nombre' => [42];
        yield 'une liste vide' => [[]];
        yield 'request n\'est pas un objet' => [['request' => 'IntentRequest']];
        yield 'type numérique' => [['request' => ['type' => 7]]];
    }
}
