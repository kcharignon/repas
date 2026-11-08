<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\RecipeComponentRules;
use Repas\Tests\Helper\Builder\RecipeBuilder;

/**
 * Ce que la copie d'une recette composée garde de ses recettes utilisées (ADR 0040 décisions 7, 8, 21 et 33 à 35, COMP-003) : `RecipeComponentRules::isKeptByCopy`
 * et `Recipe::isSecretWhenCopiedBy`, écrits une fois pour que la copie et le choix des recettes utilisées jugent la même chose.
 */
class RecipeComponentRulesCopyTest extends TestCase
{
    private const string AUTHOR = 'author-id';
    private const string COPIER = 'copier-id';

    /**
     * @return iterable<string, array{bool, string, bool, string, bool, bool}> original secret, copieur, recette utilisée secrète, auteur de la recette utilisée, gardée ?
     */
    public static function cases(): iterable
    {
        // Un autre compte copie une recette ordinaire : seules les recettes utilisées ordinaires du même auteur sont gardées
        yield 'un autre compte, recette utilisée ordinaire du même auteur' => [false, self::COPIER, false, self::AUTHOR, true];
        yield 'un autre compte, recette utilisée secrète (illisible de lui)' => [false, self::COPIER, true, self::AUTHOR, false];
        yield 'un autre compte, recette utilisée d\'un troisième auteur' => [false, self::COPIER, false, 'third-id', false];
        // L'auteur copie sa recette ordinaire : une recette utilisée secrète n'irait pas sous une copie ordinaire
        yield 'l\'auteur, recette ordinaire, recette utilisée ordinaire' => [false, self::AUTHOR, false, self::AUTHOR, true];
        yield 'l\'auteur, recette ordinaire, recette utilisée secrète' => [false, self::AUTHOR, true, self::AUTHOR, false];
        // L'auteur copie sa recette secrète : la copie est secrète, elle peut en utiliser de toute sorte
        yield 'l\'auteur, recette secrète, recette utilisée ordinaire' => [true, self::AUTHOR, false, self::AUTHOR, true];
        yield 'l\'auteur, recette secrète, recette utilisée secrète' => [true, self::AUTHOR, true, self::AUTHOR, true];
        // La recette d'un autre auteur ne se garde jamais, même ordinaire et lisible
        yield 'l\'auteur, recette utilisée d\'un autre auteur' => [false, self::AUTHOR, false, self::COPIER, false];
    }

    #[DataProvider('cases')]
    public function testACopyKeepsOnlyTheUsedRecipesTheRulesAllow(bool $originalIsSecret, string $copierId, bool $usedIsSecret, string $usedAuthorId, bool $kept): void
    {
        // Given
        $original = new RecipeBuilder()->withId('tart-id')->withAuthorId(self::AUTHOR)->asSecret($originalIsSecret)->build();
        $used = new RecipeBuilder()->withId('dough-id')->withAuthorId($usedAuthorId)->asSecret($usedIsSecret)->build();

        // When & Then
        $this->assertSame($kept, new RecipeComponentRules()->isKeptByCopy($original, $used, $copierId));
    }

    public function testOnlyTheAuthorCopyingASecretRecipeMakesASecretCopy(): void
    {
        // Given
        $secret = new RecipeBuilder()->withAuthorId(self::AUTHOR)->asSecret()->build();
        $ordinary = new RecipeBuilder()->withAuthorId(self::AUTHOR)->build();

        // When & Then
        $this->assertTrue($secret->isSecretWhenCopiedBy(self::AUTHOR));
        $this->assertFalse($secret->isSecretWhenCopiedBy(self::COPIER), 'un autre n\'obtient jamais une copie secrète (et ne peut pas copier la secrète)');
        $this->assertFalse($ordinary->isSecretWhenCopiedBy(self::AUTHOR));
        $this->assertFalse($ordinary->isSecretWhenCopiedBy(self::COPIER));
    }
}
