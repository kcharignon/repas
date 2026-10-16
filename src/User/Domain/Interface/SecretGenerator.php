<?php

namespace Repas\User\Domain\Interface;

interface SecretGenerator
{
    /**
     * Un secret aléatoire de 256 bits, en hexadécimal : il est stocké haché et ne voyage que dans un lien envoyé par email.
     */
    public function generate(): string;
}
