<?php

namespace Repas\Shared\Domain\Tool;

use InvalidArgumentException;
use Random\RandomException;
use Symfony\Component\String\Slugger\AsciiSlugger;

class StringTool
{


    const int ALPHABET_ALPHA_NUMERIC = 0;
    const int ALPHABET_NUMERIC = 1;
    const int ALPHABET_HEXA = 2;

    public static function camelCaseToUpperSnakeCase(string $stringInCamelCase): string
    {
        // Appel camelCaseToSnakeCase, puis met la chaîne en minuscule
        return strtoupper(self::camelCaseToSnakeCase($stringInCamelCase));
    }

    public static function camelCaseToLowerSnakeCase(string $stringInCamelCase, ): string
    {
        // Appel camelCaseToSnakeCase, puis met la chaîne en majuscules
        return strtolower(self::camelCaseToSnakeCase($stringInCamelCase));
    }

    private static function camelCaseToSnakeCase(string $stringInCamelCase, ): string
    {
        // Ajoute un underscore avant chaque majuscule, sauf pour la première lettre
        return preg_replace('/(?<!^)([A-Z])/', '_$1', $stringInCamelCase);
    }

    public static function slugify(string $text): string
    {
        $symboleMap = ['fr' => [
            '&' => 'et',
            '@' => 'at',
        ]];

        $slugger = new AsciiSlugger('fr_FR', $symboleMap);
        return strtolower($slugger->slug($text));
    }

    /**
     * @throws RandomException
     */
    public static function generateRandomString(int $length, string|int $alphabet = 0): string
    {
        if ($length <= 0) {
            throw new InvalidArgumentException('Length must be a positive integer.');
        }

        // Conversion de l'alphabet si c'est un entier
        if (is_int($alphabet)) {
            $alphabet = match ($alphabet) {
                self::ALPHABET_NUMERIC => '0123456789',
                self::ALPHABET_HEXA => '0123456789abcdef',
                self::ALPHABET_ALPHA_NUMERIC => 'abcdefghijklmnopqrstuvwxyz0123456789',
                default => throw new InvalidArgumentException('Invalid alphabet identifier.'),
            };
        }

        // Vérification que l'alphabet est une chaîne non vide
        if (!is_string($alphabet) || $alphabet === '') {
            throw new InvalidArgumentException('Alphabet must be a non-empty string.');
        }

        $randomString = '';
        $maxIndex = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $alphabet[random_int(0, $maxIndex)];
        }

        return $randomString;
    }

    static function upperCaseFirst($string): string
    {
        return mb_strtoupper(mb_substr($string, 0, 1), 'UTF-8').mb_substr($string, 1);
    }
}
