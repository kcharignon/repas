<?php

namespace Repas\Tests\User;


use PHPUnit\Framework\TestCase;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Service\UserProvider;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

class UserProviderTest extends TestCase
{
    private UserInMemoryRepository $userRepository;
    private UserProvider $provider;

    protected function setUp(): void
    {
        $this->userRepository = new UserInMemoryRepository([new UserBuilder()->withId('user-id')->withEmail('alexiane@example.com')->withPassword('ancien-hash')->build()]);
        $this->provider = new UserProvider($this->userRepository);
    }

    public function testUpgradedPasswordHashIsSaved(): void
    {
        // Given : Symfony recalcule le hash à la connexion (nouvel algorithme ou nouveau coût) et le confie au provider
        $user = $this->provider->loadUserByIdentifier('alexiane@example.com');
        assert($user instanceof User);

        // When
        $this->provider->upgradePassword($user, 'nouveau-hash');

        // Then : le hash survit à la requête (il serait perdu s'il n'était qu'en mémoire)
        $this->assertSame('nouveau-hash', $this->userRepository->findOneById('user-id')->getPassword());
        // Then : mais ce n'est pas un changement de mot de passe, les jetons émis restent valables
        $this->assertSame(0, $this->userRepository->findOneById('user-id')->getCredentialsVersion());
    }

    public function testRefusesAUserOfAnotherType(): void
    {
        // Then
        $this->expectException(UnsupportedUserException::class);

        // When
        $this->provider->upgradePassword(new InMemoryUser('x', 'y'), 'hash');
    }

    public function testLoadsAUserByEmailWhateverTheCase(): void
    {
        // When
        $user = $this->provider->loadUserByIdentifier('  Alexiane@Example.COM ');
        assert($user instanceof User);

        // Then
        $this->assertSame('user-id', $user->getId());
    }

    public function testUnknownEmailIsNotFound(): void
    {
        // Then
        $this->expectException(UserNotFoundException::class);

        // When
        $this->provider->loadUserByIdentifier('inconnu@example.com');
    }
}
