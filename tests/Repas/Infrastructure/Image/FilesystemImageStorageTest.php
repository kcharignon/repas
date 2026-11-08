<?php

namespace Repas\Tests\Repas\Infrastructure\Image;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageStorage;
use Repas\Repas\Domain\Model\ProcessedImage;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Repas\Infrastructure\Image\FilesystemImageStorage;
use Repas\Tests\Helper\Image\Images;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le stockage des images envoyées sur le disque (ADR 0039), dans un dossier jetable : nom = empreinte, écriture atomique et idempotente, droits lisibles
 * par le serveur de fichiers, échec nommé quand le disque refuse.
 */
class FilesystemImageStorageTest extends TestCase
{
    private string $publicDirectory;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->publicDirectory = sys_get_temp_dir() . '/repas-images-' . bin2hex(random_bytes(6));
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->publicDirectory);
        $this->logger->cleanLogs();
    }

    private function storage(?string $publicDirectory = null): FilesystemImageStorage
    {
        return new FilesystemImageStorage($publicDirectory ?? $this->publicDirectory, $this->logger);
    }

    private function image(?string $bytes = null): ProcessedImage
    {
        return new ImageNormalizer()->normalize($bytes ?? Images::png(64, 32));
    }

    public function testTheImageIsWrittenUnderItsDigestAndItsPathIsReturned(): void
    {
        // Given
        $image = $this->image();

        // When
        $path = $this->storage()->store($image);

        // Then
        $this->assertSame('images/uploads/' . $image->hash . '.png', $path);
        $this->assertSame(ImageStorage::DIRECTORY . '/' . $image->fileName(), $path);
        $this->assertFileExists($this->publicDirectory . '/' . $path);
        $this->assertSame($image->bytes, file_get_contents($this->publicDirectory . '/' . $path));
    }

    public function testEveryFormatKeepsItsExtension(): void
    {
        // When
        $paths = array_map(fn (string $bytes): string => $this->storage()->store($this->image($bytes)), [Images::png(), Images::webp(), Images::svg()]);

        // Then
        $this->assertSame(['png', 'webp', 'svg'], array_map(static fn (string $path): string => pathinfo($path, PATHINFO_EXTENSION), $paths));
    }

    public function testTheDirectoryIsCreatedWhenItIsMissing(): void
    {
        // Given
        $this->assertDirectoryDoesNotExist($this->publicDirectory);

        // When
        $this->storage()->store($this->image());

        // Then : lisible par le serveur de fichiers
        $this->assertDirectoryExists($this->publicDirectory . '/images/uploads');
        $this->assertSame(0755, fileperms($this->publicDirectory . '/images/uploads') & 0777 & ~umask());
    }

    public function testTheFileIsReadableByEveryone(): void
    {
        // When
        $path = $this->storage()->store($this->image());

        // Then
        $this->assertSame(0644, fileperms($this->publicDirectory . '/' . $path) & 0777);
    }

    public function testTheSameImageIsNotWrittenTwice(): void
    {
        // Given
        $image = $this->image();
        $first = $this->storage()->store($image);
        $file = $this->publicDirectory . '/' . $first;
        touch($file, 1_000_000_000);
        clearstatcache();

        // When
        $second = $this->storage()->store($image);

        // Then : le même chemin, le fichier n'a pas été touché
        $this->assertSame($first, $second);
        clearstatcache();
        $this->assertSame(1_000_000_000, filemtime($file));
    }

    public function testAnIncompleteFileOfTheSameNameIsReplaced(): void
    {
        // Given : un fichier du même nom mais trop court (une écriture d'une ancienne version qui aurait été interrompue)
        $image = $this->image();
        mkdir($this->publicDirectory . '/images/uploads', 0755, true);
        file_put_contents($this->publicDirectory . '/images/uploads/' . $image->fileName(), 'abc');

        // When
        $this->storage()->store($image);

        // Then
        $this->assertSame($image->bytes, file_get_contents($this->publicDirectory . '/images/uploads/' . $image->fileName()));
    }

    public function testNoTemporaryFileIsLeftBehind(): void
    {
        // When
        $this->storage()->store($this->image());
        $this->storage()->store($this->image(Images::png(10, 10)));

        // Then : deux fichiers, aucun `.partial`
        $files = array_values(array_diff(scandir($this->publicDirectory . '/images/uploads') ?: [], ['.', '..']));
        $this->assertCount(2, $files);
        $this->assertSame([], array_values(array_filter($files, static fn (string $file): bool => str_contains($file, 'partial'))));
    }

    public function testTheOtherFilesOfTheDirectoryAreNotTouched(): void
    {
        // Given
        mkdir($this->publicDirectory . '/images/uploads', 0755, true);
        file_put_contents($this->publicDirectory . '/images/uploads/ancienne.webp', 'ancienne');

        // When
        $this->storage()->store($this->image());

        // Then
        $this->assertSame('ancienne', file_get_contents($this->publicDirectory . '/images/uploads/ancienne.webp'));
    }

    public function testTheStorageTrailingSlashOfTheRootIsIgnored(): void
    {
        // When
        $path = $this->storage($this->publicDirectory . '/')->store($this->image());

        // Then
        $this->assertFileExists($this->publicDirectory . '/' . $path);
    }

    public function testADirectoryThatCannotBeCreatedIsAStorageFailureAndTheLogHasTheReasonOnly(): void
    {
        // Given : la racine est un fichier ordinaire, aucun dossier n'y est possible
        file_put_contents($this->publicDirectory = sys_get_temp_dir() . '/repas-images-fichier-' . bin2hex(random_bytes(4)), 'je suis un fichier');

        // When
        try {
            $this->storage()->store($this->image());
            $this->fail('Le stockage aurait dû échouer.');
        } catch (ImageException $exception) {
            // Then
            $this->assertSame([500, 'IMAGE_STORAGE_FAILED'], [$exception->getCode(), $exception->getMessage()]);
        } finally {
            unlink($this->publicDirectory);
        }
        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('error', $logs[0][0]);
        $this->assertSame(['reason' => 'le dossier des images ne peut pas être créé'], $logs[0][2]);
    }

    public function testAFileThatCannotBeMovedIntoPlaceIsAStorageFailureAndLeavesNothingBehind(): void
    {
        // Given : à la place du fichier, un dossier non vide (le renommage échoue)
        $image = $this->image();
        $target = $this->publicDirectory . '/images/uploads/' . $image->fileName();
        mkdir($target . '/dedans', 0755, true);

        // When
        try {
            $this->storage()->store($image);
            $this->fail('Le stockage aurait dû échouer.');
        } catch (ImageException $exception) {
            // Then
            $this->assertSame('IMAGE_STORAGE_FAILED', $exception->getMessage());
        }
        $leftovers = array_filter(scandir($this->publicDirectory . '/images/uploads') ?: [], static fn (string $file): bool => str_contains($file, 'partial'));
        $this->assertSame([], array_values($leftovers));
    }
}
