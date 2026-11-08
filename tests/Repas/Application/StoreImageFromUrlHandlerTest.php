<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\StoreImageFromUrl\StoreImageFromUrlCommand;
use Repas\Repas\Application\Command\StoreImageFromUrl\StoreImageFromUrlHandler;
use Repas\Repas\Application\Command\StoreUploadedImage\StoreUploadedImageCommand;
use Repas\Repas\Application\Command\StoreUploadedImage\StoreUploadedImageHandler;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\Tests\Helper\Image\ImageDownloadLimiterInMemory;
use Repas\Tests\Helper\Image\ImageInMemoryFetcher;
use Repas\Tests\Helper\Image\ImageInMemoryStorage;
use Repas\Tests\Helper\Image\Images;

/**
 * Télécharger l'image d'un lien direct (ADR 0039) : le lien est jugé d'abord (aucun appel sortant s'il est refusé), le plafond est consommé, l'image
 * téléchargée passe par EXACTEMENT le même traitement qu'un fichier envoyé, puis elle est gardée sous son empreinte. Un refus n'écrit rien.
 */
class StoreImageFromUrlHandlerTest extends TestCase
{
    private const string URL = 'https://exemple.org/images/surgeles.png';
    private const string ADMIN = 'admin-1';

    private ImageInMemoryFetcher $fetcher;
    private ImageInMemoryStorage $storage;
    private ImageDownloadLimiterInMemory $limiter;
    private StoreImageFromUrlHandler $handler;

    protected function setUp(): void
    {
        $this->fetcher = new ImageInMemoryFetcher();
        $this->storage = new ImageInMemoryStorage();
        $this->limiter = new ImageDownloadLimiterInMemory();
        $this->handler = new StoreImageFromUrlHandler($this->limiter, $this->fetcher, new ImageNormalizer(), $this->storage);
    }

    private function download(string $url = self::URL): string
    {
        return ($this->handler)(new StoreImageFromUrlCommand($url, self::ADMIN));
    }

