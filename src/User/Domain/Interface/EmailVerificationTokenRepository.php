<?php

namespace Repas\User\Domain\Interface;

use Repas\User\Domain\Model\EmailVerificationToken;

interface EmailVerificationTokenRepository
{
    /**
     * Le jeton dont le secret a ce hachage, ou `null` s'il est inconnu (jamais émis, déjà utilisé, remplacé).
     */
    public function findOneByTokenHash(string $tokenHash): ?EmailVerificationToken;

    /**
     * Le jeton en cours de l'utilisateur, ou `null` s'il n'en a pas.
     */
    public function findOneByUserId(string $userId): ?EmailVerificationToken;

    /**
     * Enregistre un nouveau jeton. Un jeton ne change jamais : il est remplacé (`deleteAllOfUser()` puis un nouveau) ou supprimé.
     */
    public function add(EmailVerificationToken $token): void;

    /**
     * Supprime les jetons de l'utilisateur (confirmation faite, ou remplacement par un nouveau).
     */
    public function deleteAllOfUser(string $userId): void;
}
