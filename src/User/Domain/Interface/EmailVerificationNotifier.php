<?php

namespace Repas\User\Domain\Interface;

interface EmailVerificationNotifier
{
    /**
     * Envoie à `$email` le lien de confirmation construit sur `$secret`. Un échec d'envoi est journalisé, pas relancé : le
     * compte reste créé et l'utilisateur peut demander un nouvel envoi (ADR 0020).
     */
    public function send(string $email, string $secret): void;
}
