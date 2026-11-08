<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Repas\Tests\Helper\Image\Images;

/**
 * Le traitement d'une image envoyée par l'administrateur (ADR 0039), avec le vrai GD : le type est reconnu par le CONTENU (PNG, WebP ou SVG ; le JPEG est
 * refusé), un raster est décodé puis ré-encodé DANS SON FORMAT (aucune métadonnée, transparence gardée), ramené à 512 px au plus, et nommé par l'empreinte
 * de son résultat ; un SVG est assaini (`SvgSanitizerTest`). Les refus (trop gros, pas une image, format refusé, bombe de décompression) n'écrivent rien :
 * le service ne fait que rendre une image ou lever `ImageException`.
 */
class ImageNormalizerTest extends TestCase
{
    private ImageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ImageNormalizer();
    }

    // ---------------------------------------------------------------- les formats acceptés

    public function testAPngStaysAPng(): void
    {
        // When
        $image = $this->normalizer->normalize(Images::png());

        // Then : un PNG lisible, aux dimensions de l'original, nommé par l'empreinte de ses octets
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($image->bytes, 0, 8));
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($image->bytes)[2]);
        $this->assertSame([40, 20], [getimagesizefromstring($image->bytes)[0], getimagesizefromstring($image->bytes)[1]]);
        $this->assertSame([40, 20], [$image->width, $image->height]);
        $this->assertSame('png', $image->extension);
        $this->assertSame('image/png', $image->mimeType());
        $this->assertSame(hash('sha256', $image->bytes), $image->hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\.png$/', $image->fileName());
    }

    public function testAWebpStaysAWebp(): void
    {
        // When
        $image = $this->normalizer->normalize(Images::webp());

        // Then
        $this->assertSame('RIFF', substr($image->bytes, 0, 4));
        $this->assertSame('WEBP', substr($image->bytes, 8, 4));
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($image->bytes)[2]);
        $this->assertSame([40, 20], [$image->width, $image->height]);
        $this->assertSame('webp', $image->extension);
        $this->assertSame('image/webp', $image->mimeType());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\.webp$/', $image->fileName());
    }

    public function testASvgStaysASvg(): void
    {
        // When
        $image = $this->normalizer->normalize(Images::svg());

        // Then
        $this->assertSame('svg', $image->extension);
        $this->assertSame('image/svg+xml', $image->mimeType());
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $image->bytes);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\.svg$/', $image->fileName());
        $this->assertSame([24, 24], [$image->width, $image->height]);
    }

    public function testASvgWithAPrologueAndAByteOrderMarkIsRecognized(): void
    {
        // When / Then
        $this->assertSame('svg', $this->normalizer->normalize(Images::svgWithPrologue())->extension);
    }

    // ---------------------------------------------------------------- la transparence

    /**
     * @return iterable<string, array{string}>
     */
    public static function transparentRasters(): iterable
    {
        yield 'PNG' => [Images::transparentPng(40)];
        yield 'WebP sans perte' => [Images::transparentWebp(40)];
        yield 'WebP avec perte' => [Images::transparentLossyWebp(40)];
        yield 'PNG à palette dont une couleur est transparente' => [Images::paletteTransparentPng(40)];
    }

    #[DataProvider('transparentRasters')]
    public function testTransparencySurvivesDecodingResizingAndEncoding(string $bytes): void
    {
        // When
        $image = $this->normalizer->normalize($bytes);

        // Then : le coin reste transparent, le carré rouge reste rouge et opaque
        $this->assertGreaterThan(120, Images::alphaAt($image->bytes, 1, 1), 'Le fond devient opaque.');
        $this->assertSame(0, Images::alphaAt($image->bytes, 14, 14), 'Le carré rouge n\'est plus opaque.');
        $this->assertGreaterThan(200, Images::colorAt($image->bytes, 14, 14)[0]);
    }

    #[DataProvider('transparentRasters')]
    public function testTransparencySurvivesResizing(string $bytes): void
    {
        // Given : le même dessin, de 40 points, redessiné à 1 200 points pour qu'il soit réduit à 512
        $large = $this->scaled($bytes, 1200);

        // When
        $image = $this->normalizer->normalize($large);

        // Then : réduit à 512 points, le fond reste transparent et le carré rouge (de 300 à 600 sur 1 200) reste opaque à son milieu
        $this->assertSame([512, 512], [$image->width, $image->height]);
        $this->assertGreaterThan(120, Images::alphaAt($image->bytes, 2, 2));
        $this->assertLessThan(10, Images::alphaAt($image->bytes, 192, 192));
        $this->assertGreaterThan(200, Images::colorAt($image->bytes, 192, 192)[0]);
    }

    public function testASemiTransparentPixelStaysSemiTransparent(): void
    {
        // When : le carré bleu à moitié transparent (alpha 64 sur 127) du dessin de test
        $png = $this->normalizer->normalize(Images::transparentPng(40));
        $webp = $this->normalizer->normalize(Images::transparentWebp(40));

        // Then
        foreach ([$png, $webp] as $image) {
            $alpha = Images::alphaAt($image->bytes, 25, 25);
            $this->assertGreaterThan(40, $alpha);
            $this->assertLessThan(90, $alpha);
        }
    }

    // ---------------------------------------------------------------- la taille

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function sizes(): iterable
    {
        yield 'plus petite : jamais agrandie' => [100, 60, 100, 60];
        yield 'au bord : 512 de large' => [512, 300, 512, 300];
        yield 'une image large' => [1024, 512, 512, 256];
        yield 'une image haute' => [300, 1200, 128, 512];
        yield 'une image carrée' => [2000, 2000, 512, 512];
        yield 'très allongée : un point de haut au moins' => [4000, 3, 512, 1];
        yield 'à une dimension près' => [513, 513, 512, 512];
    }

    #[DataProvider('sizes')]
    public function testTheImageIsBroughtBackTo512PixelsKeepingItsProportions(int $width, int $height, int $expectedWidth, int $expectedHeight): void
    {
        // When
        $png = $this->normalizer->normalize(Images::png($width, $height));
        $webp = $this->normalizer->normalize(Images::webp($width, $height));

        // Then : dans les deux formats
        foreach ([$png, $webp] as $image) {
            $this->assertSame([$expectedWidth, $expectedHeight], [$image->width, $image->height]);
            $this->assertSame([$expectedWidth, $expectedHeight], [getimagesizefromstring($image->bytes)[0], getimagesizefromstring($image->bytes)[1]]);
        }
    }

    // ---------------------------------------------------------------- les métadonnées et le contenu étranger

    public function testAnythingAppendedToTheFileIsDropped(): void
    {
        // Given : un PNG et un WebP valides suivis d'une page web (un « polyglotte »)
        $trailer = '<script>alert("a-ne-pas-garder")</script>';

        foreach ([Images::png() . $trailer, Images::webp() . $trailer] as $polyglot) {
            // When
            $image = $this->normalizer->normalize($polyglot);

            // Then
            $this->assertStringNotContainsString('a-ne-pas-garder', $image->bytes);
            $this->assertStringNotContainsString('<script', $image->bytes);
        }
    }

    public function testTheTextAndMetadataChunksOfAPngAreDropped(): void
    {
        // Given : un PNG avec un bloc de texte (tEXt) et un bloc EXIF (eXIf) insérés avant IEND
        $png = Images::png();
        $iend = strlen($png) - 12;
        $text = "Comment\0TEXTE-A-NE-PAS-GARDER";
        $exif = 'MARQUE-APPAREIL-SECRETE';
        $chunks = pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text))
            . pack('N', strlen($exif)) . 'eXIf' . $exif . pack('N', crc32('eXIf' . $exif));
        $withMetadata = substr($png, 0, $iend) . $chunks . substr($png, $iend);
        $this->assertStringContainsString('TEXTE-A-NE-PAS-GARDER', $withMetadata);

        // When
        $image = $this->normalizer->normalize($withMetadata);

        // Then
        $this->assertStringNotContainsString('TEXTE-A-NE-PAS-GARDER', $image->bytes);
        $this->assertStringNotContainsString('MARQUE-APPAREIL-SECRETE', $image->bytes);
    }

    // ---------------------------------------------------------------- la même image, le même fichier

    public function testTheSameImageGivesTheSameFileName(): void
    {
        // Given
        foreach ([Images::png(64, 64, Images::GREEN), Images::webp(64, 64, Images::GREEN), Images::svg()] as $bytes) {
            // When
            $first = $this->normalizer->normalize($bytes);
            $second = $this->normalizer->normalize($bytes);

            // Then
            $this->assertSame($first->fileName(), $second->fileName());
            $this->assertSame($first->bytes, $second->bytes);
        }
    }

    public function testTwoDifferentImagesGiveTwoFileNames(): void
    {
        // When / Then
        $this->assertNotSame(
            $this->normalizer->normalize(Images::png(64, 64, Images::GREEN))->fileName(),
            $this->normalizer->normalize(Images::png(64, 64, Images::BLUE))->fileName(),
        );
    }

    public function testTheSameDrawingInTwoFormatsGivesTwoFiles(): void
    {
        // When / Then : chaque raster garde son format, donc son extension et son nom
        $this->assertNotSame(
            $this->normalizer->normalize(Images::png(64, 64))->fileName(),
            $this->normalizer->normalize(Images::webp(64, 64))->fileName(),
        );
    }

    // ---------------------------------------------------------------- les refus

    public function testMoreThanThreeMebibytesIsTooLargeBeforeAnyDecoding(): void
    {
        // Given : une signature PNG suivie d'assez d'octets pour dépasser 3 Mio
        $bytes = "\x89PNG\r\n\x1a\n" . str_repeat('a', ImageNormalizer::MAX_BYTES);

        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::TOO_LARGE);

        // When
        $this->normalizer->normalize($bytes);
    }

    public function testASvgOfMoreThanThreeMebibytesIsTooLarge(): void
    {
        // Given
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><desc>' . str_repeat('a', ImageNormalizer::MAX_BYTES) . '</desc></svg>';

        // Then
        $this->expectExceptionMessage(ImageException::TOO_LARGE);

        // When
        $this->normalizer->normalize($svg);
    }

    public function testExactlyThreeMebibytesIsNotRefusedForItsWeight(): void
    {
        // Given : 3 Mio pile, qui ne sont pas une image
        $bytes = str_repeat('a', ImageNormalizer::MAX_BYTES);

        // Then : refusé, mais parce que ce n'est pas une image
        $this->expectExceptionMessage(ImageException::NOT_AN_IMAGE);

        // When
        $this->normalizer->normalize($bytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notImages(): iterable
    {
        yield 'vide' => [''];
        yield 'un texte' => ['Bonjour, ceci n\'est pas une image.'];
        yield 'une page web' => [Images::htmlPage()];
        yield 'du JSON' => ['{"image": true}'];
        yield 'des octets au hasard' => [str_repeat("\x01\x02\x03\xFE", 100)];
        yield 'une signature PNG sans rien derrière' => ["\x89PNG\r\n\x1a\n"];
        yield 'une signature WebP sans rien derrière' => ['RIFF....WEBP'];
        yield 'un PNG coupé' => [substr(Images::png(), 0, 40)];
        yield 'un WebP abîmé' => [substr(Images::webp(), 0, 30)];
        yield 'une signature PNG puis du texte' => ["\x89PNG\r\n\x1a\n" . 'pas du tout un PNG'];
        yield 'un PDF' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n"];
        yield 'un script PHP' => ['<?php system($_GET["c"]);'];
        yield 'un programme' => ["MZ\x90\x00\x03\x00\x00\x00"];
        yield 'un SVG mal formé' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>'];
        yield 'un SVG coupé' => [substr(Images::svg(), 0, 100)];
    }

    #[DataProvider('notImages')]
    public function testWhatIsNotAReadableImageIsRefused(string $bytes): void
    {
        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::NOT_AN_IMAGE);

        // When
        $this->normalizer->normalize($bytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedFormats(): iterable
    {
        yield 'un JPEG (il ne gère pas la transparence)' => [Images::jpeg()];
        yield 'un JPEG avec un EXIF' => [substr(Images::jpeg(), 0, 2) . "\xFF\xE1\x00\x08Exif\0\0" . substr(Images::jpeg(), 2)];
        yield 'une signature JPEG sans rien derrière' => ["\xFF\xD8\xFF"];
        yield 'un GIF' => [Images::gif()];
        yield 'un BMP' => ['BM' . str_repeat("\0", 60)];
        yield 'un TIFF' => ["II*\0" . str_repeat("\0", 60)];
        yield 'une icône' => ["\0\0\1\0" . str_repeat("\0", 60)];
        yield 'un AVIF' => ["\0\0\0\x1C" . 'ftypavif' . str_repeat("\0", 40)];
        yield 'un HEIC' => ["\0\0\0\x18" . 'ftypheic' . str_repeat("\0", 40)];
    }

    #[DataProvider('unsupportedFormats')]
    public function testAnImageOfAnotherFormatIsRefusedAsUnsupported(string $bytes): void
    {
        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::UNSUPPORTED);

        // When
        $this->normalizer->normalize($bytes);
    }

    public function testTheRefusalOfAJpegIsA422WithTheCodeTheSiteTranslates(): void
    {
        // When
        try {
            $this->normalizer->normalize(Images::jpeg());
            $this->fail('Le JPEG aurait dû être refusé.');
        } catch (ImageException $exception) {
            // Then
            $this->assertSame([422, 'IMAGE_UNSUPPORTED'], [$exception->getCode(), $exception->getMessage()]);
        }
    }

    public function testAWebPageThatMentionsSvgIsNotAnImageAtAll(): void
    {
        // When / Then : une page web qui contient un <svg> reste « pas une image »
        $this->expectExceptionMessage(ImageException::NOT_AN_IMAGE);
        $this->normalizer->normalize(Images::htmlPage());
    }

    // ---------------------------------------------------------------- la bombe de décompression

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function claimedSizes(): iterable
    {
        yield 'des milliards de points annoncés' => [65535, 65535];
        yield 'plus de 8 000 de large' => [8001, 10];
        yield 'plus de 8 000 de haut' => [10, 8001];
        yield '12 millions et un point' => [4000, 3001];
        yield 'très large et très haut' => [100000, 100000];
    }

    #[DataProvider('claimedSizes')]
    public function testAnImageThatClaimsTooManyPixelsIsRefusedBeforeBeingDecoded(int $width, int $height): void
    {
        // Given : quelques dizaines d'octets qui annoncent une image immense
        $bomb = Images::pngClaiming($width, $height);
        $this->assertLessThan(100, strlen($bomb));

        // Then
        $this->expectException(ImageException::class);
        $this->expectExceptionMessage(ImageException::TOO_LARGE);

        // When
        $this->normalizer->normalize($bomb);
    }

    public function testARealPngOverTheLimitOfPixelsIsRefusedWithoutBeingDecoded(): void
    {
        // Given : un vrai PNG à palette de 4 000 × 3 001 points, quelques Ko une fois compressé
        $bomb = Images::palettePng(4000, 3001);
        $this->assertLessThan(ImageNormalizer::MAX_BYTES, strlen($bomb));
        $memory = memory_get_peak_usage(true);

        // When
        try {
            $this->normalizer->normalize($bomb);
            $this->fail('La bombe aurait dû être refusée.');
        } catch (ImageException $exception) {
            // Then : refusée sans que la mémoire ait grimpé de ce que la décompression aurait coûté (48 Mo)
            $this->assertSame(ImageException::TOO_LARGE, $exception->getMessage());
            $this->assertSame(413, $exception->getCode());
            $this->assertLessThan(24 * 1024 * 1024, memory_get_peak_usage(true) - $memory);
        }
    }

    public function testAnImageAtTheLimitOfPixelsIsAccepted(): void
    {
        // Given : 3 000 × 4 000 = 12 millions de points exactement, léger une fois compressé
        $png = Images::palettePng(3000, 4000);

        // When
        $image = $this->normalizer->normalize($png);

        // Then
        $this->assertSame([384, 512], [$image->width, $image->height]);
    }

    public function testAnImageWhoseMemoryCostWouldExceedTheMemoryLimitIsRefusedNotFatal(): void
    {
        // Given : une limite de mémoire juste au-dessus de ce qui est déjà utilisé
        $previous = ini_get('memory_limit');
        $png = Images::palettePng(3000, 3000);
        // (la limite de PHP s'applique à la mémoire réservée, pas seulement à celle qui est utilisée)
        $this->assertNotFalse(ini_set('memory_limit', (string) (memory_get_usage(true) + 30 * 1024 * 1024)));

        try {
            // When / Then : 3 000 × 3 000 × 5 octets (45 Mo) dépasseraient les 30 Mo disponibles
            $this->expectException(ImageException::class);
            $this->expectExceptionMessage(ImageException::TOO_LARGE);
            $this->normalizer->normalize($png);
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    public function testTheRefusalsNeverCiteTheContent(): void
    {
        // When
        try {
            $this->normalizer->normalize('SECRET-DU-CONTENU');
            $this->fail('Le contenu aurait dû être refusé.');
        } catch (ImageException $exception) {
            // Then : un code, rien du contenu
            $this->assertSame([422, 'IMAGE_NOT_AN_IMAGE'], [$exception->getCode(), $exception->getMessage()]);
        }
    }

    /** Le même dessin (une image décodée) redessiné à `$side` points de côté, dans le format de l'original. */
    private function scaled(string $encoded, int $side): string
    {
        $source = imagecreatefromstring($encoded);
        $target = imagecreatetruecolor($side, $side);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $side, $side, imagesx($source), imagesy($source));

        ob_start();
        if (str_starts_with($encoded, 'RIFF')) {
            imagewebp($target, null, IMG_WEBP_LOSSLESS);
        } else {
            imagepng($target);
        }

        return (string) ob_get_clean();
    }
}
