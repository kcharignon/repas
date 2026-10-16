<?php

namespace Repas\User\Infrastructure\Security;


use Repas\User\Domain\Interface\SecretGenerator;

final readonly class RandomSecretGenerator implements SecretGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
