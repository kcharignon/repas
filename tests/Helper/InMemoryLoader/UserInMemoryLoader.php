<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\User\Application\Loader\UserLoader;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;
use Repas\User\Domain\Exception\UserException;

/**
 * Double idiot : renvoie le DTO programmé quel que soit l'identifiant demandé (ni recherche, ni filtre) et enregistre
 * ses appels. Sans DTO programmé, il se comporte comme le port : utilisateur introuvable.
 */
class UserInMemoryLoader implements UserLoader
{
    /** @var list<string> */
    public array $getCalls = [];

    public int $listCalls = 0;

    /** @var list<string> */
    public array $emailExistsCalls = [];

    /**
     * @param list<UserSummaryDTO> $users
     * @param list<string> $activeUserIds les comptes que `isActive()` dit actifs (les autres, inconnus ou non, ne le sont pas)
     */
    public function __construct(
        private readonly ?CurrentUserDTO $user = null,
        private readonly array $users = [],
        private readonly bool $emailAlreadyUsed = false,
        private readonly array $activeUserIds = [],
    ) {
    }

    public function get(string $id): CurrentUserDTO
    {
        $this->getCalls[] = $id;

        return $this->user ?? throw UserException::NotFound($id);
    }
    public function list(): array
    {
        $this->listCalls++;

        return $this->users;
    }

    public function emailExists(string $email): bool
    {
        $this->emailExistsCalls[] = $email;

        return $this->emailAlreadyUsed;
    }

    public function isActive(string $userId): bool
    {
        return in_array($userId, $this->activeUserIds, true);
    }
}
