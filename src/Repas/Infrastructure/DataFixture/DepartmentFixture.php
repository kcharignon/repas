<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Repas\Repas\Infrastructure\Entity\Department as DepartmentEntity;

class DepartmentFixture extends RepasFixture implements FixtureGroupInterface
{
    const string FILE_NAME = 'department.json';

    public static function getGroups(): array
    {
        return ['prod', 'test', 'dev'];
    }

    /**
     * @throws Exception
     */
    public function load(ObjectManager $manager): void
    {
        $filePath = $this->getFilePath(self::FILE_NAME);

        try {
            foreach ($this->readFileObjectByObject($filePath) as $departmentData) {
                $departmentEntity = new DepartmentEntity(
                    slug: $departmentData['slug'],
                    name: $departmentData['name'],
                    image: $departmentData['image'],
                );
                $manager->persist($departmentEntity);

                $this->addReference($departmentEntity->getSlug(),$departmentEntity);
            }

            $manager->flush();
        } catch (Exception $e) {
            dump(sprintf("Failed to create Department: %s", $departmentData["name"] ?? 'Unknown'));
            throw $e;
        }
    }
}
