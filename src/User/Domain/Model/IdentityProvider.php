<?php

namespace Repas\User\Domain\Model;


/**
 * Fournisseur d'identité externe qui prouve qui est la personne par un jeton signé (ADR 0024). La valeur est stockée telle
 * quelle (colonne `provider` de `user_identity`, AUTH-005) : ne jamais renommer un cas.
 */
enum IdentityProvider: string
{
    case GOOGLE = 'GOOGLE';
}
