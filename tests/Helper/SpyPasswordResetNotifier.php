<?php

namespace Repas\Tests\Helper;


use Repas\User\Domain\Interface\PasswordResetNotifier;

class SpyPasswordResetNotifier implements PasswordResetNotifier
{
    /** @var list<array{email: string, secret: string}> */
    public array $sent = [];

    public function send(string $email, string $secret): void
    {
        $this->sent[] = ['email' => $email, 'secret' => $secret];
    }
}
