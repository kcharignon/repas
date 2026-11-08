<?php

namespace Repas\Tests\Repas\Infrastructure\Image;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Repas\Infrastructure\Image\UploadedImageReader;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * La lecture du fichier d'une requête multipart (ADR 0039) : bornée à 3 Mio + 1 octet en mémoire, jamais un chemin choisi par le client, le nom et le type
 * annoncés ne sont jamais lus.
 */
class UploadedImageReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function upload(string $content, int $error = UPLOAD_ERR_OK, bool $test = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        $this->files[] = $path;
        file_put_contents($path, $content);

        return new UploadedFile($path, 'image-du-client.png', 'image/png', $error, $test);
    }

    public function testTheContentOfTheFileIsRead(): void
    {
        // When / Then
        $this->assertSame('les octets', new UploadedImageReader()->read($this->upload('les octets')));
    }

    public function testAnEmptyFileIsReadAsEmptyForTheContentToBeJudgedLater(): void
    {
        // When / Then : c'est ImageNormalizer qui dira « pas une image »
        $this->assertSame('', new UploadedImageReader()->read($this->upload('')));
    }

    public function testExactlyThreeMebibytesIsRead(): void
    {
        // When
        $content = new UploadedImageReader()->read($this->upload(str_repeat('a', ImageNormalizer::MAX_BYTES)));

        // Then
        $this->assertSame(ImageNormalizer::MAX_BYTES, strlen($content));
    }

    public function testOneByteOverThreeMebibytesIsTooLarge(): void
    {
        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::TOO_LARGE);

        // When
        new UploadedImageReader()->read($this->upload(str_repeat('a', ImageNormalizer::MAX_BYTES + 1)));
    }

    public function testNeverMoreThanTheLimitPlusOneByteIsReadIntoMemory(): void
    {
        // Given : un fichier de 12 Mio
        $file = $this->upload(str_repeat('a', 4 * ImageNormalizer::MAX_BYTES));
        $before = memory_get_peak_usage(true);

        // When
        try {
            new UploadedImageReader()->read($file);
            $this->fail('Le fichier aurait dû être refusé.');
        } catch (ImageException $exception) {
            // Then : refusé sur sa taille avant toute lecture, la mémoire n'a pas grimpé de la taille du fichier
            $this->assertSame(ImageException::TOO_LARGE, $exception->getMessage());
            $this->assertLessThan(ImageNormalizer::MAX_BYTES, memory_get_peak_usage(true) - $before);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notFiles(): iterable
    {
        yield 'rien' => [null];
        yield 'un texte' => ['/etc/passwd'];
        yield 'une liste' => [['/etc/passwd']];
        yield 'un nombre' => [12];
        yield 'un booléen' => [true];
    }

    #[DataProvider('notFiles')]
    public function testWhatIsNotAnUploadedFileIsMissing(mixed $file): void
    {
        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::FILE_MISSING);

        // When
        new UploadedImageReader()->read($file);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function uploadErrors(): iterable
    {
        yield 'plus que upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, ImageException::TOO_LARGE];
        yield 'plus que MAX_FILE_SIZE du formulaire' => [UPLOAD_ERR_FORM_SIZE, ImageException::TOO_LARGE];
        yield 'envoi partiel' => [UPLOAD_ERR_PARTIAL, ImageException::FILE_MISSING];
        yield 'aucun fichier' => [UPLOAD_ERR_NO_FILE, ImageException::FILE_MISSING];
        yield 'pas de dossier temporaire' => [UPLOAD_ERR_NO_TMP_DIR, ImageException::FILE_MISSING];
        yield 'écriture impossible' => [UPLOAD_ERR_CANT_WRITE, ImageException::FILE_MISSING];
        yield 'extension PHP qui arrête l\'envoi' => [UPLOAD_ERR_EXTENSION, ImageException::FILE_MISSING];
    }

    #[DataProvider('uploadErrors')]
    public function testAnUploadErrorHasItsNamedRefusal(int $error, string $message): void
    {
        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage($message);

        // When
        new UploadedImageReader()->read($this->upload('abc', $error));
    }

    public function testAFileThatPhpDidNotReceiveAsAnUploadIsMissing(): void
    {
        // Given : un fichier ordinaire du disque que le client voudrait faire passer pour un envoi (hors mode test, `is_uploaded_file` est faux)
        $file = $this->upload('/etc/passwd', UPLOAD_ERR_OK, test: false);

        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::FILE_MISSING);

        // When
        new UploadedImageReader()->read($file);
    }
}
