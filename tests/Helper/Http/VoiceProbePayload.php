<?php

namespace Repas\Tests\Helper\Http;


use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps validé de la route d'essai `VoiceProbeController::body()`.
 */
final readonly class VoiceProbePayload
{
    public function __construct(
        #[Assert\NotBlank]
        public string $phrase = '',
    ) {
    }
}
