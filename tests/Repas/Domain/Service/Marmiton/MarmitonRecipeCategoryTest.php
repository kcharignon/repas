<?php

namespace Repas\Tests\Repas\Domain\Service\Marmiton;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\Marmiton\MarmitonRecipeCategory;

class MarmitonRecipeCategoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function categories(): iterable
    {
        yield 'entrée' => ['Entrée', 'entree'];
        yield 'entree sans accent' => ['entree', 'entree'];
        yield 'entrées au pluriel' => ['ENTRÉES', 'entree'];
        yield 'plat principal' => ['Plat principal', 'plat'];
        yield 'plat' => ['plat', 'plat'];
        yield 'accompagnement' => ['Accompagnement', 'plat'];
        yield 'sauce' => ['Sauce', 'plat'];
        yield 'dessert' => ['Dessert', 'dessert'];
        yield 'dessert en majuscules' => ['DESSERT', 'dessert'];
        yield 'confiserie' => ['Confiserie', 'dessert'];
        yield 'amuse-gueule' => ['Amuse-gueule', 'apero'];
        yield 'amuse gueule sans tiret' => ['amuse gueule', 'apero'];
        yield 'boisson' => ['Boisson', 'boisson'];
        yield 'espaces autour' => ["  Dessert \n", 'dessert'];
        yield 'catégorie inconnue' => ['Plat végétarien', 'plat'];
        yield 'vide' => ['', 'plat'];
        yield 'absente' => [null, 'plat'];
        yield 'trop longue' => [str_repeat('dessert', 1_000), 'plat'];
    }

    #[DataProvider('categories')]
    public function testTypeSlug(?string $category, string $expected): void
    {
        // When / Then
        $this->assertSame($expected, MarmitonRecipeCategory::typeSlug($category));
    }

    public function testEveryTypeIsAReferenceRecipeType(): void
    {
        // Given
        $types = json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/src/Repas/Infrastructure/DataFixture/Data/recipe_type.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $slugs = array_map(static fn(array $type): string => $type['slug'], $types);

        // When / Then : tout type rendu existe dans les données de référence
        foreach (['Entrée', 'Plat principal', 'Dessert', 'Amuse-gueule', 'Boisson', 'Inconnue'] as $category) {
            $this->assertContains(MarmitonRecipeCategory::typeSlug($category), $slugs, $category);
        }
    }
}
