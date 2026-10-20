<?php

namespace Repas\User\Application\RecordUserLogin;


/**
 * Une authentification vient de réussir pour ce compte : retenir la date de sa dernière connexion (ADR 0021, amendement du
 * 2026-10-20). `userId` vient de l'authentification qui vient de réussir (jeton de rafraîchissement échangé, mot de passe vérifié),
 * jamais du corps d'une requête. Envoyée seulement par `UserLoginTracker`, le point d'entrée unique.
 */
final readonly class RecordUserLoginCommand
{
    public function __construct(
        public string $userId,
    ) {
    }
}
