<?php

namespace Repas\User\Domain\Interface;

use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\UserIdentity;

/**
 * Les liens entre les comptes et leurs identités externes (table `user_identity`, ADR 0024 décision 8). Aucune clé étrangère
 * ne lie un lien à son compte : la suppression du compte efface ses liens (`deleteAllOfUser()`, ADR 0021).
 */
interface UserIdentityRepository
{
    /**
     * Le lien de cette identité externe (la clé : le `sub` du jeton), ou `null` si elle n'est liée à aucun compte.
     */
    public function findOneByProviderAndSubject(IdentityProvider $provider, string $subject): ?UserIdentity;

    /**
     * Le lien du compte chez ce fournisseur, ou `null` s'il n'en a pas.
     */
    public function findOneByUserAndProvider(string $userId, IdentityProvider $provider): ?UserIdentity;

    /**
     * Enregistre un nouveau lien. Un lien ne change jamais : il est posé une fois, puis supprimé avec le compte.
     *
     * Si l'identité est déjà liée à un compte, ou si le compte a déjà un lien chez ce fournisseur (deux connexions simultanées
     * qui ont toutes deux vu « aucun lien »), l'enregistrement est refusé. La transaction du bus est alors perdue : le cas
     * d'usage laisse remonter l'exception et ne tente rien d'autre.
     *
     * @throws IdentityException lien déjà posé (`alreadyLinked()`, `409`)
     */
    public function save(UserIdentity $identity): void;

    /**
     * Supprime tous les liens du compte (suppression du compte, ADR 0021) ; ceux des autres comptes restent.
     */
    public function deleteAllOfUser(string $userId): void;
}
