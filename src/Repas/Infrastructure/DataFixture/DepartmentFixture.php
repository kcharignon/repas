<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Infrastructure\Entity\Department as DepartmentEntity;

class DepartmentFixture extends Fixture
{
    const DEPARTMENTS = [
        [
            "name" => "fruit & légume",
            "image" => "https://cdn-icons-png.flaticon.com/128/1147/1147832.png",
        ],
        [
            "name" => "fromage",
            "image" => "https://cdn-icons-png.flaticon.com/128/3753/3753482.png",
        ],
        [
            "name" => "yaourt",
            "image" => "https://cdn-icons-png.flaticon.com/128/1047/1047510.png",
        ],
        [
            "name" => "gâteau",
            "image" => 'https://cdn-icons-png.flaticon.com/128/2435/2435678.png',
        ],
        [
            "name" => "bébé",
            "image" => "images/department/bebe.png",
        ],
        [
            "name" => "apéritif",
            "image" => "https://cdn-icons-png.flaticon.com/128/3814/3814603.png",
        ],
        [
            "name" => "viande",
            "image" => "https://cdn-icons-png.flaticon.com/128/1134/1134447.png",
        ],
        [
            "name" => "alcool",
            "image" => "https://cdn-icons-png.flaticon.com/128/920/920541.png",
        ],
        [
            "name" => "boisson",
            "image" => "https://cdn-icons-png.flaticon.com/128/2405/2405597.png",
        ],
        [
            "name" => "surgelé",
            "image" => "https://cdn-icons-png.flaticon.com/128/3082/3082004.png",
        ],
        [
            "name" => "conserve",
            "image" => "https://cdn-icons-png.flaticon.com/128/2916/2916046.png",
        ],
        [
            "name" => "aide pâtissier",
            "image" => "https://cdn-icons-png.flaticon.com/128/992/992767.png",
        ],
        [
            "name" => "céréale",
            "image" => "https://cdn-icons-png.flaticon.com/128/5009/5009812.png",
        ],
        [
            "name" => "traiteur",
            "image" => "images/department/traiteur.png",
        ],
        [
            "name" => "produit ménager",
            "image" => "https://cdn-icons-png.flaticon.com/128/3899/3899392.png",
        ],
        [
            "name" => "boulangerie",
            "image" => "https://cdn-icons-png.flaticon.com/128/3081/3081918.png",
        ],
        [
            "name" => "sauce",
            "image" => "https://cdn-icons-png.flaticon.com/128/3106/3106132.png",
        ],
        [
            "name" => "animal",
            "image" => "https://cdn-icons-png.flaticon.com/128/2619/2619232.png",
        ],
        [
            "name" => "divers",
            "image" => "https://cdn-icons-png.flaticon.com/128/699/699044.png",
        ],
        [
            "name" => "cuisine du monde",
            "image" => "https://cdn-icons-png.flaticon.com/128/1717/1717511.png",
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::DEPARTMENTS as $departmentData) {
            $departmentModel = Department::create(
                $departmentData['name'],
                $departmentData['image'],
            );

            $departmentEntity = DepartmentEntity::fromModel($departmentModel);
            $manager->persist($departmentEntity);

            $this->addReference($departmentEntity->getSlug(),$departmentEntity);
        }

        $manager->flush();
    }
}
