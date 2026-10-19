<?php

namespace Repas\User\Infrastructure\Security;


use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Interrupteur et réglage de la connexion avec Google (ADR 0024 décision 5) : l'ID client Web (`GOOGLE_CLIENT_ID`, public
 * mais propre au déploiement, jamais écrit dans le dépôt). Vide ou fait d'espaces : la fonction est coupée.
 */
final readonly class GoogleSignInSettings
{
    private string $clientId;

    public function __construct(
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        string $clientId,
    ) {
        $this->clientId = trim($clientId);
    }

    public function isEnabled(): bool
    {
        return $this->clientId !== '';
    }

    /**
     * L'audience exigée des jetons ; chaîne vide si la fonction est coupée (le vérificateur refuse alors tout jeton).
     */
    public function clientId(): string
    {
        return $this->clientId;
    }
}
