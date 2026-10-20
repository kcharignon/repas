<?php

namespace Repas\User\Domain\Interface;

use DateTimeImmutable;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Model\User;

interface UserRepository
{
    /**
     * @throws UserException
     */
    public function findOneById(string $id): User;

    /**
     * @throws UserException
     */
    public function findOneByEmail(string $email): User;

    /**
     * Un utilisateur porte cet email (l'email est unique : deux inscriptions du même email se télescoperaient).
     */
    public function emailExists(string $email): bool;

    /**
     * @return Tab<User>
     */
    public function findAll(): Tab;

    public function save(User $user): void;

    /**
     * Retient `$at` comme dernière connexion du compte `$userId` : une écriture ciblée de cette seule colonne, jamais
     * l'enregistrement du modèle (`save()` recopie tout le modèle, dernier enregistrement gagnant : il effacerait un statut ou
     * un mot de passe changé entre-temps). La date ne recule jamais (une connexion plus ancienne que celle connue est ignorée) et
     * un compte inconnu ou supprimé entre-temps ne reçoit rien. Le port ne décide pas s'il faut écrire : voir
     * `User::shouldRecordLogin()`.
     */
    public function recordLogin(string $userId, DateTimeImmutable $at): void;

    /**
     * Supprime le compte lui-même ; ses jetons et ses données du contexte `Repas` partent avec lui (ADR 0021).
     */
    public function delete(User $user): void;
}
