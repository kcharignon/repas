<?php

namespace Repas\Repas\Domain\Model;


class Ingredient
{
    private string $id;

    private string $name;

    private string $image;

    private Unit $defaultUnit;

    private bool $defaultUniteSplittable;
}
