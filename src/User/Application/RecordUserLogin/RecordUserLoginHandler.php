<?php

namespace Repas\User\Application\RecordUserLogin;


use Psr\Clock\ClockInterface;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retient la dernière connexion d'un compte (ADR 0021, amendement du 2026-10-20).
 *
 * Elle ne s'écrit pas à chaque authentification : au plus une fois toutes les `User::LOGIN_RECORD_INTERVAL_SECONDS` par compte
 * (`User::shouldRecordLogin()`, jugé sur la valeur chargée, donc sans requête de plus quand on n'écrit pas). Quand on écrit, c'est
 * une seule instruction qui ne touche que la colonne `last_login_at` (`UserRepository::recordLogin()`) : jamais `save()` du
 * modèle, qui recopierait tout le modèle et écraserait ce qu'une autre requête vient de changer.
 *
 * Le compte vient d'être chargé par l'authentification qui a réussi : `findOneById` est servi par l'identity map de Doctrine, il ne
 * coûte aucune requête dans une requête réelle. Un compte supprimé entre-temps, ou qui n'est plus actif, ne reçoit rien et ne fait
 * pas échouer l'authentification.
 */
#[AsMessageHandler]
readonly class RecordUserLoginHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordUserLoginCommand $command): void
    {
        try {
            $user = $this->userRepository->findOneById($command->userId);
        } catch (UserException) {
            return;
        }

        $now = $this->clock->now();
        if (!$user->shouldRecordLogin($now)) {
            return;
        }

        $this->userRepository->recordLogin($user->getId(), $now);
    }
}
