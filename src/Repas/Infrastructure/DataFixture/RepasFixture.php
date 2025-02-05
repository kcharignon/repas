<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\Fixture;
use Generator;

abstract class RepasFixture extends Fixture
{
    protected function getFilePath(string $filename): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . $filename;
    }

    protected function readFileObjectByObject(string $filePath): Generator
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Le fichier $filePath n'existe pas.");
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Impossible d'ouvrir le fichier $filePath.");
        }

        $buffer = '';
        $insideArray = false;

        while (($char = fgetc($handle)) !== false) {
            // Ignorer uniquement le premier '['
            if ($char === '[' && !$insideArray) {
                $insideArray = true;
                continue;
            }

            // Ignorer uniquement le dernier ']'
            if ($char === ']' && feof($handle)) {
                continue;
            }

            // Ajouter le caractère au buffer
            $buffer .= $char;

            // Détecter la fin d'un objet JSON
            if ($char === '}' && json_decode($buffer) !== null) {
                yield json_decode($buffer, true);
                $buffer = ''; // Réinitialiser le buffer après chaque objet JSON
            }
        }

        fclose($handle);
    }
}
