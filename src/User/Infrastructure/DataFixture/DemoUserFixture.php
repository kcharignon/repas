<?php

namespace Repas\User\Infrastructure\DataFixture;


use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Comptes de démonstration pour essayer le site et le front (`make fixtures-demo`). **Développement seulement** : le mot
 * de passe est public, la fixture refuse de tourner en production et n'appartient à aucun groupe de production.
 * Idempotente : un compte déjà présent n'est ni modifié ni dupliqué.
 */
class DemoUserFixture extends Fixture implements FixtureGroupInterface
{
    public const string PASSWORD = 'M0t 2 p@sse';
    public const string ADMIN_EMAIL = 'admin@repas.fr';
    public const string USER_EMAIL = 'user@repas.fr';

    /** @var array<string, array{roles: list<string>, serving: int}> */
    private const array ACCOUNTS = [
        self::ADMIN_EMAIL => ['roles' => ['ROLE_USER', 'ROLE_ADMIN'], 'serving' => 4],
        self::USER_EMAIL => ['roles' => ['ROLE_USER'], 'serving' => 2],
    ];

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function __construct(
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw new LogicException(sprintf("Les fixtures de démonstration ne tournent qu'en dev (environnement : %s).", $this->environment));
        }

        $hash = $this->passwordHasherFactory->getPasswordHasher(User::class)->hash(self::PASSWORD);
        foreach (self::ACCOUNTS as $email => $account) {
            $entity = $manager->getRepository(UserEntity::class)->findOneBy(['email' => $email]);
            if (!$entity instanceof UserEntity) {
                $entity = UserEntity::fromModel(User::create(UuidGenerator::new(), $email, $account['roles'], $hash, $account['serving'], new DateTimeImmutable()));
                $manager->persist($entity);
            }
            $this->addReference($email, $entity);
        }

        $manager->flush();
    }
}
