<?php

namespace Repas\Tests\Shared;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Infrastructure\Http\EntityVersion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

class EntityVersionTest extends TestCase
{
    public function testEtagIsAQuotedVersion(): void
    {
        // Act & Assert
        $this->assertSame('"3"', EntityVersion::etag(3));
    }

    public function testExpectedReadsTheQuotedVersionOfIfMatch(): void
    {
        // Given
        $request = new Request(server: ['HTTP_IF_MATCH' => '"12"']);

        // When & Then
        $this->assertSame(12, EntityVersion::expected($request));
    }

    public function testExpectedIsNullWithoutIfMatch(): void
    {
        // Act & Assert
        $this->assertNull(EntityVersion::expected(new Request()));
    }

    public function testExpectedIsNullForAnyVersion(): void
    {
        // Given
        $request = new Request(server: ['HTTP_IF_MATCH' => '*']);

        // When & Then
        $this->assertNull(EntityVersion::expected($request));
    }

    #[DataProvider('invalidValues')]
    public function testExpectedRefusesAValueWhichIsNotAnEtagOfTheApi(string $value): void
    {
        // Given
        $request = new Request(server: ['HTTP_IF_MATCH' => $value]);

        // Assert
        $this->expectException(PreconditionFailedHttpException::class);

        // Act
        EntityVersion::expected($request);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'sans guillemets' => ['3'];
        yield 'ETag faible' => ['W/"3"'];
        yield 'texte' => ['"abc"'];
        yield 'liste' => ['"3", "4"'];
        yield 'négatif' => ['"-1"'];
        yield 'trop grand' => ['"12345678901234567890"'];
    }
}
