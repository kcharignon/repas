<?php

namespace Repas\User\Domain\Exception;


use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Connexion refusée : le mot de passe est bon mais l'adresse email du compte n'est pas confirmée (ADR 0020). Levée
 * après la vérification du mot de passe, pour ne révéler l'état du compte qu'à celui qui le connaît.
 *
 * Étend l'exception « compte » personnalisable de Symfony : seule celle-là n'est pas rabattue sur un échec générique.
 */
final class EmailNotVerifiedException extends CustomUserMessageAccountStatusException
{
    public const string MESSAGE = 'EMAIL_NOT_VERIFIED';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
