<?php

namespace Repas\Tests\Voice\Infrastructure\Http;


use Repas\Voice\Domain\Interface\VoiceFeature;
use Repas\Tests\Helper\ControlledVoiceFeature;
use Repas\Tests\Helper\Http\VoiceProbeController;
use Repas\Tests\Helper\QueryBusWebTestCase;

/**
 * Câblage réel de l'interrupteur de la commande vocale sur des routes d'essai (`VoiceProbeController`, environnement de test) :
 * sécurité, listener `#[RequiresVoice]` et mise en forme de l'erreur par `ApiExceptionListener`.
 */
class VoiceSwitchApiTest extends QueryBusWebTestCase
{
    public function testAMarkedRouteAnswersWhenTheSwitchIsOn(): void
    {
        // Given
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(true));

        // When
        $this->client->request('GET', VoiceProbeController::REQUIRED_PATH, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['probe' => 'voice-required'], $this->jsonBody());
    }

    public function testAMarkedRouteAnswers404VoiceDisabledWhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));

        // When
        $this->client->request('GET', VoiceProbeController::REQUIRED_PATH, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_DISABLED']], $this->jsonBody());
    }

    public function testTheSwitchAnswersBeforeTheBodyIsValidated(): void
    {
        // Given : un corps invalide sur une route marquée, interrupteur coupé
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));

        // When
        $this->client->request('POST', VoiceProbeController::BODY_PATH, content: '{"phrase":""}', server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json']);

        // Then : 404 VOICE_DISABLED, pas 422, et le controller n'a rien exécuté
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['error' => ['message' => 'VOICE_DISABLED']], $this->jsonBody());
    }

    public function testTheSameInvalidBodyIsRefusedWith422WhenTheSwitchIsOn(): void
    {
        // Given : la même requête, interrupteur allumé : la validation du corps s'applique bien (le test précédent prouve donc l'ordre)
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(true));

        // When
        $this->client->request('POST', VoiceProbeController::BODY_PATH, content: '{"phrase":""}', server: $this->bearerFor($this->savedUser()) + ['CONTENT_TYPE' => 'application/json']);

        // Then
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testAnUnmarkedRouteIgnoresTheSwitch(): void
    {
        // Given
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));

        // When
        $this->client->request('GET', VoiceProbeController::FREE_PATH, server: $this->bearerFor($this->savedUser()));

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame(['probe' => 'voice-free'], $this->jsonBody());
    }

    public function testAnAnonymousCallStillGets401WhenTheSwitchIsOff(): void
    {
        // Given
        static::getContainer()->set(VoiceFeature::class, new ControlledVoiceFeature(false));

        // When
        $this->client->request('GET', VoiceProbeController::REQUIRED_PATH);

        // Then
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
