<?php

namespace Repas\Repas\Domain\Model;


class Conversion
{
    private string $id;

    private Unit $startUnit;

    private Unit $endUnit;

    private float $coefficient;
}
