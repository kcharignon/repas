<?php

namespace Repas\User\Infrastructure\Security;


use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\IssueRefreshToken\IssueRefreshTokenCommand;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Domain\Model\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Une connexion réussie (`POST /api/login_check`) ouvre une famille de refresh tokens et pose son cookie (ADR 0015 §3).
 */
final readonly class IssueRefreshTokenOnLogin
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private RefreshTokenCookie $cookie,
    ) {
    }

    #[AsEventListener(event: Events::AUTHENTICATION_SUCCESS)]
    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $secret = RefreshTokenCookie::generateSecret();
        $this->commandBus->dispatch(new IssueRefreshTokenCommand($user->getId(), RefreshToken::hash($secret)));

        $event->getResponse()->headers->setCookie($this->cookie->create($secret));
    }
}
