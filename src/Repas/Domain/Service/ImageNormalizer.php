<?php

namespace Repas\Repas\Domain\Service;


use GdImage;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Model\ProcessedImage;

/**
 * Transforme ce que l'administrateur envoie (un fichier, ou les octets téléchargés d'un lien) en l'image que l'application garde (ADR 0039). Le
 * même traitement pour les deux, jamais une image gardée telle quelle :
 *
 * 1. **Taille** : 3 Mio au plus (`MAX_BYTES`).
 * 2. **Type reconnu par le CONTENU**, jamais par l'extension ni par le `Content-Type` du client : PNG, WebP ou SVG. Le JPEG est refusé (`unsupported` : il ne
 *    gère pas la transparence), comme le GIF, le BMP, le TIFF, l'AVIF… ; tout le reste est `notAnImage`.
 * 3. **SVG** : jamais gardé tel quel. Il est assaini par une liste blanche d'éléments et d'attributs puis ré-écrit depuis l'arbre assaini (`SvgSanitizer`).
 * 4. **PNG et WebP** : dimensions lues AVANT de décoder (l'en-tête seul) : 8 000 px de côté et 12 millions de pixels au plus, et de quoi tenir en mémoire,
 *    sinon `tooLarge` (une bombe de décompression est un petit fichier qui annonce une image immense). Puis **décodés et ré-encodés dans leur format**
 *    (un PNG reste un PNG, un WebP reste un WebP, sans perte) : les métadonnées (EXIF, profils, commentaires) et tout contenu étranger collé au fichier
 *    disparaissent avec les octets d'origine. **La transparence est gardée** à chaque étape : le décodage la lit, la toile d'arrivée la porte (mélange
 *    des couleurs coupé, canal alpha enregistré) et les deux encodeurs l'écrivent.
 * 5. **Ramenés à 512 px de côté au plus**, proportions gardées, jamais agrandis.
 * 6. **Nommé par l'empreinte SHA-256** du résultat (`ProcessedImage`) : la même image donne le même fichier.
 *
 * GD est une extension de PHP, pas un service : ce service de domaine reste pur et se teste sans infrastructure.
 */
