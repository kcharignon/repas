<?php

namespace Repas\User\Infrastructure\Http;


/**
 * Corps d'une route publique qui fait partir un email vers l'adresse saisie (inscription, renvoi du lien de confirmation, mot de passe
 * oublié) : `PublicEmailRateLimitListener` plafonne les demandes par destinataire.
 */
interface EmailRecipientPayload
{
    /**
     * L'adresse saisie, telle que reçue (le plafond la met sous la forme qu'utilise la recherche du compte).
     */
    public function recipientEmail(): string;
}
