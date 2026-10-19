<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Interface\UserIdentityRepository;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\UserIdentity;

/**
 * Double en mémoire du port : il tient le même contrat que la table, y compris ses deux clés uniques (`alreadyLinked()`),
 * pour que le cas d'usage de connexion se teste sans base.
 */
class UserIdentityInMemoryRepository implements UserIdentityRepository
{
    /** @var array<string, UserIdentity> */
    private array $identities = [];

    /**
     * @param list<UserIdentity> $identities
     */
    public function __construct(array $identities = [])
    {
        foreach ($identities as $identity) {
            $this->save($identity);
        }
    }

    public function findOneByProviderAndSubject(IdentityProvider $provider, string $subject): ?UserIdentity
    {
        foreach ($this->identities as $identity) {
            if ($identity->getProvider() === $provider && $identity->getSubject() === $subject) {
                return clone $identity;
            }
        }

        return null;
    }

    public function findOneByUserAndProvider(string $userId, IdentityProvider $provider): ?UserIdentity
    {
        foreach ($this->identities as $identity) {
            if ($identity->getUserId() === $userId && $identity->getProvider() === $provider) {
                return clone $identity;
            }
        }

        return null;
    }

    public function save(UserIdentity $identity): void
    {
        foreach ($this->identities as $id => $existing) {
            if ($id === $identity->getId()) {
                continue;
            }
            $sameSubject = $existing->getProvider() === $identity->getProvider() && $existing->getSubject() === $identity->getSubject();
            $sameUser = $existing->getProvider() === $identity->getProvider() && $existing->getUserId() === $identity->getUserId();
            if ($sameSubject || $sameUser) {
                throw IdentityException::alreadyLinked();
            }
        }

        $this->identities[$identity->getId()] = clone $identity;
    }

    public function deleteAllOfUser(string $userId): void
    {
        $this->identities = array_filter($this->identities, static fn(UserIdentity $identity): bool => $identity->getUserId() !== $userId);
    }

    /**
     * @return list<UserIdentity>
     */
    public function all(): array
    {
        return array_values($this->identities);
    }
}
