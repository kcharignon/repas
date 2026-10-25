<?php

namespace Repas\Tests\Helper;


use Repas\Tests\Helper\Alexa\AlexaTestClient;
use Repas\Voice\Domain\Interface\OpaqueTokenGenerator;

/**
 * Renvoie les secrets donnés l'un après l'autre, puis des secrets numérotés de la bonne forme (`secret-2`, `secret-3`…, complétés à
 * 43 caractères). Il garde ce qu'il a donné, pour qu'un test retrouve le secret en clair que la base ne connaît pas.
 */
class ControlledOpaqueTokenGenerator implements OpaqueTokenGenerator
{
    /** @var list<string> */
    public array $generated = [];
    private int $index = 0;

    /**
     * @param list<string> $tokens
     */
    public function __construct(private readonly array $tokens = [])
    {
    }

    public function generate(): string
    {
        $token = $this->tokens[$this->index] ?? AlexaTestClient::token('secret-' . $this->index);
        $this->index++;
        $this->generated[] = $token;

        return $token;
    }
}
