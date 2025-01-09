<?php

namespace Repas\Tests\Builder;


use Repas\User\Domain\Model\User;

class UserBuilder implements Builder
{
    private string $email;
    private array $roles;
    private string $password;

    private function initialize(): void
    {
        $this->email ??= "johndoe@example.com";
        $this->roles ??= [];
        // Password hashed : Test
        $this->password ??= '$2y$13$0LFXi/NjQ/Ic36vx5MLGqu5kidPlsWf.xctG6xXNs1YYFiMLLLMym';
    }

    public function build(): User
    {
        $this->initialize();
        return User::load([
            'email' => $this->email,
            'roles' => $this->roles,
            'password' => $this->password
        ]);
    }

    public function withEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }
}
