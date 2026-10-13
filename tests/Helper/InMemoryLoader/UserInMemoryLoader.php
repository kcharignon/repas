<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\User\Application\Loader\UserLoader;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Domain\Exception\UserException;

/**
 * Double idiot : renvoie le DTO programmé quel que soit l'identifiant demandé (ni recherche, ni filtre) et enregistre
 * ses appels. Sans DTO programmé, il se comporte comme le port : utilisateur introuvable.
 */
class UserInMemoryLoader implements UserLoader
{
    /** @var list<string> */
    public array $getCalls = [];

    public function __construct(
        private readonly ?CurrentUserDTO $user = null,
    ) {
    }

    public function get(string $id): CurrentUserDTO
    {
        $this->getCalls[] = $id;

        return $this->user ?? throw UserException::NotFound($id);
    }
}
