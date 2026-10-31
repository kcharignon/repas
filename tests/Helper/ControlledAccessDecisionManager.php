<?php

namespace Repas\Tests\Helper;


use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Gestionnaire de décision de test des voters de droits : répond « administrateur » ou non à la question `ROLE_ADMIN` qu'ils lui posent.
 */
final readonly class ControlledAccessDecisionManager implements AccessDecisionManagerInterface
{
    public function __construct(private bool $admin)
    {
    }

    public function decide(TokenInterface $token, array $attributes, mixed $object = null, ?AccessDecision $accessDecision = null): bool
    {
        return $this->admin;
    }
}
