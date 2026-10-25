<?php

namespace Repas\Tests\Voice\Infrastructure\Security;


use ReflectionProperty;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\AlexaRequestVerifier;
use Repas\Voice\Domain\Interface\CertificateChainFetcher;
use Repas\Voice\Infrastructure\Security\HttpCertificateChainFetcher;
use Repas\Voice\Infrastructure\Security\OpenSslAlexaRequestVerifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le câblage réel du vérificateur (VOX-203) : le port est servi par l'adaptateur OpenSSL, qui télécharge la chaîne par l'adaptateur
 * HTTP, et l'identifiant du skill vient de `ALEXA_SKILL_ID` — VIDE par défaut, et vide veut dire « aucune requête n'est acceptée ».
 * Aucun de ces essais n'atteint le réseau : la requête est refusée avant le téléchargement.
 */
class AlexaRequestVerifierWiringTest extends KernelTestCase
{
    public function testThePortsAreServedByTheOpenSslAndHttpAdapters(): void
    {
        // Given
        self::bootKernel();

        // When
        $verifier = self::getContainer()->get(AlexaRequestVerifier::class);
        $fetcher = self::getContainer()->get(CertificateChainFetcher::class);

        // Then
        $this->assertInstanceOf(OpenSslAlexaRequestVerifier::class, $verifier);
        $this->assertInstanceOf(HttpCertificateChainFetcher::class, $fetcher);
        $this->assertInstanceOf(HttpCertificateChainFetcher::class, (new ReflectionProperty(OpenSslAlexaRequestVerifier::class, 'chainFetcher'))->getValue($verifier));
        $this->assertSame([], (new ReflectionProperty(OpenSslAlexaRequestVerifier::class, 'trustedRoots'))->getValue($verifier), 'en production : le magasin du système');
    }

    public function testTheSkillIsNotConfiguredByDefaultAndEverythingIsRefused(): void
    {
        // Given : l'environnement de test ne pose pas ALEXA_SKILL_ID (il est vide dans .env)
        $this->withSkillId(false, function (): void {
            // When
            $verifier = self::getContainer()->get(AlexaRequestVerifier::class);

            // Then
            $this->assertSame('', (new ReflectionProperty(OpenSslAlexaRequestVerifier::class, 'skillId'))->getValue($verifier));
            $this->expectException(VoiceException::class);
            $this->expectExceptionMessage(VoiceException::INVALID_REQUEST);
            $verifier->verify('{"request":{"timestamp":"2026-10-25T10:00:00Z"}}', 'https://s3.amazonaws.com/echo.api/echo-api-cert.pem', 'AAAA');
        });
    }

    public function testTheSkillIdentifierComesFromTheEnvironment(): void
    {
        // Given
        $this->withSkillId('amzn1.ask.skill.00000000-test-0000-0000-000000000001', function (): void {
            // When
            $verifier = self::getContainer()->get(AlexaRequestVerifier::class);

            // Then
            $this->assertSame('amzn1.ask.skill.00000000-test-0000-0000-000000000001', (new ReflectionProperty(OpenSslAlexaRequestVerifier::class, 'skillId'))->getValue($verifier));
        });
    }

    /**
     * Reconstruit le noyau avec `ALEXA_SKILL_ID` à la valeur essayée (`false` : retirée de l'environnement), puis remet l'environnement.
     */
    private function withSkillId(string|false $value, callable $assertions): void
    {
        $previous = [$_SERVER['ALEXA_SKILL_ID'] ?? null, $_ENV['ALEXA_SKILL_ID'] ?? null, getenv('ALEXA_SKILL_ID')];
        try {
            if ($value === false) {
                unset($_SERVER['ALEXA_SKILL_ID'], $_ENV['ALEXA_SKILL_ID']);
                putenv('ALEXA_SKILL_ID');
            } else {
                $_SERVER['ALEXA_SKILL_ID'] = $_ENV['ALEXA_SKILL_ID'] = $value;
                putenv('ALEXA_SKILL_ID=' . $value);
            }
            self::ensureKernelShutdown();
            self::bootKernel(['environment' => 'test', 'debug' => false]);

            $assertions();
        } finally {
            foreach (['_SERVER' => $previous[0], '_ENV' => $previous[1]] as $bag => $old) {
                if ($old === null) {
                    unset($GLOBALS[$bag]['ALEXA_SKILL_ID']);
                } else {
                    $GLOBALS[$bag]['ALEXA_SKILL_ID'] = $old;
                }
            }
            putenv($previous[2] === false ? 'ALEXA_SKILL_ID' : 'ALEXA_SKILL_ID=' . $previous[2]);
            self::ensureKernelShutdown();
        }
    }
}
