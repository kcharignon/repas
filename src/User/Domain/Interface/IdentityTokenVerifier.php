<?php

namespace Repas\User\Domain\Interface;

use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Model\VerifiedIdentity;

/**
 * Établit, sans rien croire de la page ni de l'application, qu'un jeton d'identité vient du fournisseur, a été émis pour
 * notre ID client, n'est pas expiré, et dit qui est la personne (ADR 0024 décision 6). Ne consomme pas le nonce : c'est le
 * cas d'usage qui le fait, après cette vérification (AUTH-006).
 */
interface IdentityTokenVerifier
{
    /**
     * @throws IdentityException toujours `invalidCredential()`, quelle que soit la raison du refus (aucune n'est révélée)
     */
    public function verify(string $idToken): VerifiedIdentity;
}
