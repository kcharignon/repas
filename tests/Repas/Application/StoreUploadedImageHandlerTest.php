<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\StoreUploadedImage\StoreUploadedImageCommand;
use Repas\Repas\Application\Command\StoreUploadedImage\StoreUploadedImageHandler;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Tests\Helper\Image\ImageInMemoryStorage;
use Repas\Tests\Helper\Image\Images;

/**
 * Garder l'image d'un fichier envoyé (ADR 0039) : le contenu est reconnu, décodé et ré-encodé dans son format, ou assaini pour un SVG (le vrai
 * `ImageNormalizer`, de domaine pur, avec GD), puis écrit sous son empreinte dans un stockage en mémoire. Un refus n'écrit rien.
 */
class StoreUploadedImageHandlerTest extends TestCase
{
    private ImageInMemoryStorage $storage;
    private StoreUploadedImageHandler $handler;

    protected function setUp(): void
    {
        $this->storage = new ImageInMemoryStorage();
        $this->handler = new StoreUploadedImageHandler(new ImageNormalizer(), $this->storage);
    }

    public function testAValidPngIsStoredAsAPngNamedByItsDigestAndItsPathIsReturned(): void
    {
        // When
        $path = ($this->handler)(new StoreUploadedImageCommand(Images::png(100, 80)));

        // Then
        $this->assertMatchesRegularExpression('#^images/uploads/[0-9a-f]{64}\.png$#', $path);
        $this->assertSame([$path], array_keys($this->storage->files));
        $stored = $this->storage->files[$path];
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($stored)[2]);
        $this->assertSame($path, 'images/uploads/' . hash('sha256', $stored) . '.png');
        $this->assertSame([100, 80], [getimagesizefromstring($stored)[0], getimagesizefromstring($stored)[1]]);
    }

    public function testAWebpStaysAWebpAndKeepsItsTransparency(): void
    {
        // When
        $path = ($this->handler)(new StoreUploadedImageCommand(Images::transparentWebp(40)));

        // Then
        $this->assertMatchesRegularExpression('#^images/uploads/[0-9a-f]{64}\.webp$#', $path);
        $stored = $this->storage->files[$path];
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($stored)[2]);
        $this->assertGreaterThan(120, Images::alphaAt($stored, 1, 1));
        $this->assertSame(0, Images::alphaAt($stored, 14, 14));
    }

    public function testASvgIsStoredSanitizedUnderItsDigest(): void
    {
        // Given
        $svg = str_replace('<title>', '<script>alert("a-ne-pas-garder")</script><title>', Images::svg());

        // When
        $path = ($this->handler)(new StoreUploadedImageCommand($svg));

        // Then : un fichier .svg, sans le script, nommé par l'empreinte de ce qui est écrit
        $this->assertMatchesRegularExpression('#^images/uploads/[0-9a-f]{64}\.svg$#', $path);
        $stored = $this->storage->files[$path];
        $this->assertStringContainsString('<circle', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('a-ne-pas-garder', $stored);
        $this->assertSame($path, 'images/uploads/' . hash('sha256', $stored) . '.svg');
    }

    public function testALargeImageIsBroughtBackTo512PixelsInItsOwnFormat(): void
    {
        // When
        $png = ($this->handler)(new StoreUploadedImageCommand(Images::png(1600, 900)));
        $webp = ($this->handler)(new StoreUploadedImageCommand(Images::webp(1600, 900)));

        // Then
        foreach ([$png, $webp] as $path) {
            $stored = $this->storage->files[$path];
            $this->assertSame([512, 288], [getimagesizefromstring($stored)[0], getimagesizefromstring($stored)[1]]);
        }
        $this->assertStringEndsWith('.png', $png);
        $this->assertStringEndsWith('.webp', $webp);
    }

    public function testTheSameImageTwiceGivesTheSamePathAndWritesOnce(): void
    {
        // Given
        foreach ([Images::png(64, 64, Images::BLUE), Images::webp(64, 64, Images::BLUE), Images::svg()] as $bytes) {
            // When
            $first = ($this->handler)(new StoreUploadedImageCommand($bytes));
            $second = ($this->handler)(new StoreUploadedImageCommand($bytes));

            // Then
            $this->assertSame($first, $second);
        }
        $this->assertSame(3, $this->storage->writes);
        $this->assertCount(3, $this->storage->files);
    }

    public function testASvgWrittenWithOtherWhitespaceAndExtrasGivesTheSamePath(): void
    {
        // When
        $first = ($this->handler)(new StoreUploadedImageCommand(Images::svg()));
        $second = ($this->handler)(new StoreUploadedImageCommand(Images::svgWithPrologue()));

        // Then : le prologue et le commentaire disparaissent, c'est le même fichier
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->storage->writes);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function refusals(): iterable
    {
        yield 'plus de 3 Mio' => ["\x89PNG\r\n\x1a\n" . str_repeat('a', ImageNormalizer::MAX_BYTES), ImageException::TOO_LARGE, 413];
        yield 'une bombe de décompression' => [Images::pngClaiming(60000, 60000), ImageException::TOO_LARGE, 413];
        yield 'un texte' => ['ceci n\'est pas une image', ImageException::NOT_AN_IMAGE, 422];
        yield 'une page web' => [Images::htmlPage(), ImageException::NOT_AN_IMAGE, 422];
        yield 'rien du tout' => ['', ImageException::NOT_AN_IMAGE, 422];
        yield 'un JPEG' => [Images::jpeg(), ImageException::UNSUPPORTED, 422];
        yield 'un GIF' => [Images::gif(), ImageException::UNSUPPORTED, 422];
        yield 'un SVG avec une entité externe' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><title>&x;</title></svg>', ImageException::UNSUPPORTED, 422];
        yield 'un SVG dont il ne reste rien' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><script>alert(1)</script></svg>', ImageException::UNSUPPORTED, 422];
    }

    #[DataProvider('refusals')]
    public function testARefusedFileWritesNothing(string $bytes, string $message, int $status): void
    {
        // When
        try {
            ($this->handler)(new StoreUploadedImageCommand($bytes));
            $this->fail('Le fichier aurait dû être refusé.');
        } catch (ImageException $exception) {
            // Then
            $this->assertSame([$message, $status], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame([], $this->storage->files);
        $this->assertSame(0, $this->storage->writes);
    }

    public function testAStorageThatFailsIsA500AndTheImageIsNotKept(): void
    {
        // Given
        $handler = new StoreUploadedImageHandler(new ImageNormalizer(), $this->storage->failing());

        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::STORAGE_FAILED);

        // When
        $handler(new StoreUploadedImageCommand(Images::png()));
    }
}
