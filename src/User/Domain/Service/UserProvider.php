<?php

namespace Repas\User\Domain\Service;


use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

readonly class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{


    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        $user->setPassword($newHashedPassword);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return $this->userRepository->findOneByEmail($user->getEmail());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            return $this->userRepository->findOneByEmail($identifier);
        } catch (UserException $e) {
            $exception = new UserNotFoundException(sprintf('User with email "%s" not found.', $identifier));
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }
    }

}
