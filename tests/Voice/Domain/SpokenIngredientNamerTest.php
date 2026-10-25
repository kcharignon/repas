<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Le nom du produit à créer d'après la phrase entendue (ADR 0029 décision 11, VOX-303) : sans article, en minuscules, borné, jamais sans lettre.
 */
class SpokenIngredientNamerTest extends TestCase
{
    #[DataProvider('phrases')]
    public function testItNamesTheProductFromThePhrase(string $phrase, ?string $expected): void
    {
        $this->assertSame($expected, new SpokenIngredientNamer()->nameFrom($phrase));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function phrases(): iterable
    {
        yield '« de la ciboulette »' => ['de la ciboulette', 'ciboulette'];
        yield '« du pain de mie »' => ['du pain de mie', 'pain de mie'];
        yield '« des tomates cerises »' => ['des tomates cerises', 'tomates cerises'];
        yield '« un melon »' => ['un melon', 'melon'];
        yield '« une courgette »' => ['une courgette', 'courgette'];
        yield '« le persil »' => ['le persil', 'persil'];
        yield '« la ciboulette »' => ['la ciboulette', 'ciboulette'];
        yield '« les haricots »' => ['les haricots', 'haricots'];
        yield '« de l\'ail »' => ['de l\'ail', 'ail'];
        yield '« d\'ail »' => ['d\'ail', 'ail'];
        yield '« l\'ail »' => ['l\'ail', 'ail'];
        yield 'apostrophe typographique' => ['de l’échalote', 'échalote'];
        yield 'plusieurs articles de suite' => ['de la la ciboulette', 'ciboulette'];
        yield 'majuscules' => ['De la CIBOULETTE', 'ciboulette'];
        yield 'espaces et tabulations' => ["  du \t pain   complet ", 'pain complet'];
        yield 'sans article' => ['ciboulette', 'ciboulette'];
        yield 'un article au milieu reste' => ['pain de mie', 'pain de mie'];
        yield 'un nom qui commence comme un article' => ['lardons', 'lardons'];
        yield 'un nom qui commence par « un »' => ['unagi', 'unagi'];
        yield 'un tiret reste' => ['chocolat au lait', 'chocolat au lait'];
        yield 'nombre dans le nom' => ['sauce 4 fromages', 'sauce 4 fromages'];
        yield 'signes retirés' => ['de la ciboulette !', 'ciboulette'];
        yield 'balises retirées' => ['<speak>ciboulette</speak>', 'speak ciboulette speak'];
        yield 'un article seul reste le nom' => ['la', 'la'];
        yield 'aucune lettre' => ['12', null];
        yield 'signes seulement' => ['?!', null];
        yield 'vide' => ['', null];
    }

    public function testANameOfSixtyCharactersFitsAndSixtyOneIsRefused(): void
    {
        $namer = new SpokenIngredientNamer();

        $this->assertSame(str_repeat('a', 60), $namer->nameFrom(str_repeat('a', 60)));
        $this->assertNull($namer->nameFrom(str_repeat('a', 61)));
    }

    public function testTheLengthIsCountedAfterTheArticleIsRemoved(): void
    {
        $this->assertSame(str_repeat('a', 60), new SpokenIngredientNamer()->nameFrom('de la ' . str_repeat('a', 60)));
    }

    /**
     * La création repasse le nom de l'état par ce normalisateur (l'état est une entrée non fiable) : un nom déjà normalisé doit rester tel quel.
     */
    #[DataProvider('phrases')]
    public function testANameAlreadyNormalisedIsLeftUnchanged(string $phrase, ?string $ignored): void
    {
        $namer = new SpokenIngredientNamer();
        $name = $namer->nameFrom($phrase);

        if ($name === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame($name, $namer->nameFrom($name));
    }
}
