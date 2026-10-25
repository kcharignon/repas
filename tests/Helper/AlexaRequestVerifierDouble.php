<?php

namespace Repas\Tests\Helper;


use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\AlexaRequestVerifier;
use RuntimeException;

/**
 * Double du port `AlexaRequestVerifier` pour les tests du controller : accepte tout, ou refuse tout (`VoiceException::invalidRequest()`),
 * ou échoue d'une façon inattendue, et note ce qu'on lui a présenté pour prouver que le corps brut et les en-têtes lui arrivent tels quels.
 * La vraie vérification se prouve avec de vraies signatures dans `OpenSslAlexaRequestVerifierTest` et de bout en bout dans
 * `AlexaWebhookApiFlowTest`.
 */
final class AlexaRequestVerifierDouble implements AlexaRequestVerifier
{
    /** @var list<array{body: string, certChainUrl: string, signature: string}> */
    public array $verified = [];

    private function __construct(private readonly string $mode)
    {
    }

    public static function accepting(): self
    {
        return new self('accept');
    }

    public static function refusing(): self
    {
        return new self('refuse');
    }

    /** Une panne qui n'est pas un refus (le webhook doit répondre `400` quand même : dans le doute, on refuse). */
    public static function crashing(): self
    {
        return new self('crash');
    }

    public function verify(string $rawBody, string $certChainUrl, string $signature256): void
    {
        $this->verified[] = ['body' => $rawBody, 'certChainUrl' => $certChainUrl, 'signature' => $signature256];

        match ($this->mode) {
            'refuse' => throw VoiceException::invalidRequest(),
            'crash' => throw new RuntimeException('panne inattendue du vérificateur'),
            default => null,
        };
    }
}
