<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Voice\Domain\Interface\UserVoiceDataRepository;

/**
 * Double idiot du port d'effacement des données vocales d'un compte : retient les comptes dont on a demandé l'effacement.
 */
class UserVoiceDataInMemoryRepository implements UserVoiceDataRepository
{
    /** @var list<string> */
    public array $erasedUsers = [];

    public function deleteByUser(string $userId): void
    {
        $this->erasedUsers[] = $userId;
    }
}
