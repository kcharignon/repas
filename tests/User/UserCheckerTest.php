<?php

namespace Repas\Tests\User;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Exception\EmailNotVerifiedException;
use Repas\User\Domain\Service\UserChecker;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

class UserCheckerTest extends TestCase
{
    private UserChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new UserChecker();
    }

    public function testAPendingAccountIsRefusedOnlyAfterThePasswordIsChecked(): void
    {
        // Given
        $user = new UserBuilder()->isPending()->build();

        // Then : avant le mot de passe, rien ne trahit l'état du compte
        $this->checker->checkPreAuth($user);
        $this->expectException(EmailNotVerifiedException::class);
        $this->expectExceptionMessage('EMAIL_NOT_VERIFIED');

        // When : après le mot de passe
        $this->checker->checkPostAuth($user);
    }

    public function testAnActiveAccountPasses(): void
    {
        // Given
        $user = new UserBuilder()->build();

        // When
        $this->checker->checkPreAuth($user);
        $this->checker->checkPostAuth($user);

        // Then
        $this->addToAssertionCount(1);
    }

    public function testADisabledAccountIsRefusedBeforeThePasswordIsChecked(): void
    {
        // Assert
        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Ton compte est désactivé.');

        // When
        $this->checker->checkPreAuth(new UserBuilder()->isDisabled()->build());
    }
}
