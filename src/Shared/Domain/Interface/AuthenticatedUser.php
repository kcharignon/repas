<?php

namespace Repas\Shared\Domain\Interface;


/**
 * Utilisateur connecté tel que les contextes autres que `User` le voient : son identifiant et son nombre de personnes
 * habituel, et s'il est désactivé, rien d'autre (un contexte n'importe pas le domaine d'un autre, ADR 0001 et 0035). Les controllers
 * l'obtiennent par `#[CurrentUser] AuthenticatedUser $user` ou `assert($this->getUser() instanceof AuthenticatedUser)`, les voters de
 * chaque contexte par `$token->getUser()`.
 */
interface AuthenticatedUser
{
    public function getId(): string;

    public function getDefaultServing(): int;

    /**
     * Le compte est désactivé par un administrateur (statut `DISABLED`, ADR 0021) : les voters des droits le refusent. Les statuts en attente et
     * actif ne le sont pas ici (les autres refus d'un compte se jugent ailleurs).
     */
    public function isDisabled(): bool;
}
