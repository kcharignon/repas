<?php

namespace Repas\User\Infrastructure\DataFixture;


use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Repas\User\Domain\Model\UserStatus;
use Repas\User\Infrastructure\Entity\User as UserEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

class UserFixture extends Fixture implements FixtureGroupInterface
{
    private const array USERS = [
        [
            "email" => "alexiane.sichi@gmail.com",
            "roles" => ["ROLE_USER"],
            "password" => "logan",
            "fullname" => "Alexiane Sichi",
            "recipes" => [],
            "default_serving" => 2,
            "status" => UserStatus::ACTIVE,
            "statistics" => [
                "recipes" => 73,
                "ingredients" => 15,
            ]
        ],
        [
            "email" => "kantincharignon@gmail.com",
            "roles" => ["ROLE_USER", "ROLE_ADMIN"],
            "password" => null,
            "fullname" => "Kantin C.",
            "recipes" => [],
            "default_serving" => 5,
            "status" => UserStatus::ACTIVE,
            "statistics" => [
                "recipes" => 0,
                "ingredients" => 0,
            ]
        ],
        [
            "email" => "john.doe@gmail.com",
            "roles" => ["ROLE_USER"],
            "password" => "hjfauendfiwp",
            "fullname" => "John Doe",
            "recipes" => [],
            "default_serving" => 2,
            "status" => UserStatus::DISABLED,
            "statistics" => [
                "recipes" => 486,
                "ingredients" => 311,
            ]
        ],
    ];

    public static function getGroups(): array
    {
        return ['test', 'dev'];
    }

    /**
     * @param string|null $adminPassword `ADMIN_PASSWORD`, défini par `.env.dev` (développement) et `.env.test` (tests), jamais par `.env`
     */
    public function __construct(
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        #[Autowire(env: 'default::ADMIN_PASSWORD')]
        private ?string $adminPassword,
    ) {
    }


    public function load(ObjectManager $manager): void
    {
        // Avant d'écrire quoi que ce soit : sans mot de passe défini, le compte administrateur n'est pas créé
        $adminPassword = $this->adminPassword;
        if ($adminPassword === null || $adminPassword === '') {
            throw new LogicException("ADMIN_PASSWORD n'est pas défini : le mot de passe du compte administrateur des fixtures vient de .env.dev, .env.test ou .env.local.");
        }

        foreach (self::USERS as $user) {
            $passwordHashed = $this->getPasswordHashed($user['password'] ?? $adminPassword);

            $userModel = User::create(
                UuidGenerator::new(),
                $user['email'],
                $user['roles'],
                $passwordHashed,
                $user['default_serving'],
                new DateTimeImmutable(),
            );

            $userModel->setStatus($user['status']);

            $userModel->setStatistics([
                'createdAt' => $userModel->getStatistics()['createdAt'],
                'recipes' => $user['statistics']['recipes'],
                'ingredients' => $user['statistics']['ingredients'],
            ]);
            $userEntity = UserEntity::fromModel($userModel);
            $manager->persist($userEntity);

            $this->addReference($userEntity->getEmail(), $userEntity);
        }

        $manager->flush();
    }

    private function getPasswordHashed(string $password): string
    {
        return $this
            ->passwordHasherFactory
            ->getPasswordHasher(User::class)
            ->hash($password)
        ;
    }

}
