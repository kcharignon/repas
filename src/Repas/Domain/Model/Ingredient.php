<?php

namespace Repas\Repas\Domain\Model;


class Ingredient
{
    private string $id;

    private string $nom;

    private string $image;

    private Unite $uniteParDefaut;

    private bool $uniteParDefautSecable;
}
