<?php

namespace Repas\Shared\Domain\Model;


interface ModelInterface
{
    public function getId(): string;

    public static function load(array $datas): static;

    public function toArray(): array;
}
