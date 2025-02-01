<?php

namespace Repas\Tests\Repas\Repository;


use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Tests\Helper\DatabaseTestCase;

class ConversionRepositoryTest extends DatabaseTestCase
{
    private ConversionRepository $conversionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversionRepository = static::getContainer()->get(ConversionRepository::class);
    }

    public function testCRUD(): void
    {
        // Arrange
    }


}
