<?php

namespace Repas\Tests\Repas;


use Repas\Repas\Domain\Interface\ShoppingListRepository as ShoppingListRepositoryInterface;
use Repas\Repas\Infrastructure\Repository\ShoppingListPostgreSQLRepository;
use Repas\Tests\Helper\DatabaseTestCase;

class ShoppingListRepository extends DatabaseTestCase
{
    private ShoppingListRepositoryInterface $shoppingListRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $managerRegistry = static::getContainer()->get('doctrine');

        $this->shoppingListRepository = new ShoppingListPostgreSQLRepository($managerRegistry);
    }
}
