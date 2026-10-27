<?php

namespace Repas\Tests\Voice;


use PHPUnit\Framework\TestCase;
use Repas\Voice\Infrastructure\Security\OpenSslAlexaRequestVerifier;

/**
 * La bordure de production (`deploy/Caddyfile`) laisse passer le corps d'Alexa jusqu'à la taille que lit son contrôleur (la limite du
 * vérificateur plus un octet) : la limite de la bordure et celle du code ne se désaccordent pas en silence.
 */
final class EdgeAlexaBodyLimitTest extends TestCase
{
    public function testTheEdgeAcceptsAnAlexaBodyUpToTheSizeTheControllerReads(): void
    {
        // Given
        $caddyfile = file_get_contents(dirname(__DIR__, 2) . '/deploy/Caddyfile');
        $this->assertIsString($caddyfile);

        // When : le bloc du webhook, repéré par son chemin
        $found = preg_match(
            '~@alexa\s+path\s+/api/voice/alexa\s+handle\s+@alexa\s*\{\s*request_body\s*\{\s*max_size\s+(\d+)\s*\}~',
            $caddyfile,
            $matches,
        );

        // Then
        $this->assertSame(1, $found, 'le bloc du webhook d\'Alexa porte sa propre limite de corps');
        $this->assertGreaterThanOrEqual(OpenSslAlexaRequestVerifier::MAX_BODY_BYTES + 1, (int) $matches[1]);
    }
}
