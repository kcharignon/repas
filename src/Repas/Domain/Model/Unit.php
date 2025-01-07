<?php

namespace Repas\Repas\Domain\Model;


class Unit
{
    private string $id;

    private string $name;

    private string $abbreviation;

    private Conversion $conversions;
}
