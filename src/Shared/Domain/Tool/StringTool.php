<?php

namespace Repas\Shared\Domain\Tool;


use Symfony\Component\String\Slugger\AsciiSlugger;

class StringTool
{
    public static function camelCaseToSnakeCase(string $stringInCamelCase): string
    {
        // Ajoute un underscore avant chaque majuscule, sauf pour la première lettre, puis met la chaîne en majuscules
        return strtoupper(preg_replace('/(?<!^)([A-Z])/', '_$1', $stringInCamelCase));
    }

    public static function slugify(string $text): string
    {
        $slugger = new AsciiSlugger();
        return strtolower($slugger->slug($text));
    }
}
