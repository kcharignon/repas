<?php

namespace Repas\User\Domain\Interface;

interface PasswordResetNotifier
{
    /**
     * Envoie à `$email` le lien de réinitialisation du mot de passe construit sur `$secret`. Un échec d'envoi est journalisé,
     * pas relancé : la réponse de l'API ne doit pas dépendre de l'envoi, et l'utilisateur peut faire une nouvelle demande (ADR 0026).
     */
    public function send(string $email, string $secret): void;
}
