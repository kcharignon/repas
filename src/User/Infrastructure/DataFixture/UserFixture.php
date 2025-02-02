<?php

namespace Repas\User\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
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
            "default_serving" => 4,
        ],
        [
            "email" => "kantincharignon@gmail.com",
            "roles" => ["ROLE_USER", "ROLE_ADMIN"],
            "password" => null,
            "fullname" => "Kantin C.",
            "recipes" => [],
            "default_serving" => 5,
        ],
    ];

    public static function getGroups(): array
    {
        return ['prod', 'test', 'dev'];
    }

    public function __construct(
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        private ContainerBagInterface $containerBag,
    ) {
    }


    public function load(ObjectManager $manager): void
    {
        foreach (self::USERS as $user) {
            $passwordHashed = $this->getPasswordHashed($user['password']);

            $userModel = User::create(
                UuidGenerator::new(),
                $user['email'],
                $user['roles'],
                $passwordHashed,
                $user['default_serving'],
            );

            $userEntity = UserEntity::fromModel($userModel);
            $manager->persist($userEntity);

            $this->addReference($userEntity->getEmail(), $userEntity);
        }

        $manager->flush();
    }

    private function getPasswordHashed(?string $password): string
    {
        $password ??= $this->containerBag->get('env(ADMIN_PASSWORD)');

        return $this
            ->passwordHasherFactory
            ->getPasswordHasher(User::class)
            ->hash($password)
        ;
    }

}
