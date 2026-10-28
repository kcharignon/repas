<?php

namespace Repas\Tests\Helper;


use Repas\User\Domain\Interface\RegistrationNotifier;

class SpyRegistrationNotifier implements RegistrationNotifier
{
    /** @var list<array{email: string, secret: string}> */
    public array $welcomes = [];
    /** @var list<string> */
    public array $reminders = [];

    public function sendWelcome(string $email, string $secret): void
    {
        $this->welcomes[] = ['email' => $email, 'secret' => $secret];
    }

    public function sendAlreadyRegistered(string $email): void
    {
        $this->reminders[] = $email;
    }

    /** Nombre d'emails partis, tous modèles confondus : c'est ce que le temps de réponse peut laisser voir. */
    public function count(): int
    {
        return count($this->welcomes) + count($this->reminders);
    }
}
