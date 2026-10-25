<?php

namespace Repas\Tests\Helper\Alexa;


use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\CertificateChainFetcher;
use Repas\Voice\Domain\Model\CertificateChainUrl;

/**
 * Double du port `CertificateChainFetcher` : renvoie la chaîne programmée (ou échoue si elle est `null`) et note les adresses
 * demandées. Aucun accès au réseau.
 */
final class ChainFetcherDouble implements CertificateChainFetcher
{
    /** @var list<string> les adresses (normalisées) demandées, dans l'ordre */
    public array $requested = [];

    public function __construct(private readonly ?string $chainPem)
    {
    }

    public function fetch(CertificateChainUrl $url): string
    {
        $this->requested[] = $url->value();

        return $this->chainPem ?? throw VoiceException::certificateChainUnavailable('double de test');
    }
}
