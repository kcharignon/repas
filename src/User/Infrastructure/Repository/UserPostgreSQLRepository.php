<?php

namespace Repas\User\Infrastructure\Repository;



use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Tests\Builder\UserBuilder;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

class UserPostgreSQLRepository extends ServiceEntityRepository implements UserRepository
{


    public function __construct(
        private readonly ManagerRegistry $managerRegistry
    ) {
        parent::__construct($this->managerRegistry, User::class);
    }

    public function getUserByEmail(string $email): User
    {
//        $criteria = new Criteria(['email' => $email]);
//        $this->findOneBy($criteria);
//        $this->matching($criteria);

        return (new UserBuilder())
            ->withEmail('kantincharignon@gmail.com')
            ->build();
    }
}
