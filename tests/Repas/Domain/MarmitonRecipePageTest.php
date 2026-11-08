<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Tests\Helper\MarmitonPages;

/**
 * La page lue (ADR 0037, MAR-001) : le constructeur est la garde finale, une page à moitié remplie n'existe pas.
 */
class MarmitonRecipePageTest extends TestCase
{
    /**
     * @param list<string> $lines
     */
    private function page(
        string $url = MarmitonPages::EXAMPLE_URL,
        string $title = 'Tarte',
        ?string $servings = '6 personnes',
        ?string $category = 'Dessert',
        array $lines = ['250 g de farine'],
    ): MarmitonRecipePage {
        return new MarmitonRecipePage($url, $title, $servings, $category, $lines);
    }

    public function testAReadablePageKeepsItsFacts(): void
    {
        // When
        $page = $this->page(lines: ['250 g de farine', '2 oeufs']);

        // Then
        $this->assertSame(MarmitonPages::EXAMPLE_URL, $page->canonicalUrl);
        $this->assertSame('Tarte', $page->title);
        $this->assertSame('6 personnes', $page->servingsText);
        $this->assertSame('Dessert', $page->category);
        $this->assertSame(['250 g de farine', '2 oeufs'], $page->ingredientLines);
    }

    public function testTheServingsAndTheCategoryMayBeNull(): void
    {
        // When
        $page = $this->page(servings: null, category: null);

        // Then
        $this->assertNull($page->servingsText);
        $this->assertNull($page->category);
    }

    public function testTheLimitsAreAccepted(): void
    {
        // When
        $page = $this->page(
            title: str_repeat('é', MarmitonRecipePage::MAX_TITLE_LENGTH),
            servings: str_repeat('p', MarmitonRecipePage::MAX_LABEL_LENGTH),
            category: str_repeat('c', MarmitonRecipePage::MAX_LABEL_LENGTH),
            lines: array_fill(0, MarmitonRecipePage::MAX_LINES, str_repeat('à', MarmitonRecipePage::MAX_LINE_LENGTH)),
        );

        // Then
        $this->assertCount(100, $page->ingredientLines);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadablePages(): iterable
    {
        yield 'sans titre' => [['title' => '']];
        yield 'un titre trop long' => [['title' => str_repeat('a', MarmitonRecipePage::MAX_TITLE_LENGTH + 1)]];
        yield 'sans ligne' => [['lines' => []]];
        yield 'plus de 100 lignes' => [['lines' => array_fill(0, MarmitonRecipePage::MAX_LINES + 1, '1 oeuf')]];
        yield 'une ligne vide' => [['lines' => ['250 g de farine', '']]];
        yield 'une ligne trop longue' => [['lines' => [str_repeat('a', MarmitonRecipePage::MAX_LINE_LENGTH + 1)]]];
        yield 'un texte de personnes vide' => [['servings' => '']];
        yield 'un texte de personnes trop long' => [['servings' => str_repeat('a', MarmitonRecipePage::MAX_LABEL_LENGTH + 1)]];
        yield 'une catégorie vide' => [['category' => '']];
        yield 'une catégorie trop longue' => [['category' => str_repeat('a', MarmitonRecipePage::MAX_LABEL_LENGTH + 1)]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('unreadablePages')]
    public function testAPageWithoutAllItsFactsIsUnreadable(array $arguments): void
    {
        // When / Then
        try {
            $this->page(...$arguments);
            $this->fail('Cette page aurait dû être refusée.');
        } catch (MarmitonImportException $exception) {
            $this->assertSame(MarmitonImportException::RECIPE_UNREADABLE, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notCanonicalAddresses(): iterable
    {
        yield 'un autre hôte' => ['https://evil.example/recettes/recette_x_22082.aspx'];
        yield 'une adresse avec une requête' => [MarmitonPages::EXAMPLE_URL . '?utm=1'];
        yield 'en http' => ['http://www.marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'sans www' => ['https://marmiton.org/recettes/recette_x_22082.aspx'];
        yield 'en majuscules' => ['https://WWW.MARMITON.ORG/recettes/recette_x_22082.aspx'];
        yield 'le texte vide' => [''];
    }

    #[DataProvider('notCanonicalAddresses')]
    public function testTheAddressMustBeExactlyTheCanonicalOne(string $url): void
    {
        // When / Then
        try {
            $this->page(url: $url);
            $this->fail('Cette adresse aurait dû être refusée.');
        } catch (MarmitonImportException $exception) {
            $this->assertSame(MarmitonImportException::URL_INVALID, $exception->getMessage());
        }
    }
}
