<?php

namespace Repas\User\Infrastructure\AppVersion;


use Psr\Cache\CacheItemPoolInterface;
use Repas\User\Domain\Model\AppPlatform;
use Repas\User\Domain\Model\AppVersion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mémoire courte de la dernière version de l'application écrite pour un compte et une plateforme (DRV-758), dans le cache de
 * l'application (`cache.app`, aucune requête SQL) : elle évite de relire ou de réécrire `user_app_version` à chaque requête.
 *
 * Ce n'est qu'un cache : vide, expiré ou perdu, la version est simplement réécrite une fois (au plus une écriture par minute et
 * par compte, `AppVersionRateLimitListener`). Elle expire au bout d'une heure pour qu'une ligne perdue ou remplacée par un autre
 * appareil se corrige d'elle-même.
 */
final readonly class LastSeenAppVersions
{
    private const int TTL_SECONDS = 3600;

    public function __construct(
        #[Autowire(service: 'repas.app_version_cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function of(string $userId, AppPlatform $platform): ?AppVersion
    {
        $item = $this->cache->getItem($this->keyOf($userId, $platform));

        return $item->isHit() && is_string($item->get()) ? AppVersion::tryFromString($item->get()) : null;
    }

    public function remember(string $userId, AppPlatform $platform, AppVersion $version): void
    {
        $item = $this->cache->getItem($this->keyOf($userId, $platform));
        $item->set($version->toString())->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    /**
     * Les identifiants de compte sont des UUID ; la clé n'en garde que des caractères permis par PSR-6.
     */
    private function keyOf(string $userId, AppPlatform $platform): string
    {
        return 'app_version.' . $platform->value . '.' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $userId);
    }
}
