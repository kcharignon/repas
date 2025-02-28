<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Domain\Tool\UuidGenerator;

class ControlledUuidGenerator extends UuidGenerator
{
    public const string UUID = "unique-id";
    public array $ids;
    private int $index = 0;

    public function __construct(array $ids = [])
    {
        foreach ($ids as $id) {
            $this->ids[] = ["id" => $id, "used" => false];
        }
    }


    public static function new(): string
    {
        return static::UUID;
    }

    public function generate(): string
    {
        return $this->ids[$this->index]['id'] ?? parent::generate();

    }
}
