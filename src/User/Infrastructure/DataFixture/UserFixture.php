<?php

namespace Repas\User\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

class UserFixture extends Fixture
{
    const array USERS = [
        [
            "email" => "alexiane.sichi@gmail.com",
            "roles" => ["ROLE_USER"],
            "password" => "test",
            "fullname" => "Alexiane Sichi",
            "recipes" => [],
        ],
        [
            "email" => "kantincharignon@gmail.com",
            "roles" => ["ROLE_ADMIN"],
            "password" => "test",
            "fullname" => "Kantin C.",
            "recipes" => [],
        ],
    ];

    public function __construct(
        private PasswordHasherFactoryInterface $passwordHasherFactory,
    ) {
    }


    public function load(ObjectManager $manager): void
    {
        foreach (self::USERS as $user) {
            $passwordHashed = $this
                ->passwordHasherFactory
                ->getPasswordHasher(User::class)
                ->hash($user['password'])
            ;

            $userModel = User::create(
                UuidGenerator::new(),
                $user['email'],
                $user['roles'],
                $passwordHashed
            );

            $userEntity = UserEntity::fromModel($userModel);
            $manager->persist($userEntity);

            $this->addReference($userEntity->getId(), $userEntity);
        }

        $manager->flush();
    }

}
