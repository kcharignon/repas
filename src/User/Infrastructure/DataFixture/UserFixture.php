<?php

namespace Repas\User\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Entity\User as UserEntity;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

class UserFixture extends Fixture
{
    const array USERS = [
        [
            "email" => "alexiane.sichi@gmail.com",
            "roles" => ["ROLE_USER"],
            "password" => "logan",
            "fullname" => "Alexiane Sichi",
            "recipes" => [],
        ],
        [
            "email" => "kantincharignon@gmail.com",
            "roles" => ["ROLE_ADMIN"],
            "password" => null,
            "fullname" => "Kantin C.",
            "recipes" => [],
        ],
    ];

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
                $passwordHashed
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
