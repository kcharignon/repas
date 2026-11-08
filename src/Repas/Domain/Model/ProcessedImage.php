<?php

namespace Repas\Repas\Domain\Model;


use InvalidArgumentException;

/**
 * Une image envoyée par l'administrateur, telle qu'elle est gardée (ADR 0039) : DÉCODÉE puis RÉ-ENCODÉE par `ImageNormalizer` (PNG ou WebP, 512 px de
 * côté au plus, sans métadonnée) ou, pour un SVG, ré-écrite depuis l'arbre assaini par une liste blanche (`SvgSanitizer`) ; nommée par l'empreinte
 * SHA-256 de ses octets. Le nom ne vient donc jamais de la personne qui l'a envoyée, et la même image donne toujours le même fichier (rien n'est écrit
 * deux fois). Chaque raster reste dans son format : un PNG reste un PNG, un WebP reste un WebP (la transparence est gardée dans les deux).
 */
final readonly class ProcessedImage
{
    public const string PNG = 'png';
    public const string WEBP = 'webp';
    public const string SVG = 'svg';

    /** Les trois formats gardés, avec le type de contenu sous lequel le serveur de fichiers les sert (d'après l'extension). */
    public const array MIME_TYPES = [
        self::PNG => 'image/png',
        self::WEBP => 'image/webp',
        self::SVG => 'image/svg+xml',
    ];

    private function __construct(
        /** Les octets du fichier à écrire. */
        public string $bytes,
        /** `png`, `webp` ou `svg` : l'extension du fichier, qui décide du type de contenu servi. */
        public string $extension,
        /** L'empreinte SHA-256 des octets, en hexadécimal minuscule (64 caractères). */
        public string $hash,
        public int $width,
        public int $height,
    ) {
    }

    public static function of(string $bytes, string $extension, int $width, int $height): self
    {
        if (!isset(self::MIME_TYPES[$extension])) {
            throw new InvalidArgumentException('Format d\'image gardé inconnu.');
        }

        return new self($bytes, $extension, hash('sha256', $bytes), $width, $height);
    }

    /** Le nom du fichier : `{empreinte}.{extension}`. */
    public function fileName(): string
    {
        return $this->hash . '.' . $this->extension;
    }

    public function mimeType(): string
    {
        return self::MIME_TYPES[$this->extension];
    }
}
