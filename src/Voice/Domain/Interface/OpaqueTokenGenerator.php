<?php

namespace Repas\Voice\Domain\Interface;


interface OpaqueTokenGenerator
{
    /**
     * Un secret aléatoire de `OpaqueToken::BYTES` octets (256 bits) en base64url sans remplissage, de la forme que
     * `OpaqueToken::isWellFormed()` accepte. Il ne voyage qu'une fois, dans la réponse qui le donne ; la base n'en garde que le haché.
     */
    public function generate(): string;
}
