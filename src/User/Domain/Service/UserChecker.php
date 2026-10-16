<?php

namespace Repas\User\Domain\Service;

use Repas\User\Domain\Exception\EmailNotVerifiedException;
use Repas\User\Domain\Model\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (method_exists($user, 'isDisabled') && $user->isDisabled()) {
            throw new CustomUserMessageAccountStatusException('Votre compte est désactivé.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // Après la vérification du mot de passe : l'état « en attente » ne se révèle qu'à celui qui le connaît (ADR 0020)
        if ($user instanceof User && $user->isPending()) {
            throw new EmailNotVerifiedException();
        }
    }
}
