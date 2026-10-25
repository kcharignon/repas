<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\AlexaResponse;

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
        $this->assertSame(['version' => '1.0', 'response' => [
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
}
