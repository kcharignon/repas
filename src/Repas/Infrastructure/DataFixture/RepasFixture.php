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
        $insideObject = false; // Pour savoir si on est en train de lire un objet JSON

        while (($char = fgetc($handle)) !== false) {
            if ($char === '[' && !$insideObject) {
                continue; // On ignore le début du tableau
            }
            if ($char === ']' && !$insideObject) {
                break; // Fin du tableau, on stoppe la lecture
            }
            if ($char === '{') {
                $insideObject = true;
            }

            if ($insideObject) {
                $buffer .= $char;
            }

            // Quand on trouve `}`, on vérifie si l'objet JSON est complet
            if ($char === '}') {
                $decodedObject = json_decode($buffer, true);
                if ($decodedObject !== null) {
                    yield $decodedObject;
                    $buffer = ''; // Réinitialisation du buffer
                    $insideObject = false; // On attend le prochain objet JSON
                }
            }
        }

        fclose($handle);
    }
}
