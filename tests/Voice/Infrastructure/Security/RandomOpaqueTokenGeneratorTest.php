<?php

namespace Repas\Tests\Voice\Infrastructure\Security;


use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Model\OpaqueToken;
use Repas\Voice\Infrastructure\Security\RandomOpaqueTokenGenerator;

class RandomOpaqueTokenGeneratorTest extends TestCase
{
    public function testEveryTokenIs32RandomBytesInBase64UrlAndAcceptedByTheDomain(): void
    {
        $generator = new RandomOpaqueTokenGenerator();
        $tokens = [];

        for ($i = 0; $i < 200; $i++) {
            $token = $generator->generate();

            $this->assertTrue(OpaqueToken::isWellFormed($token), $token);
            $this->assertSame(43, strlen($token));
            $this->assertSame(OpaqueToken::BYTES, strlen(base64_decode(strtr($token, '-_', '+/') . '=', true)));
            $tokens[$token] = true;
        }

        // 256 bits : aucun doublon sur 200 tirages
        $this->assertCount(200, $tokens);
    }
}
