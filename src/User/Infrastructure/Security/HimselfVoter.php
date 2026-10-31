<?php

namespace Repas\User\Infrastructure\Security;


use Repas\User\Domain\Model\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droit d'un utilisateur sur son propre compte (`HIMSELF`, le sujet est l'identifiant du compte visé). Les droits sur les objets de `Repas` et
 * de `Voice` sont jugés par un voter de chacun de ces contextes (ADR 0035).
 */
class HimselfVoter extends Voter
{
    public const string HIMSELF = 'HIMSELF';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }


    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::HIMSELF && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // Un administrateur passe pour le compte de n'importe qui
        if ($this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        $user = $token->getUser();

        if (!$user instanceof User || $user->isDisabled()) {
            return false;
        }

        return $user->getId() === $subject;
    }
}
