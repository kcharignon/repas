<?php

namespace Repas\User\Domain\Interface;

use Repas\User\Domain\Model\PasswordResetToken;

interface PasswordResetTokenRepository
{
    /**
     * Le jeton dont le secret a ce hachage, ou `null` s'il est inconnu (jamais émis, déjà utilisé, remplacé).
     */
    public function findOneByTokenHash(string $tokenHash): ?PasswordResetToken;

    /**
     * Le jeton en cours de l'utilisateur, ou `null` s'il n'en a pas.
     */
    public function findOneByUserId(string $userId): ?PasswordResetToken;

    /**
     * Enregistre un nouveau jeton. Un jeton ne change jamais : il est remplacé (`deleteAllOfUser()` puis un nouveau) ou supprimé.
     */
    public function add(PasswordResetToken $token): void;

    /**
     * Supprime les jetons de l'utilisateur (réinitialisation faite, remplacement par un nouveau, ou suppression du compte).
     */
    public function deleteAllOfUser(string $userId): void;
}
