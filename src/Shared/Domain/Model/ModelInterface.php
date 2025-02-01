<?php

namespace Repas\Shared\Domain\Model;


interface ModelInterface
{
    public function getId(): string;

    public static function load(array $datas): ModelInterface;

    public function toArray(): array;
}
