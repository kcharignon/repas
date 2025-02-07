<?php

namespace Repas\User\Infrastructure\Security;


use Repas\User\Domain\Model\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class OwnerVoter extends Voter
{
    public const string HIMSELF = 'HIMSELF';
    public const string INGREDIENT_OWNER = 'INGREDIENT_OWNER';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }


    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::HIMSELF, self::INGREDIENT_OWNER]) && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::HIMSELF => $user->getId() === $subject,
            self::INGREDIENT_OWNER => str_starts_with($subject, $user->getId()),
            default => false,
        };
    }

}
