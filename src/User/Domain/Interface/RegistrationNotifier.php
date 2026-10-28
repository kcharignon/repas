<?php

namespace Repas\User\Domain\Interface;

/**
 * Les deux emails que l'inscription publique peut faire partir (ADR 0020 amendé) : la bienvenue d'une adresse nouvelle ou d'un compte
 * encore en attente, et le rappel à une adresse qui a déjà un compte actif. Un échec d'envoi est journalisé, pas relancé : la réponse
 * de l'API est la même quel que soit l'état de l'adresse, un échec ne doit pas la changer.
 */
interface RegistrationNotifier
{
    /**
     * Envoie à `$email` le lien de bienvenue construit sur `$secret` : il ouvre l'écran où l'on choisit son mot de passe.
     */
    public function sendWelcome(string $email, string $secret): void;

    /**
     * Dit à `$email` qu'un compte existe déjà, avec de quoi se connecter ou choisir un nouveau mot de passe. Aucun secret.
     */
    public function sendAlreadyRegistered(string $email): void;
}
