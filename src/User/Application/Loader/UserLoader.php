<?php

namespace Repas\User\Application\Loader;

use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;
use Repas\User\Domain\Exception\UserException;

interface UserLoader
{
    /**
     * @throws UserException si aucun utilisateur n'a cet identifiant
     */
    public function get(string $id): CurrentUserDTO;
    /**
     * Tous les utilisateurs, triés par email, avec leurs statistiques (administration).
     *
     * @return list<UserSummaryDTO>
     */
    public function list(): array;

    /**
     * Un compte utilise-t-il déjà cet email ?
     */
    public function emailExists(string $email): bool;

    /**
     * Le compte existe-t-il et son statut est-il `ACTIVE` (ADR 0021) ? Une requête sur la colonne du statut, sans charger le compte.
     */
    public function isActive(string $userId): bool;

    /**
     * L'identifiant du compte qui porte cette adresse (voir `User::normalizeEmail()`), ou `null`. Une requête sur la colonne.
     */
    public function idOfEmail(string $email): ?string;

    /**
     * L'identifiant et le statut (`PENDING`, `ACTIVE`, `DISABLED`) de chaque compte, triés par identifiant. Une requête sur deux colonnes.
     *
     * @return list<array{id: string, status: string}>
     */
    public function listStatuses(): array;

    /**
     * L'adresse des comptes donnés, par identifiant ; un identifiant inconnu n'y figure pas. Une requête.
     *
     * @param list<string> $userIds
     *
     * @return array<string, string>
     */
    public function emailsOf(array $userIds): array;
}
