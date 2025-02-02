<?php

namespace Repas\Tests\Builder;


use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class UserBuilder implements Builder
{
    private string $email;
    private array $roles;
    private string $password;
    private string $id;
    private int $defaultServing;

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->email ??= "johndoe@example.com";
        $this->roles ??= [];
        // Password hashed : Test
        $this->password ??= '$2y$13$0LFXi/NjQ/Ic36vx5MLGqu5kidPlsWf.xctG6xXNs1YYFiMLLLMym';
        $this->defaultServing ??= 4;
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
        ]);
    }

    public function withEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }
}
