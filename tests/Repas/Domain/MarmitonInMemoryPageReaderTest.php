<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\MarmitonUrl;
use Repas\Tests\Helper\MarmitonInMemoryPageReader;
use Repas\Tests\Helper\MarmitonPages;

/**
 * Le double que les tests de handlers utilisent à la place de Marmiton (MAR-003) : des réponses par identifiant, et la trace des appels.
 */
class MarmitonInMemoryPageReaderTest extends TestCase
{
    public function testAProgrammedPageIsReadAndTheCallIsRecorded(): void
    {
        // Given
        $page = new MarmitonRecipePage(MarmitonPages::EXAMPLE_URL, 'Tarte', null, null, ['250 g de farine']);
        $reader = (new MarmitonInMemoryPageReader())->willRead('22082', $page);

        // When
        $read = $reader->read(MarmitonUrl::parse(MarmitonPages::EXAMPLE_URL . '?utm=1'));

        // Then
        $this->assertSame($page, $read);
        $this->assertSame(1, $reader->callCount());
        $this->assertSame('22082', $reader->calls()[0]->id);
    }

    public function testAProgrammedFailureIsThrown(): void
    {
        // Given
        $reader = (new MarmitonInMemoryPageReader())->willFail('22082', MarmitonImportException::unavailable());

        // When / Then
        $this->expectExceptionMessage(MarmitonImportException::UNAVAILABLE);
        $reader->read(MarmitonUrl::parse(MarmitonPages::EXAMPLE_URL));
    }

    public function testAnUnknownIdentifierIsNotFoundAndStillRecorded(): void
    {
        // Given
        $reader = new MarmitonInMemoryPageReader();

        // When / Then
        try {
            $reader->read(MarmitonUrl::parse(MarmitonPages::EXAMPLE_URL));
            $this->fail('Un identifiant inconnu aurait dû être introuvable.');
        } catch (MarmitonImportException $exception) {
            $this->assertSame(MarmitonImportException::RECIPE_NOT_FOUND, $exception->getMessage());
        }
        $this->assertSame(1, $reader->callCount());
    }

    public function testAReaderNeverCalledRecordsNothing(): void
    {
        $this->assertSame(0, (new MarmitonInMemoryPageReader())->callCount());
        $this->assertSame([], (new MarmitonInMemoryPageReader())->calls());
    }
}
