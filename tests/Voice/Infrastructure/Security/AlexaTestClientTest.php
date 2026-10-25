<?php

namespace Repas\Tests\Voice\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Voice\Domain\Interface\AlexaOAuthClient;
use Repas\Voice\Infrastructure\Security\EnvAlexaOAuthClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le client OAuth factice des tests (`AlexaTestClient`) et la configuration de l'environnement de test (`.env.test`, `phpunit.dist.xml`)
 * disent la même chose, et le service branché est l'implémentation de l'environnement.
 */
class AlexaTestClientTest extends KernelTestCase
{
    public function testTheSecretHashConstantIsTheSha256OfTheSecret(): void
    {
        $this->assertSame(hash('sha256', AlexaTestClient::SECRET), AlexaTestClient::SECRET_HASH);
    }

    public function testTheWiredClientIsTheOneOfTheTestEnvironment(): void
    {
        // Given
        self::bootKernel();

        // When
        $client = self::getContainer()->get(AlexaOAuthClient::class);

        // Then
        $this->assertInstanceOf(EnvAlexaOAuthClient::class, $client);
        $this->assertTrue($client->authenticate(AlexaTestClient::CLIENT_ID, AlexaTestClient::SECRET));
        $this->assertFalse($client->authenticate(AlexaTestClient::CLIENT_ID, 'wrong'));
        $this->assertTrue($client->isRedirectUriAllowed(AlexaTestClient::REDIRECT_URI));
        $this->assertTrue($client->isRedirectUriAllowed(AlexaTestClient::OTHER_REDIRECT_URI));
        $this->assertFalse($client->isRedirectUriAllowed('https://evil.example.test/callback'));
    }
}
