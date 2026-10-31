<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Double de l'utilisateur du jeton tel que les voters des contextes `Repas` et `Voice` le voient : seulement `AuthenticatedUser` (ADR 0035),
 * jamais le modèle du contexte `User`. Il prouve qu'un voter ne s'appuie sur rien d'autre.
 */
final readonly class SecurityUserDouble implements UserInterface, AuthenticatedUser
{
    public function __construct(
        private string $id,
        private bool $disabled = false,
        private int $defaultServing = 4,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDefaultServing(): int
    {
        return $this->defaultServing;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
