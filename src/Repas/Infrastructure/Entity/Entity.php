<?php

namespace Repas\Repas\Infrastructure\Entity;


use Repas\Shared\Domain\Model\ModelInterface;

interface Entity
{
    public function getId(): string;

    public function updateFromModel(ModelInterface $model): void;

    public static function fromModel(ModelInterface $model): Entity;
}
