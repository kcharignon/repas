<?php

namespace Repas\Repas\Domain\Model;


class Conversion
{
    private string $id;

    private Unite $uniteDeDepart;

    private Unite $uniteDArrivee;

    private float $coefficient;
}
