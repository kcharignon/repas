<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Service\SpokenNumber;

/**
 * Le lecteur de nombres dits (ADR 0029 décision 13, VOX-305) : le même pour le nombre de personnes d'un repas et pour le nombre laissé en tête d'un
 * produit. Des chiffres jusqu'à trois, des mots de « un » à « quatre vingt dix neuf » (avec ou sans trait d'union), « cent ».
 */
class SpokenNumberTest extends TestCase
{
    #[DataProvider('numbers')]
    public function testItReadsDigitsAndWords(string $said, ?int $expected): void
    {
        $this->assertSame($expected, new SpokenNumber()->parse($said), $said);
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function numbers(): iterable
    {
        yield '0' => ['0', 0];
        yield '6' => ['6', 6];
        yield '99' => ['99', 99];
        yield '100' => ['100', 100];
        yield '999' => ['999', 999];
        yield 'quatre chiffres' => ['1000', null];
        yield 'zéro' => ['zéro', 0];
        yield 'un' => ['un', 1];
        yield 'une' => ['une', 1];
        yield 'deux' => ['deux', 2];
        yield 'seize' => ['seize', 16];
        yield 'dix sept' => ['dix sept', 17];
        yield 'dix-sept' => ['dix-sept', 17];
        yield 'vingt' => ['vingt', 20];
        yield 'vingt et un' => ['vingt et un', 21];
        yield 'vingt-et-un' => ['vingt-et-un', 21];
        yield 'vingt deux' => ['vingt deux', 22];
        yield 'trente' => ['trente', 30];
        yield 'trente et un' => ['trente et un', 31];
        yield 'quarante cinq' => ['quarante cinq', 45];
        yield 'cinquante' => ['cinquante', 50];
        yield 'soixante' => ['soixante', 60];
        yield 'soixante et onze' => ['soixante et onze', 71];
        yield 'soixante dix' => ['soixante dix', 70];
        yield 'soixante quinze' => ['soixante quinze', 75];
        yield 'quatre vingts' => ['quatre vingts', 80];
        yield 'quatre vingt' => ['quatre vingt', 80];
        yield 'quatre vingt un' => ['quatre vingt un', 81];
        yield 'quatre vingt dix' => ['quatre vingt dix', 90];
        yield 'quatre vingt dix neuf' => ['quatre vingt dix neuf', 99];
        yield 'cent' => ['cent', 100];
        yield 'majuscules et espaces' => ['  Quatre   VINGT  dix neuf ', 99];
        yield 'un mot qui n\'est pas un nombre' => ['pain', null];
        yield 'un nombre suivi d\'un mot' => ['six personnes', null];
        yield 'des chiffres et des lettres' => ['6e', null];
        yield 'négatif' => ['-3', null];
        yield 'décimal' => ['1.5', null];
        yield 'vide' => ['', null];
    }

    public function testEveryNumberFromOneToNinetyNineWrittenLikeTheInteractionModelIsRead(): void
    {
        // Given : les 99 valeurs du type QUANTITY du modèle d'interaction, écrites par le générateur (une valeur = un nombre en lettres)
        $model = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/docs/voice/skill/interaction-model.fr-FR.json'), true, 512, JSON_THROW_ON_ERROR);
        $type = array_values(array_filter($model['interactionModel']['languageModel']['types'], static fn(array $type): bool => $type['name'] === 'QUANTITY'))[0];

        foreach ($type['values'] as $value) {
            // Then : le lecteur du webhook lit chaque mot du modèle comme l'identifiant que le modèle lui donne
            foreach ([$value['name']['value'], ...$value['name']['synonyms']] as $word) {
                $this->assertSame((int) $value['id'], new SpokenNumber()->parse($word), $word);
            }
        }
    }
}
