<?php

namespace Repas\User\Infrastructure\EventListener;


use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

readonly class LogoutListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RememberMeHandlerInterface $rememberMeHandler,
    ) {
    }

    #[AsEventListener]
    public function __invoke(LogoutEvent $event): void
    {
        $this->tokenStorage->setToken(null);

        $this->rememberMeHandler->clearRememberMeCookie();
    }
}
