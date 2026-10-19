<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Tests\Helper\Builder\Builder;
use Repas\User\Application\Query\GetCurrentUser\AppUpdateDTO;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;

class CurrentUserDTOBuilder implements Builder
{
    private string $id;
    private string $email;
    /** @var list<string> */
    private array $roles;
    private bool $isAdmin;
    private int $defaultServing;
    private AppUpdateDTO $appUpdate;

    private function initialize(): void
    {
        $this->id ??= '0b7a6f0e-5c1d-4c52-9f0e-3f3c1d8b2a11';
        $this->email ??= 'alexiane@example.com';
        $this->roles ??= ['ROLE_USER'];
        $this->isAdmin ??= in_array('ROLE_ADMIN', $this->roles, true);
        $this->defaultServing ??= 4;
        $this->appUpdate ??= new AppUpdateDTO(null, null, false);
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function isAdmin(): self
    {
        $this->roles = ['ROLE_USER', 'ROLE_ADMIN'];
        $this->isAdmin = true;
        return $this;
    }

    public function withDefaultServing(int $defaultServing): self
    {
        $this->defaultServing = $defaultServing;
        return $this;
    }

    public function withAppUpdate(?string $latest, ?string $minimum, bool $outdated): self
    {
        $this->appUpdate = new AppUpdateDTO($latest, $minimum, $outdated);
        return $this;
    }

    public function build(): CurrentUserDTO
    {
        $this->initialize();
        return new CurrentUserDTO($this->id, $this->email, $this->roles, $this->isAdmin, $this->defaultServing, $this->appUpdate);
    }
}
