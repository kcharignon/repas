<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Repas\Repas\Infrastructure\Entity\Unit as UnitEntity;

class UnitFixture extends RepasFixture implements FixtureGroupInterface
{
    const string FILE_NAME = "unit.json";

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
            foreach ($this->readFileObjectByObject($filePath) as $unitData) {
                $unitEntity = new UnitEntity(
                    slug : $unitData["slug"],
                    name: $unitData["name"],
                    symbol: $unitData["symbol"],
                );
                $manager->persist($unitEntity);

                $this->addReference($unitEntity->getSlug(), $unitEntity);
            }

            $manager->flush();
        } catch (Exception $e) {
            dump(sprintf("Failed to create Unit: %s", $unitData["name"] ?? 'Unknown'));
            throw $e;
        }
    }
}