final readonly class ImageNormalizer
{
    public const int MAX_BYTES = 3 * 1024 * 1024;
    public const int MAX_SIDE = 8000;
    public const int MAX_PIXELS = 12_000_000;
    public const int TARGET_SIDE = 512;

    /**
     * Octets de mémoire par point d'une image GD décodée : 4 en couleur vraie avec transparence, plus 1 pour l'image à palette (un PNG à palette est
     * décodé tel quel avant d'être converti : les deux coexistent un instant). Une marge garde le reste de la requête.
     */
    private const int DECODING_BYTES_PER_PIXEL = 5;
    private const int CANVAS_BYTES_PER_PIXEL = 4;
    private const int MEMORY_MARGIN = 16 * 1024 * 1024;

    public function __construct(
        private SvgSanitizer $svgSanitizer = new SvgSanitizer(),
    ) {
    }

    /**
     * @throws ImageException `tooLarge`, `notAnImage`, `unsupported` ou `processingFailed`
     */
    public function normalize(string $bytes): ProcessedImage
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ImageException::tooLarge();
        }

        $format = $this->format($bytes);
        if ($format === ProcessedImage::SVG) {
            return $this->svgSanitizer->sanitize($bytes);
        }

        [$width, $height] = $this->declaredSize($bytes, $format);
        $this->assertFitsInMemory($width, $height);

        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof GdImage) {
            throw ImageException::notAnImage();
        }
        if (!imageistruecolor($source) && !@imagepalettetotruecolor($source)) {
            throw ImageException::notAnImage();
        }
        $width = imagesx($source);
        $height = imagesy($source);

        [$targetWidth, $targetHeight] = $this->targetSize($width, $height);
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        // La transparence est gardée : le mélange des couleurs est coupé et le canal alpha sera écrit ; la toile commence transparente et la copie
        // remplace ses points (jamais de mélange avec du noir, un point transparent de l'original reste transparent)
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent === false) {
            throw ImageException::processingFailed();
        }
        imagefill($canvas, 0, 0, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $encoded = $format === ProcessedImage::PNG ? imagepng($canvas, null, 9) : imagewebp($canvas, null, IMG_WEBP_LOSSLESS);
        $encodedBytes = (string) ob_get_clean();
        if ($encoded === false || $encodedBytes === '') {
            throw ImageException::processingFailed();
        }

        return ProcessedImage::of($encodedBytes, $format, $targetWidth, $targetHeight);
    }

    /**
     * @return 'png'|'webp'|'svg'
     *
     * @throws ImageException
     */
    private function format(string $bytes): string
    {
        // Un PNG commence par sa signature puis par le bloc IHDR (ses dimensions) : sans lui, ce ne sont pas des dimensions
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && substr($bytes, 12, 4) === 'IHDR') {
            return ProcessedImage::PNG;
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return ProcessedImage::WEBP;
        }
        if ($this->looksLikeSvg($bytes)) {
            return ProcessedImage::SVG;
        }

        throw $this->isAnotherImageFormat($bytes) ? ImageException::unsupported() : ImageException::notAnImage();
    }

    /** Du texte qui commence comme un SVG (marque d'ordre des octets, espaces, prologue XML, commentaire ou DOCTYPE permis) ; jamais une page web. */
    private function looksLikeSvg(string $bytes): bool
    {
        $head = ltrim(substr($bytes, 0, 2048), "\xEF\xBB\xBF \t\r\n");
        if (preg_match('~^<!doctype\s+html|<html[\s>]~i', $head) === 1) {
            return false;
        }
        if (preg_match('~^<svg\b~i', $head) === 1) {
            return true;
        }

        return preg_match('~^(?:<\?xml\b|<!--|<!doctype\b)~i', $head) === 1 && stripos($head, '<svg') !== false;
    }

    /** Une image qu'on reconnaît mais qu'on n'accepte pas (le JPEG, qui ne gère pas la transparence, en premier). */
    private function isAnotherImageFormat(string $bytes): bool
    {
        foreach (["\xFF\xD8\xFF", 'GIF87a', 'GIF89a', 'BM', "II*\0", "MM\0*", "\0\0\1\0", "\xFF\x4F\xFF\x51", "\x00\x00\x00\x0CjP  "] as $signature) {
            if (str_starts_with($bytes, $signature)) {
                return true;
            }
        }

        // AVIF, HEIC : `ftyp` aux octets 4 à 7
        return substr($bytes, 4, 4) === 'ftyp';
    }

    /**
     * Les dimensions annoncées par l'en-tête (rien n'est décodé), et la preuve que l'en-tête est celui du format reconnu.
     *
     * @return array{int, int}
     *
     * @throws ImageException
     */
    private function declaredSize(string $bytes, string $format): array
    {
        $info = @getimagesizefromstring($bytes);
        $expectedType = $format === ProcessedImage::PNG ? IMAGETYPE_PNG : IMAGETYPE_WEBP;
        if ($info === false || $info[2] !== $expectedType || $info[0] < 1 || $info[1] < 1) {
            throw ImageException::notAnImage();
        }

        if ($info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw ImageException::tooLarge();
        }

        return [$info[0], $info[1]];
    }

    /** Décoder tiendra-t-il dans la mémoire qui reste ? Sinon `tooLarge` plutôt qu'une erreur fatale qui coupe le processus. */
    private function assertFitsInMemory(int $width, int $height): void
    {
        $limit = $this->memoryLimit();
        if ($limit === null) {
            return;
        }

        // L'image décodée, la toile qu'on en tire (512 points de côté au plus, jamais plus grande que l'original) et une marge pour le reste de la requête
        $canvas = min($width * $height, self::TARGET_SIDE * self::TARGET_SIDE) * self::CANVAS_BYTES_PER_PIXEL;
        if (memory_get_usage(true) + $width * $height * self::DECODING_BYTES_PER_PIXEL + $canvas + self::MEMORY_MARGIN > $limit) {
            throw ImageException::tooLarge();
        }
    }

    /** La limite de mémoire de PHP en octets, `null` quand il n'y en a pas. */
    private function memoryLimit(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;
        $unit = strtolower(substr($raw, -1));

        return match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * La taille finale : 512 px de côté au plus, proportions gardées, jamais agrandie, 1 px au moins.
     *
     * @return array{int, int}
     */
    private function targetSize(int $width, int $height): array
    {
        $longest = max($width, $height);
        if ($longest <= self::TARGET_SIDE) {
            return [$width, $height];
        }

        $ratio = self::TARGET_SIDE / $longest;

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }
}
