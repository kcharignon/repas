<?php

namespace Repas\Shared\Domain\Model;


interface ModelInterface
{
    public static function load(array $datas): static;

    public function toArray(): array;
}
