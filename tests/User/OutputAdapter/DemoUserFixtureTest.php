<?php

namespace Repas\Tests\User\OutputAdapter;


use Doctrine\Common\DataFixtures\ReferenceRepository;
use LogicException;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\DataFixture\DemoUserFixture;
use Repas\User\Infrastructure\Entity\User as UserEntity;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Les comptes de démonstration (`make fixtures-demo`) : développement seulement, mot de passe connu, idempotents.
 */
class DemoUserFixtureTest extends DatabaseTestCase
{
    private PasswordHasherFactoryInterface $hasherFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasherFactory = static::getContainer()->get('security.password_hasher_factory');
    }

    private function fixture(string $environment = 'dev'): DemoUserFixture
    {
        $fixture = new DemoUserFixture($this->hasherFactory, $environment);
        $fixture->setReferenceRepository(new ReferenceRepository($this->entityManager));

        return $fixture;
    }

    private function account(string $email): ?UserEntity
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(UserEntity::class)->findOneBy(['email' => $email]);
    }

    public function testCreatesAnAdministratorAndAnOrdinaryUserWhoCanSignIn(): void
    {
        // Act
        $this->fixture()->load($this->entityManager);

        // Assert
        $admin = $this->account('admin@repas.fr');
        $user = $this->account('user@repas.fr');
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $admin->getRoles());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $hasher = $this->hasherFactory->getPasswordHasher(User::class);
        $this->assertTrue($hasher->verify($admin->getPassword(), 'M0t 2 p@sse'));
        $this->assertTrue($hasher->verify($user->getPassword(), 'M0t 2 p@sse'));
        $this->assertFalse($hasher->verify($user->getPassword(), 'autre'));
    }

    public function testRunningTwiceNeitherDuplicatesNorChangesAnAccount(): void
    {
        // Arrange
        $this->fixture()->load($this->entityManager);
        $hash = $this->account('user@repas.fr')->getPassword();

        // Act
        $this->fixture()->load($this->entityManager);

        // Assert
        $this->entityManager->clear();
        $this->assertCount(2, $this->entityManager->getRepository(UserEntity::class)->findBy(['email' => ['admin@repas.fr', 'user@repas.fr']]));
        $this->assertSame($hash, $this->account('user@repas.fr')->getPassword());
    }

    public function testRefusesToRunInProduction(): void
    {
        // Assert
        $this->expectException(LogicException::class);

        // Act
        $this->fixture('prod')->load($this->entityManager);
    }

    public function testBelongsToTheDemoGroupOnly(): void
    {
        // Act & Assert : ni `prod`, ni `test`, ni `dev` : on ne le charge que par `--group=demo`
        $this->assertSame(['demo'], DemoUserFixture::getGroups());
    }
}
