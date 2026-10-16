<?php

namespace Repas\Tests\Helper;


use Repas\User\Domain\Interface\EmailVerificationNotifier;

class SpyEmailVerificationNotifier implements EmailVerificationNotifier
{
    /** @var list<array{email: string, secret: string}> */
    public array $sent = [];

    public function send(string $email, string $secret): void
    {
        $this->sent[] = ['email' => $email, 'secret' => $secret];
    }
}
