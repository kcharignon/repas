<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserAppVersionRepository;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\UserAppVersion;

/**
 * Double en mémoire du port : il tient le même contrat que la table, une ligne par compte et par plateforme, et n'écrit rien
 * pour un compte que `$users` ne connaît pas (compte supprimé), pour que le cas d'usage se teste sans base.
 */
class UserAppVersionInMemoryRepository implements UserAppVersionRepository
{
    /** @var array<string, UserAppVersion> */
    private array $appVersions = [];

    public int $saveCalls = 0;

    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function save(UserAppVersion $appVersion): void
    {
        $this->saveCalls++;

        try {
            $this->users->findOneById($appVersion->getUserId());
        } catch (UserException) {
            return;
        }

        $this->appVersions[$appVersion->getId()] = clone $appVersion;
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->appVersions = array_filter($this->appVersions, static fn(UserAppVersion $appVersion): bool => $appVersion->getUserId() !== $userId);
    }

    /**
     * @return list<UserAppVersion>
     */
    public function all(): array
    {
        return array_values($this->appVersions);
    }
}
