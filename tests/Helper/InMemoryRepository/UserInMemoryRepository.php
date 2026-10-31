<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use DateTimeImmutable;
use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class UserInMemoryRepository implements UserRepository
{
    /** @var Tab<User>  */
    private Tab $users;

    /** @var list<array{string, DateTimeImmutable}> les appels à `recordLogin()`, dans l'ordre (compte, date) */
    public array $recordLoginCalls = [];

    /** @var list<string> les adresses demandées à `findOneByEmail()`, dans l'ordre : prouve qu'un cas d'usage ne lit un compte que s'il en a besoin */
    public array $findOneByEmailCalls = [];

    /**
     * @param array<User>|null $users
     */
    public function __construct(array $users = [])
    {
        $this->users = Tab::newEmptyTyped(User::class);
        foreach ($users as $user) {
            $this->users[$user->getId()] = $user;
        }
    }


    public function findOneById(string $id): User
    {
        return $this->users[$id] ?? throw UserException::NotFound($id);
    }

    public function findOneByEmail(string $email): User
    {
        $this->findOneByEmailCalls[] = $email;

        return $this->users->find(fn(User $user) => $user->getEmail() === User::normalizeEmail($email)) ?? throw UserException::NotFound($email);
    }

    public function emailExists(string $email): bool
    {
        return $this->users->find(fn(User $user) => $user->getEmail() === User::normalizeEmail($email)) !== null;
    }

    public function save(User $user): void
    {
        $this->users[$user->getId()] = $user;
    }

    public function recordLogin(string $userId, DateTimeImmutable $at): void
    {
        $this->recordLoginCalls[] = [$userId, $at];

        // Comme la base : un compte supprimé entre-temps ne reçoit rien, et la date ne recule jamais
        ($this->users[$userId] ?? null)?->recordLogin($at);
    }

    public function delete(User $user): void
    {
        unset($this->users[$user->getId()]);
    }

    public function findAll(): Tab
    {
        return $this->users;
    }
}
