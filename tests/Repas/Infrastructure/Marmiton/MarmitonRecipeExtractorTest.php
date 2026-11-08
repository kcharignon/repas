<?php

namespace Repas\Tests\Repas\Infrastructure\Marmiton;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Infrastructure\Marmiton\MarmitonRecipeExtractor;
use Repas\Tests\Helper\MarmitonPages;

/**
 * Ce qu'on lit d'une page Marmiton (ADR 0037 décisions 1 et 13, MAR-001) : seuls les blocs `ld+json`, un objet `Recipe` lisible en entier,
 * jamais le reste de la page. Aucun accès réseau : des textes HTML.
 */
class MarmitonRecipeExtractorTest extends TestCase
{
    private function extract(string $html): MarmitonRecipePage
    {
        return (new MarmitonRecipeExtractor())->extract($html, MarmitonPages::EXAMPLE_URL);
    }

    private function assertUnreadable(string $html): void
    {
        try {
            $this->extract($html);
            $this->fail('Cette page aurait dû être illisible.');
        } catch (MarmitonImportException $exception) {
            $this->assertSame(MarmitonImportException::RECIPE_UNREADABLE, $exception->getMessage());
        }
    }

    public function testTheRecordedExamplePageGivesItsFactsAndNothingElse(): void
    {
        // When
        $page = $this->extract(MarmitonPages::example());

        // Then
        $this->assertSame(MarmitonPages::EXAMPLE_URL, $page->canonicalUrl);
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $page->title);
        $this->assertSame('6 personnes', $page->servingsText);
        $this->assertSame('Dessert', $page->category);
        $this->assertSame(MarmitonPages::EXAMPLE_LINES, $page->ingredientLines);
        $this->assertCount(13, $page->ingredientLines);
    }

    public function testARecipeAtTheRootOfTheBlockIsRead(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([MarmitonPages::recipe()]));

        // Then
        $this->assertSame(MarmitonPages::EXAMPLE_LINES, $page->ingredientLines);
    }

    public function testARecipeInAListIsRead(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([[['@type' => 'WebSite', 'name' => 'Marmiton'], MarmitonPages::recipe(['name' => 'Dans une liste'])]]));

        // Then
        $this->assertSame('Dans une liste', $page->title);
    }

    public function testARecipeInAGraphIsRead(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([[
            '@context' => 'https://schema.org',
            '@graph' => [['@type' => 'Organization', 'name' => 'Marmiton'], MarmitonPages::recipe(['name' => 'Dans un graphe'])],
        ]]));

        // Then
        $this->assertSame('Dans un graphe', $page->title);
    }

    public function testARecipeInAGraphInsideAListIsRead(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([[['@graph' => [MarmitonPages::recipe(['name' => 'Graphe dans une liste'])]]]]));

        // Then
        $this->assertSame('Graphe dans une liste', $page->title);
    }

    public function testARecipeNestedInsideAnotherItemIsNotRead(): void
    {
        // Given : une recette liée, dans la liste d'un autre élément du graphe, n'est pas la recette de la page
        $related = ['@type' => 'ItemList', 'itemListElement' => [MarmitonPages::recipe(['name' => 'Une recette liée'])]];

        // When / Then
        $this->assertUnreadable(MarmitonPages::page([['@graph' => [$related, ['@type' => 'WebPage', 'mainEntity' => MarmitonPages::recipe()]]]]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function recipeTypes(): iterable
    {
        yield 'un type simple' => ['Recipe'];
        yield 'une liste de types' => [['Thing', 'Recipe']];
        yield 'un type avec son préfixe' => ['schema:Recipe'];
        yield 'un type en adresse' => ['https://schema.org/Recipe'];
    }

    #[DataProvider('recipeTypes')]
    public function testEveryWayToWriteTheRecipeTypeIsRead(mixed $type): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([MarmitonPages::recipe(['@type' => $type])]));

        // Then
        $this->assertSame(MarmitonPages::EXAMPLE_TITLE, $page->title);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notRecipeTypes(): iterable
    {
        yield 'un autre type' => ['HowTo'];
        yield 'un type qui finit par Recipe' => ['NotRecipe'];
        yield 'un type qui commence par Recipe' => ['RecipeBook'];
        yield 'un type en minuscules' => ['recipe'];
        yield 'aucun type' => [null];
        yield 'un type qui n\'est pas un texte' => [42];
    }

    #[DataProvider('notRecipeTypes')]
    public function testAnObjectWhichIsNotARecipeIsNotRead(mixed $type): void
    {
        $this->assertUnreadable(MarmitonPages::page([MarmitonPages::recipe(['@type' => $type])]));
    }

    public function testABrokenBlockIsSkippedAndTheNextOneIsRead(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page(['{"@type": "Recipe", "name": ', MarmitonPages::recipe(['name' => 'Le deuxième bloc'])]));

        // Then
        $this->assertSame('Le deuxième bloc', $page->title);
    }

    public function testABlockWithoutRecipeIsSkippedAndTheNextOneIsRead(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([['@type' => 'BreadcrumbList'], MarmitonPages::recipe(['name' => 'Après le fil d\'Ariane'])]));

        // Then
        $this->assertSame('Après le fil d\'Ariane', $page->title);
    }

    public function testAnUnreadableRecipeIsSkippedAndTheNextOneIsRead(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([
            MarmitonPages::recipe(['name' => 'Sans ingrédient', 'recipeIngredient' => []]),
            ['@graph' => [MarmitonPages::recipe(['name' => '']), MarmitonPages::recipe(['name' => 'La bonne'])]],
        ]));

        // Then
        $this->assertSame('La bonne', $page->title);
    }

    public function testTheFirstReadableRecipeWins(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([MarmitonPages::recipe(['name' => 'La première']), MarmitonPages::recipe(['name' => 'La deuxième'])]));

        // Then
        $this->assertSame('La première', $page->title);
    }

    public function testOnlyJsonLdScriptsAreRead(): void
    {
        // Given : des scripts ordinaires qui portent une recette parfaitement lisible, et des balises qui ne sont pas des scripts
        $recipe = json_encode(MarmitonPages::recipe(['name' => 'À ne pas lire']), JSON_THROW_ON_ERROR);
        $html = "<html><head>\n"
            . "<script>$recipe</script>\n"
            . "<script type=\"text/javascript\">$recipe</script>\n"
            . "<script type=\"application/json\">$recipe</script>\n"
            . "<script data-type=\"application/ld+json\">$recipe</script>\n"
            . "<scripts type=\"application/ld+json\">$recipe</scripts>\n"
            . "<script-x type=\"application/ld+json\">$recipe</script-x>\n"
            . "<template><p type=\"application/ld+json\">$recipe</p></template>\n"
            . "<!-- $recipe -->\n"
            . "<p>$recipe</p>\n"
            . "</head></html>";

        // When / Then
        $this->assertUnreadable($html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openingTags(): iterable
    {
        yield 'la forme habituelle' => ['<script type="application/ld+json">'];
        yield 'en majuscules' => ['<SCRIPT TYPE="APPLICATION/LD+JSON">'];
        yield 'avec des apostrophes' => ["<script type='application/ld+json'>"];
        yield 'sans guillemets' => ['<script type=application/ld+json>'];
        yield 'avec des espaces autour du signe égal' => ['<script type = "application/ld+json" >'];
        yield 'avec d\'autres attributs avant' => ['<script async id="recipe" type="application/ld+json">'];
        yield 'avec d\'autres attributs après' => ['<script type="application/ld+json" data-n-head="ssr">'];
        yield 'avec un retour à la ligne' => ["<script\ntype=\"application/ld+json\"\n>"];
        yield 'avec une espace dans la valeur' => ['<script type=" application/ld+json ">'];
    }

    #[DataProvider('openingTags')]
    public function testEveryWayToOpenTheJsonLdScriptIsRead(string $opening): void
    {
        // Given
        $json = json_encode(MarmitonPages::recipe(['name' => 'Ouvert comme il faut']), JSON_THROW_ON_ERROR);

        // When
        $page = $this->extract("<html><head>{$opening}{$json}</script></head></html>");

        // Then
        $this->assertSame('Ouvert comme il faut', $page->title);
    }

    public function testTheClosingTagIsFoundWhateverItsCase(): void
    {
        // Given
        $json = json_encode(MarmitonPages::recipe(['name' => 'Fermé en majuscules']), JSON_THROW_ON_ERROR);

        // When
        $page = $this->extract('<script type="application/ld+json">' . $json . '</SCRIPT ><p>après</p>');

        // Then
        $this->assertSame('Fermé en majuscules', $page->title);
    }

    public function testAnUnclosedScriptGivesNothing(): void
    {
        $this->assertUnreadable('<script type="application/ld+json">' . json_encode(MarmitonPages::recipe(), JSON_THROW_ON_ERROR));
    }

    public function testTheNumberOfExaminedScriptsIsBounded(): void
    {
        // Given : 400 scripts ordinaires avant le bloc de la recette
        $html = str_repeat("<script>var a = 1;</script>\n", 400) . MarmitonPages::page([MarmitonPages::recipe()]);

        // When / Then
        $this->assertUnreadable($html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePages(): iterable
    {
        yield 'une page vide' => [''];
        yield 'une page sans script' => ['<html><body><h1>Tarte</h1><ul><li>250 g de farine</li></ul></body></html>'];
        yield 'un bloc vide' => [MarmitonPages::page([''])];
        yield 'un JSON cassé' => [MarmitonPages::page(['{"@type": "Recipe", "name": "Tarte", '])];
        yield 'un JSON qui est un texte' => [MarmitonPages::page(['"Recipe"'])];
        yield 'un JSON qui est un nombre' => [MarmitonPages::page(['42'])];
        yield 'un JSON qui est null' => [MarmitonPages::page(['null'])];
        yield 'des octets qui ne sont pas de l\'UTF-8' => [MarmitonPages::page(["{\"@type\": \"Recipe\", \"name\": \"Tarte \xff\", \"recipeIngredient\": [\"1 oeuf\"]}"])];
        yield 'un bloc sans recette' => [MarmitonPages::page([['@type' => 'WebSite', 'name' => 'Marmiton']])];
        yield 'une recette sans nom' => [MarmitonPages::page([MarmitonPages::recipe(['name' => null])])];
        yield 'une recette au nom vide' => [MarmitonPages::page([MarmitonPages::recipe(['name' => ''])])];
        yield 'une recette au nom d\'espaces' => [MarmitonPages::page([MarmitonPages::recipe(['name' => " \t\n "])])];
        yield 'une recette au nom qui n\'est pas un texte' => [MarmitonPages::page([MarmitonPages::recipe(['name' => ['Tarte']])])];
        yield 'une recette au nom qui est un nombre' => [MarmitonPages::page([MarmitonPages::recipe(['name' => 12])])];
        yield 'une recette sans ingrédient' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => null])])];
        yield 'une recette à la liste d\'ingrédients vide' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => []])])];
        yield 'des ingrédients vides' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => ['', '  ', "\n"]])])];
        yield 'des ingrédients qui ne sont pas des textes' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => [1, 2, 3]])])];
        yield 'des ingrédients qui sont des objets' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => [['name' => 'farine', 'amount' => 250]]])])];
        yield 'des ingrédients dont un seul n\'est pas un texte' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => ['250 g de farine', 3, '2 oeufs']])])];
        yield 'des ingrédients dont un est null' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => ['250 g de farine', null]])])];
        yield 'des ingrédients en un seul texte' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => '250 g de farine, 2 oeufs'])])];
        yield 'des ingrédients en objet' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => ['a' => '250 g de farine']])])];
        yield 'plus de 100 lignes' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => array_map(static fn (int $n): string => "$n g de farine", range(1, 101))])])];
        yield 'plus de 1 000 entrées' => [MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => array_fill(0, 1001, '1 oeuf')])])];
        yield 'un JSON trop profond' => [MarmitonPages::page([str_repeat('[', 40) . str_repeat(']', 40)])];
        yield 'une recette à plus de 16 niveaux de profondeur' => [MarmitonPages::page([self::deeplyNested()])];
    }

    /**
     * Une recette lisible, mais enfouie sous 16 niveaux de listes : au-delà de la profondeur permise du JSON.
     *
     * @return array<mixed>
     */
    private static function deeplyNested(): array
    {
        $node = MarmitonPages::recipe();
        for ($i = 0; $i < 16; ++$i) {
            $node = [$node];
        }

        return $node;
    }

    #[DataProvider('unreadablePages')]
    public function testAPageWithoutAReadableRecipeIsUnreadable(string $html): void
    {
        $this->assertUnreadable($html);
    }

    public function testExactlyOneHundredLinesAreRead(): void
    {
        // Given
        $lines = array_map(static fn (int $n): string => "$n g de farine", range(1, 100));

        // When
        $page = $this->extract(MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => $lines])]));

        // Then
        $this->assertSame($lines, $page->ingredientLines);
    }

    public function testBlankEntriesAreIgnoredButTheOtherLinesAreKept(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => ['250 g de farine', '', '  ', '2 oeufs']])]));

        // Then
        $this->assertSame(['250 g de farine', '2 oeufs'], $page->ingredientLines);
    }

    public function testTextsAreCleanedOfControlCharactersAndRepeatedSpaces(): void
    {
        // Given : espace insécable, tabulation, retour à la ligne, caractère de contrôle, espace de largeur nulle
        $recipe = MarmitonPages::recipe([
            'name' => "  Tarte\u{00A0}au \t citron\n\x07 meringuée  ",
            'recipeIngredient' => ["250\u{00A0}g   de\tfarine", "\n2 jaunes\r\nd'oeuf\x00", "1 pincée de\u{200B}sel"],
            'recipeYield' => "6\u{00A0} personnes ",
            'recipeCategory' => "\nDessert\t",
        ]);

        // When
        $page = $this->extract(MarmitonPages::page([$recipe]));

        // Then
        $this->assertSame('Tarte au citron meringuée', $page->title);
        $this->assertSame(['250 g de farine', "2 jaunes d'oeuf", '1 pincée de sel'], $page->ingredientLines);
        $this->assertSame('6 personnes', $page->servingsText);
        $this->assertSame('Dessert', $page->category);
    }

    public function testNothingElseIsChangedInTheTexts(): void
    {
        // Given : le texte brut de Marmiton est gardé tel quel (le nettoyage est le travail de MAR-002)
        $line = '1 cuillères à soupe de maïzena (facultatif) &amp; <b>vanille</b>';

        // When
        $page = $this->extract(MarmitonPages::page([MarmitonPages::recipe(['recipeIngredient' => [$line]])]));

        // Then
        $this->assertSame([$line], $page->ingredientLines);
    }

    public function testLongTextsAreCut(): void
    {
        // Given
        $recipe = MarmitonPages::recipe([
            'name' => str_repeat('é', 400),
            'recipeIngredient' => [str_repeat('à', 301), '2 oeufs'],
            'recipeYield' => str_repeat('p', 150),
            'recipeCategory' => str_repeat('c', 150),
        ]);

        // When
        $page = $this->extract(MarmitonPages::page([$recipe]));

        // Then
        $this->assertSame(str_repeat('é', MarmitonRecipePage::MAX_TITLE_LENGTH), $page->title);
        $this->assertSame(str_repeat('à', MarmitonRecipePage::MAX_LINE_LENGTH), $page->ingredientLines[0]);
        $this->assertSame(str_repeat('p', MarmitonRecipePage::MAX_LABEL_LENGTH), $page->servingsText);
        $this->assertSame(str_repeat('c', MarmitonRecipePage::MAX_LABEL_LENGTH), $page->category);
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function labels(): iterable
    {
        yield 'un texte' => ['6 personnes', '6 personnes'];
        yield 'un entier' => [6, '6'];
        yield 'un décimal' => [4.5, '4.5'];
        yield 'une liste de textes' => [['6', '6 personnes'], '6'];
        yield 'une liste dont le premier est vide' => [['', '6 personnes'], '6 personnes'];
        yield 'une liste d\'un nombre' => [[8], '8'];
        yield 'une liste vide' => [[], null];
        yield 'un texte vide' => ['', null];
        yield 'un texte d\'espaces' => ['   ', null];
        yield 'un objet' => [['@type' => 'QuantitativeValue', 'value' => 6], null];
        yield 'une liste d\'objets' => [[['value' => 6]], null];
        yield 'un booléen' => [true, null];
        yield 'null' => [null, null];
    }

    #[DataProvider('labels')]
    public function testTheServingsAndTheCategoryAreOptionalTexts(mixed $value, ?string $expected): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([MarmitonPages::recipe(['recipeYield' => $value, 'recipeCategory' => $value])]));

        // Then
        $this->assertSame($expected, $page->servingsText);
        $this->assertSame($expected, $page->category);
    }

    public function testTheServingsAndTheCategoryMayBeAbsent(): void
    {
        // When
        $page = $this->extract(MarmitonPages::page([MarmitonPages::recipe(['recipeYield' => null, 'recipeCategory' => null])]));

        // Then
        $this->assertNull($page->servingsText);
        $this->assertNull($page->category);
    }

    public function testTheStepsAndThePhotosAreNeverKept(): void
    {
        // Given
        $recipe = MarmitonPages::recipe([
            'recipeInstructions' => [['@type' => 'HowToStep', 'text' => 'Mélanger la farine et le beurre.']],
            'image' => ['https://assets.afcdn.com/recipe/photo.jpg'],
            'author' => ['@type' => 'Person', 'name' => 'Quelqu\'un'],
            'aggregateRating' => ['ratingValue' => 4.8],
        ]);

        // When
        $page = $this->extract(MarmitonPages::page([$recipe]));

        // Then : le modèle ne porte que les faits retenus
        $this->assertSame(['canonicalUrl', 'title', 'servingsText', 'category', 'ingredientLines'], array_keys(get_object_vars($page)));
        $this->assertStringNotContainsString('farine et le beurre', serialize($page));
        $this->assertStringNotContainsString('afcdn', serialize($page));
    }
}
