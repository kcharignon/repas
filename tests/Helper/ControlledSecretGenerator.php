<?php

namespace Repas\Tests\Helper;


use Repas\User\Domain\Interface\SecretGenerator;

/**
 * Renvoie les secrets donnés l'un après l'autre, puis des secrets numérotés (`secret-2`, `secret-3`…).
 */
class ControlledSecretGenerator implements SecretGenerator
{
    private int $index = 0;

    /**
     * @param list<string> $secrets
     */
    public function __construct(private readonly array $secrets = [])
    {
    }

    public function generate(): string
    {
        $secret = $this->secrets[$this->index] ?? 'secret-' . $this->index;
        $this->index++;

        return $secret;
    }
}
