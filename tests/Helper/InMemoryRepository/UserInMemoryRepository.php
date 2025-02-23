<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Shared\Domain\Tool\Tab;
use Repas\User\Domain\Exception\UserException;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class UserInMemoryRepository implements UserRepository
{
    /** @var Tab<User>  */
    private Tab $users;

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
        return $this->users->find(fn(User $user) => $user->getEmail() === $email) ?? throw UserException::NotFound($email);
    }

    public function save(User $user): void
    {
        $this->users[$user->getId()] = $user;
    }

    public function findAll(): Tab
    {
        return $this->users;
    }
}
