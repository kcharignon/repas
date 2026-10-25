<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestRequest;
use Repas\Voice\Domain\Model\AlexaRequest;
use Repas\Voice\Domain\Model\AlexaResponse;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\SpokenDialogueState;

/**
 * La forme JSON de la réponse d'un skill personnalisé (VOX-204) : `version`, `response.outputSpeech` (`PlainText`), `reprompt`, `card`,
 * `shouldEndSession`.
 */
class AlexaResponseTest extends TestCase
{
    public function testSayingEndsTheSession(): void
    {
        // When
        $json = $this->json(AlexaResponse::say('À bientôt.'));

        // Then
        $this->assertSame(['version' => '1.0', 'response' => [
            'outputSpeech' => ['type' => 'PlainText', 'text' => 'À bientôt.'],
            'shouldEndSession' => true,
        ]], $json);
    }

    public function testAskingKeepsTheSessionOpenWithAReprompt(): void
    {
        // When
        $json = $this->json(AlexaResponse::ask('Que veux-tu ajouter ?', 'Dis un article.'));

        // Then
        $this->assertSame(['version' => '1.0', 'sessionAttributes' => [], 'response' => [
            'outputSpeech' => ['type' => 'PlainText', 'text' => 'Que veux-tu ajouter ?'],
            'reprompt' => ['outputSpeech' => ['type' => 'PlainText', 'text' => 'Dis un article.']],
            'shouldEndSession' => false,
        ]], $json);
    }

    public function testLinkingTheAccountAddsTheCardAndEndsTheSession(): void
    {
        // When
        $json = $this->json(AlexaResponse::linkAccount('Lie ton compte.'));

        // Then
        $this->assertSame(['version' => '1.0', 'response' => [
            'outputSpeech' => ['type' => 'PlainText', 'text' => 'Lie ton compte.'],
            'card' => ['type' => 'LinkAccount'],
            'shouldEndSession' => true,
        ]], $json);
    }

    public function testSilenceIsAnEmptyResponseObjectNotAnEmptyList(): void
    {
        // When
        $encoded = json_encode(AlexaResponse::silence()->toArray(), JSON_THROW_ON_ERROR);

        // Then : `"response":{}` et non `"response":[]`, sans parole
        $this->assertSame('{"version":"1.0","response":{}}', $encoded);
    }

    public function testReplyKeepsTheSessionOpenOrClosesIt(): void
    {
        $this->assertTrue($this->json(AlexaResponse::reply('Oui.', true, 'Dis.'))['response']['shouldEndSession'] === false);
        $this->assertArrayHasKey('reprompt', $this->json(AlexaResponse::reply('Oui.', true, 'Dis.'))['response']);
        $this->assertTrue($this->json(AlexaResponse::reply('Oui.', false, 'Dis.'))['response']['shouldEndSession']);
        $this->assertArrayNotHasKey('reprompt', $this->json(AlexaResponse::reply('Oui.', false, 'Dis.'))['response']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(AlexaResponse $response): array
    {
        return json_decode(json_encode($response->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testAnOpenSessionAlwaysWritesSessionAttributesAsAnObjectEvenWhenThereIsNoDialogue(): void
    {
        // When
        $encoded = json_encode(AlexaResponse::ask('Que veux-tu ajouter ?', 'Dis un article.')->toArray(), JSON_THROW_ON_ERROR);

        // Then : `{}` et non `[]` : sans la clé, ou avec une liste vide, Alexa oublierait ou refuserait l'état
        $this->assertStringContainsString('"sessionAttributes":{}', $encoded);
    }

    public function testTheDialogueInProgressIsWrittenInTheSessionAttributes(): void
    {
        // Given
        $state = SpokenDialogueState::choose('de la ciboulette', [new DialogueOption('persil-u', 'persil')], 'ciboulette');

        // When
        $json = $this->json(AlexaResponse::ask('Tu veux dire persil ?', 'Tu veux dire persil ?', $state));

        // Then
        $this->assertSame(['dialogue' => [
            'step' => 'choose',
            'phrase' => 'de la ciboulette',
            'options' => [['slug' => 'persil-u', 'name' => 'persil']],
            'name' => 'ciboulette',
            'misses' => 0,
        ]], $json['sessionAttributes']);
        $this->assertFalse($json['response']['shouldEndSession']);
    }

    public function testTheAttributesWrittenAreTheOnesTheNextRequestReads(): void
    {
        // Given : un état à l'étape de la confirmation
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette')
            ->askingDepartment()
            ->withDepartment(new DialogueOption('fruit-et-legume', 'fruit & légume'))
            ->withUnit(new DialogueOption('unite', 'unité'));

        // When : on les écrit, Alexa les rend telles quelles
        $written = $this->json(AlexaResponse::ask('Je crée ciboulette. C\'est bon ?', 'C\'est bon ?', $state))['sessionAttributes'];
        $read = AlexaRequest::fromDecoded(AlexaTestRequest::yes()->withSessionAttributes($written)->toArray());

        // Then
        $this->assertEquals($state, $read->dialogueState);
    }

    public function testAClosedSessionHasNoSessionAttributes(): void
    {
        $this->assertArrayNotHasKey('sessionAttributes', $this->json(AlexaResponse::say('À bientôt.')));
        $this->assertArrayNotHasKey('sessionAttributes', $this->json(AlexaResponse::linkAccount('Lie ton compte.')));
        $this->assertArrayNotHasKey('sessionAttributes', $this->json(AlexaResponse::silence()));
        $this->assertArrayNotHasKey('sessionAttributes', $this->json(AlexaResponse::reply('Oui.', false, 'Dis.', SpokenDialogueState::choose('x', [], 'x'))));
    }

    public function testReplyCarriesTheDialogueWhenTheSessionStaysOpen(): void
    {
        // Given
        $state = SpokenDialogueState::choose('de la ciboulette', [], 'ciboulette');

        // When
        $json = $this->json(AlexaResponse::reply('Je le crée ?', true, 'Je le crée ?', $state));

        // Then
        $this->assertSame('choose', $json['sessionAttributes']['dialogue']['step']);
    }
}
