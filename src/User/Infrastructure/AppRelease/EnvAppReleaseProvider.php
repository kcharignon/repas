<?php

namespace Repas\User\Infrastructure\AppRelease;


use Repas\User\Domain\Interface\AppReleaseProvider;
use Repas\User\Domain\Model\AppRelease;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit les versions publiées de l'application dans les variables d'environnement `APP_LATEST_VERSION` et `APP_MIN_VERSION`
 * (DRV-637), vides par défaut : vides, absentes ou mal formées, elles valent « inconnues » et personne n'est invité à mettre à
 * jour. `APP_LATEST_VERSION` est écrite par un script que l'humain lance après chaque publication de l'application.
 */
final readonly class EnvAppReleaseProvider implements AppReleaseProvider
{
    private AppRelease $release;

    public function __construct(
        #[Autowire('%env(default::APP_LATEST_VERSION)%')]
        ?string $latest,
        #[Autowire('%env(default::APP_MIN_VERSION)%')]
        ?string $minimum,
    ) {
        $this->release = AppRelease::fromSettings($latest, $minimum);
    }

    public function current(): AppRelease
    {
        return $this->release;
    }
}
