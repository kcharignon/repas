<?php

namespace Repas\Voice\Infrastructure\Security;


use Repas\Shared\Domain\Exception\DomainException;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Voice\Application\Loader\VoiceLinkOwnershipLoader;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droit sur une liaison avec Alexa (`VOICE_LINK_OWNER`, le sujet est l'identifiant de la liaison, ADR 0029 ; ADR 0035 : chaque contexte juge
 * ses objets). **Aucun passe-droit administrateur** : retirer la liaison d'un autre couperait sa commande vocale, la liaison est à son
 * propriétaire seul. Le voter n'interroge donc jamais le rôle.
 */
class VoiceLinkOwnerVoter extends Voter
{
    public const string VOICE_LINK_OWNER = 'VOICE_LINK_OWNER';

    public function __construct(
        private readonly VoiceLinkOwnershipLoader $voiceLinkOwnershipLoader,
    ) {
    }


    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VOICE_LINK_OWNER && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof AuthenticatedUser || $user->isDisabled()) {
            return false;
        }

        // Une liaison introuvable donne un 404, pas une erreur 500
        try {
            return $this->voiceLinkOwnershipLoader->voiceLinkOwnerId($subject) === $user->getId();
        } catch (DomainException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }
    }
}
