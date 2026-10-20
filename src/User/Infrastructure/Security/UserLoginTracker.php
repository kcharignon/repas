<?php

namespace Repas\User\Infrastructure\Security;


use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\RecordUserLogin\RecordUserLoginCommand;
use Repas\User\Domain\Model\User;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Point d'entrée UNIQUE du suivi de la dernière connexion (ADR 0021, amendement du 2026-10-20) : tout code qui vient de
 * AUTHENTIFIER un compte l'appelle, une fois, après le succès, et ne fait rien d'autre. Aujourd'hui, la connexion par mot de passe
 * (`RecordUserLoginOnLogin`) et le renouvellement de la session (`RefreshTokenApiController`). La future connexion avec Google
 * (ADR 0024, AUTH-006) l'appellera de la même façon, sans nouveau cas d'usage.
 *
 * Une authentification refusée n'arrive jamais ici : on n'appelle `authenticated()` qu'après le succès.
 *
 * Deux garanties :
 * - rien n'est envoyé au bus quand l'écriture est inutile (`User::shouldRecordLogin()`, jugé sur le compte déjà chargé par
 *   l'authentification) : zéro requête, pas même une transaction ouverte ;
 * - un échec du suivi ne fait jamais échouer l'authentification. Le renouvellement a déjà consommé l'ancien jeton : refuser la
 *   réponse perdrait le nouveau cookie et couperait la session pour une simple date. L'échec est journalisé.
 */
final readonly class UserLoginTracker
{
    public function __construct(
        private CommandBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function authenticated(UserInterface $user): void
    {
        if (!$user instanceof User || !$user->shouldRecordLogin($this->clock->now())) {
            return;
        }

        try {
            $this->commandBus->dispatch(new RecordUserLoginCommand($user->getId()));
        } catch (Throwable $e) {
            $this->logger->warning('The last login of an account could not be recorded.', ['user' => $user->getId(), 'exception' => $e]);
        }
    }
}
