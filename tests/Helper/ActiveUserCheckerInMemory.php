<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Interface\ActiveUserChecker;

/**
 * Les comptes actifs à la main : `new ActiveUserCheckerInMemory(['user-1'])`. Tout autre compte (désactivé, en attente, supprimé,
 * inconnu) n'est pas actif. `deactivate()` désactive un compte en cours de test.
 */
class ActiveUserCheckerInMemory implements ActiveUserChecker
{
    /** @var array<string, true> */
    private array $active = [];

    /**
     * @param list<string> $activeUserIds
     */
    public function __construct(array $activeUserIds = [])
    {
        foreach ($activeUserIds as $userId) {
            $this->active[$userId] = true;
        }
    }

    public function isActive(string $userId): bool
    {
        return isset($this->active[$userId]);
    }

    public function deactivate(string $userId): void
    {
        unset($this->active[$userId]);
    }
}
