<?php

namespace Repas\User\Application\Loader;

use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Domain\Exception\UserException;

interface UserLoader
{
    /**
     * @throws UserException si aucun utilisateur n'a cet identifiant
     */
    public function get(string $id): CurrentUserDTO;
}
