<?php

namespace Repas\Tests\Helper\Builder;


use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus as Status;

class UserBuilder implements Builder
{
    private string $email;
    private array $roles;
    private string $password;
    private string $id;
    private int $defaultServing;
    private Status $status;

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->email ??= "{$this->id}@example.com";
        $this->roles ??= ['ROLE_USER'];
        $this->password ??= '$2y$13$0LFXi/NjQ/Ic36vx5MLGqu5kidPlsWf.xctG6xXNs1YYFiMLLLMym';// Password hashed : Test
        $this->defaultServing ??= 4;
        $this->status ??= Status::ACTIVE;
    }

    public function build(): User
    {
        $this->initialize();
        return User::load([
            'id' => $this->id,
            'email' => $this->email,
            'roles' => $this->roles,
            'password' => $this->password,
            'default_serving' => $this->defaultServing,
            'status' => $this->status,
        ]);
    }

    public function withEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function isAdmin(): static
    {
        $this->roles = ['ROLE_USER', 'ROLE_ADMIN'];
        return $this;
    }

    public function withId(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function withServing(int $serving): self
    {
        $this->defaultServing = $serving;
        return $this;
    }
}
