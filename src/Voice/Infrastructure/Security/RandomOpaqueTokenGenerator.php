<?php

namespace Repas\Voice\Infrastructure\Security;


use Repas\Voice\Domain\Interface\OpaqueTokenGenerator;
use Repas\Voice\Domain\Model\OpaqueToken;

final readonly class RandomOpaqueTokenGenerator implements OpaqueTokenGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(OpaqueToken::BYTES)), '+/', '-_'), '=');
    }
}
