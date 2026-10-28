<?php

namespace Repas\User\Application\VerifyEmail;


use Psr\Clock\ClockInterface;
use Repas\Shared\Domain\Event\UserActivatedEvent;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\EmailVerificationTokenRepository;
use Repas\User\Domain\Interface\UserRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class VerifyEmailHandler
{
    public function __construct(
        private EmailVerificationTokenRepository $tokenRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Un compte en attente devient actif : l'activation est annoncée (`UserActivatedEvent`), dans la transaction du bus de commandes, pour que
     * le compte reçoive ses ingrédients (ING-008). Un compte déjà actif, ou désactivé, ne change pas de statut et n'annonce rien.
     *
     * Un compte en attente perd tout mot de passe utilisable en devenant actif (`User::lockPassword()`, ADR 0020 amendé) : le mot de
     * passe d'un compte en attente a été posé avant la confirmation de l'adresse (ancien formulaire d'inscription), par quelqu'un qui
     * n'est peut-être pas le propriétaire de la boîte. Le propriétaire en choisit un par « mot de passe oublié », qui prouve l'adresse.
     * Un compte inscrit par lien n'en a déjà pas : rien ne change pour lui.
     *
     * @throws UserException lien inconnu, déjà utilisé ou expiré
     */
    public function __invoke(VerifyEmailCommand $command): void
    {
        $token = $this->tokenRepository->findOneByTokenHash($command->tokenHash);
        if ($token === null || $token->isExpired($this->clock->now())) {
            throw UserException::invalidEmailVerificationToken();
        }

        try {
            $user = $this->userRepository->findOneById($token->getUserId());
        } catch (UserException) {
            // Jeton orphelin : même réponse qu'un jeton inconnu
            throw UserException::invalidEmailVerificationToken();
        }

        $wasPending = $user->isPending();

        if ($wasPending) {
            $user->lockPassword();
        }
        $user->verifyEmail();
        $this->userRepository->save($user);
        // Le lien ne sert qu'une fois
        $this->tokenRepository->deleteAllOfUser($user->getId());

        if ($wasPending) {
            $this->eventDispatcher->dispatch(new UserActivatedEvent($user->getId()));
        }
    }
}