    public function testADownloadedImageIsStoredLikeAnUploadedOne(): void
    {
        // Given
        $png = Images::png(1000, 500, Images::GREEN);
        $this->fetcher->willFetch(self::URL, $png);

        // When
        $path = $this->download();

        // Then : le même chemin qu'un fichier envoyé avec les mêmes octets
        $uploaded = new StoreUploadedImageHandler(new ImageNormalizer(), new ImageInMemoryStorage());
        $this->assertSame($uploaded(new StoreUploadedImageCommand($png)), $path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertSame([512, 256], [getimagesizefromstring($this->storage->files[$path])[0], getimagesizefromstring($this->storage->files[$path])[1]]);
        $this->assertSame([self::ADMIN], $this->limiter->consumed);
        $this->assertSame(['https://exemple.org/images/surgeles.png'], $this->fetcher->calls());
    }

    public function testADownloadedSvgIsSanitizedLikeAnUploadedOne(): void
    {
        // Given : le serveur ne dit rien de sûr sur le contenu (c'est le fichier qui décide, jamais l'extension du lien)
        $svg = str_replace('<title>', '<script>alert(1)</script><title>', Images::svg());
        $this->fetcher->willFetch('https://exemple.org/dessin.png', $svg);

        // When
        $path = $this->download('https://exemple.org/dessin.png');

        // Then : un .svg assaini, malgré l'extension .png du lien
        $this->assertStringEndsWith('.svg', $path);
        $this->assertStringNotContainsString('script', $this->storage->files[$path]);
        $uploaded = new StoreUploadedImageHandler(new ImageNormalizer(), new ImageInMemoryStorage());
        $this->assertSame($uploaded(new StoreUploadedImageCommand($svg)), $path);
    }

    public function testTheLinkIsRebuiltFromItsPartsAndTheFragmentIsDropped(): void
    {
        // Given
        $this->fetcher->willFetch('https://exemple.org/a.png?v=2', Images::png());

        // When
        $this->download("  HTTPS://Exemple.ORG/a.png?v=2#haut \n");

        // Then
        $this->assertSame(['https://exemple.org/a.png?v=2'], $this->fetcher->calls());
    }

    public function testTheSameImageFromTwoLinksGivesTheSamePath(): void
    {
        // Given
        $png = Images::png(50, 50, Images::BLUE);
        $this->fetcher->willFetch('https://exemple.org/a.png', $png)->willFetch('https://autre.exemple.net/b.png', $png);

        // When
        $first = $this->download('https://exemple.org/a.png');
        $second = $this->download('https://autre.exemple.net/b.png');

        // Then
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->storage->writes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'http' => ['http://exemple.org/a.png'];
        yield 'une adresse IP' => ['https://127.0.0.1/a.png'];
        yield 'les métadonnées du nuage' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'localhost' => ['https://localhost/a.png'];
        yield 'un nom sans point' => ['https://database/a.png'];
        yield 'un autre port' => ['https://exemple.org:8443/a.png'];
        yield 'un nom d\'utilisateur' => ['https://admin@exemple.org/a.png'];
        yield 'rien' => [''];
        yield 'pas un lien' => ['une image'];
    }

    #[DataProvider('refusedLinks')]
    public function testARefusedLinkMakesNoCallAndConsumesNothing(string $url): void
    {
        // When
        try {
            $this->download($url);
            $this->fail('Le lien aurait dû être refusé.');
        } catch (ImageException $exception) {
            // Then
            $this->assertSame([ImageException::URL_REFUSED, 422], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertSame([0, [], []], [$this->fetcher->callCount(), $this->limiter->consumed, $this->storage->files]);
    }

    public function testOnceTheLimitIsReachedTheNextDownloadIsRefusedBeforeAnyCall(): void
    {
        // Given : le plafond de 2 téléchargements
        $handler = new StoreImageFromUrlHandler(new ImageDownloadLimiterInMemory(2, 321), $this->fetcher, new ImageNormalizer(), $this->storage);
        $this->fetcher->willFetch(self::URL, Images::png());
        $handler(new StoreImageFromUrlCommand(self::URL, self::ADMIN));
        $handler(new StoreImageFromUrlCommand(self::URL, self::ADMIN));

        // When
        try {
            $handler(new StoreImageFromUrlCommand(self::URL, self::ADMIN));
            $this->fail('Le plafond aurait dû être atteint.');
        } catch (RateLimitedException $exception) {
            // Then : 429, le délai d'attente, un appel de moins
            $this->assertSame([ImageException::RATE_LIMITED, 429, 321], [$exception->getMessage(), $exception->getCode(), $exception->retryAfter()]);
        }
        $this->assertSame(2, $this->fetcher->callCount());

        // Un autre administrateur a son propre compteur
        $handler(new StoreImageFromUrlCommand(self::URL, 'admin-2'));
        $this->assertSame(3, $this->fetcher->callCount());
    }

    /**
     * @return iterable<string, array{ImageException}>
     */
    public static function failedDownloads(): iterable
    {
        yield 'le site ne répond pas' => [ImageException::downloadFailed()];
        yield 'l\'adresse est locale' => [ImageException::urlRefused()];
        yield 'la réponse n\'est pas une image' => [ImageException::notAnImage()];
        yield 'la réponse est trop grosse' => [ImageException::tooLarge()];
    }

    #[DataProvider('failedDownloads')]
    public function testAFailedDownloadIsReportedAsIsAndWritesNothing(ImageException $failure): void
    {
        // Given
        $this->fetcher->willFail(self::URL, $failure);

        // When
        try {
            $this->download();
            $this->fail('Le téléchargement aurait dû échouer.');
        } catch (ImageException $exception) {
            // Then
            $this->assertSame($failure, $exception);
        }
        $this->assertSame([], $this->storage->files);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function downloadedButUnusable(): iterable
    {
        yield 'un JPEG' => [Images::jpeg(), ImageException::UNSUPPORTED];
        yield 'un SVG dont il ne reste rien' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><script>alert(1)</script></svg>', ImageException::UNSUPPORTED];
        yield 'une page web' => [Images::htmlPage(), ImageException::NOT_AN_IMAGE];
        yield 'une bombe de décompression' => [Images::pngClaiming(60000, 60000), ImageException::TOO_LARGE];
        yield 'un GIF' => [Images::gif(), ImageException::UNSUPPORTED];
    }

    #[DataProvider('downloadedButUnusable')]
    public function testWhatIsDownloadedGoesThroughTheSameChecksAsAFile(string $bytes, string $message): void
    {
        // Given
        $this->fetcher->willFetch(self::URL, $bytes);

        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage($message);

        // When
        try {
            $this->download();
        } finally {
            $this->assertSame([], $this->storage->files);
        }
    }
}
