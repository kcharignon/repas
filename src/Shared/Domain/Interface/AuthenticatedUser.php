<?php

namespace Repas\Shared\Domain\Interface;


/**
 * Utilisateur connecté tel que les contextes autres que `User` le voient : son identifiant et son nombre de personnes
 * habituel, rien d'autre (un contexte n'importe pas le domaine d'un autre, ADR 0001). Les controllers
 * l'obtiennent par `#[CurrentUser] AuthenticatedUser $user` ou `assert($this->getUser() instanceof AuthenticatedUser)`.
 */
interface AuthenticatedUser
{
    public function getId(): string;

    public function getDefaultServing(): int;
}
