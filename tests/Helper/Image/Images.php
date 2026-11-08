<?php

namespace Repas\Tests\Helper\Image;


/**
 * Des images de test fabriquées par GD (aucun fichier dans le dépôt) : PNG, WebP, JPEG (refusé), SVG propres et piégés, et de quoi faire échouer le
 * traitement (page web, GIF, bombe de décompression).
 */
final class Images
{
    public const array RED = [255, 0, 0];
    public const array GREEN = [0, 255, 0];
    public const array BLUE = [0, 0, 255];

    /** Un PNG plein d'une couleur. */
    public static function png(int $width = 40, int $height = 20, array $color = self::RED): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));

        return self::encode(static fn () => imagepng($image));
    }

    /** Un PNG à fond transparent avec un carré rouge opaque et un carré bleu à moitié transparent. */
    public static function transparentPng(int $size = 40): string
    {
        return self::encode(static fn () => imagepng(self::transparentImage($size)));
    }

    /** Un WebP (sans perte) à fond transparent avec un carré rouge opaque et un carré bleu à moitié transparent. */
    public static function transparentWebp(int $size = 40): string
    {
        return self::encode(static fn () => imagewebp(self::transparentImage($size), null, IMG_WEBP_LOSSLESS));
    }

    /** Un WebP avec perte (qualité 80) à fond transparent. */
    public static function transparentLossyWebp(int $size = 40): string
    {
        return self::encode(static fn () => imagewebp(self::transparentImage($size), null, 80));
    }

    /** Un PNG à palette dont une couleur est déclarée transparente (le format historique de la transparence). */
    public static function paletteTransparentPng(int $size = 40): string
    {
        $image = imagecreate($size, $size);
        $transparent = imagecolorallocate($image, 1, 2, 3);
        $red = imagecolorallocate($image, 255, 0, 0);
        imagecolortransparent($image, $transparent);
        imagefilledrectangle($image, (int) ($size / 4), (int) ($size / 4), (int) ($size * 3 / 4), (int) ($size * 3 / 4), $red);

        return self::encode(static fn () => imagepng($image));
    }

    /** Un PNG à palette (une couleur par pixel), de la taille voulue : léger à fabriquer, une bombe s'il est grand. */
    public static function palettePng(int $width, int $height): string
    {
        $image = imagecreate($width, $height);
        imagecolorallocate($image, 10, 20, 30);

        return self::encode(static fn () => imagepng($image, null, 9));
    }

    public static function jpeg(int $width = 40, int $height = 20, array $color = self::RED): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));

        return self::encode(static fn () => imagejpeg($image, null, 95));
    }

    public static function webp(int $width = 40, int $height = 20, array $color = self::RED): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));

        return self::encode(static fn () => imagewebp($image, null, 90));
    }

    public static function gif(): string
    {
        $image = imagecreate(10, 10);
        imagecolorallocate($image, 255, 0, 0);

        return self::encode(static fn () => imagegif($image));
    }

    /** Un SVG propre : un fond, un cercle, un tracé et un dégradé, sans rien de dangereux. */
    public static function svg(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
  <title>Flocon</title>
  <defs>
    <linearGradient id="degrade" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4fc3f7"/><stop offset="1" stop-color="#0277bd"/></linearGradient>
  </defs>
  <rect width="24" height="24" rx="4" fill="url(#degrade)"/>
  <circle cx="12" cy="12" r="5" fill="none" stroke="#fff" stroke-width="2"/>
  <path d="M12 2v20M2 12h20" stroke="#fff" stroke-linecap="round" fill="none"/>
</svg>
SVG;
    }

    public static function svgWithPrologue(): string
    {
        return "\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- un commentaire -->\n" . self::svg();
    }

    public static function htmlPage(): string
    {
        return '<!DOCTYPE html><html><head><title>Une page</title></head><body><svg></svg></body></html>';
    }

    /**
     * Un PNG dont l'en-tête annonce `$width` × `$height` points et dont le reste n'est pas une image : une bombe de décompression telle qu'un
     * attaquant la fabriquerait (quelques dizaines d'octets, des milliards de points annoncés). L'en-tête suffit à `getimagesizefromstring`.
     */
    public static function pngClaiming(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n" . pack('N', strlen($header)) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    }

    /** La couleur (rouge, vert, bleu) du point (x, y) d'une image encodée, lue en la décodant. */
    public static function colorAt(string $encoded, int $x, int $y): array
    {
        $image = imagecreatefromstring($encoded);
        $rgb = imagecolorat($image, $x, $y);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    /** Le point (x, y) d'une image encodée, avec sa transparence (0 opaque à 127 transparent). */
    public static function alphaAt(string $encoded, int $x, int $y): int
    {
        $image = imagecreatefromstring($encoded);

        return (imagecolorat($image, $x, $y) >> 24) & 0x7F;
    }

    private static function transparentImage(int $size): \GdImage
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        $quarter = (int) ($size / 4);
        imagefilledrectangle($image, $quarter, $quarter, $quarter * 2 - 1, $quarter * 2 - 1, imagecolorallocatealpha($image, 255, 0, 0, 0));
        imagefilledrectangle($image, $quarter * 2, $quarter * 2, $quarter * 3 - 1, $quarter * 3 - 1, imagecolorallocatealpha($image, 0, 0, 255, 64));

        return $image;
    }

    /**
     * @param callable(): bool $writer écrit l'image sur la sortie standard
     */
    private static function encode(callable $writer): string
    {
        ob_start();
        $writer();

        return (string) ob_get_clean();
    }
}
