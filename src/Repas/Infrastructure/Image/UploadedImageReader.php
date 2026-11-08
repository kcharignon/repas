<?php

namespace Repas\Repas\Infrastructure\Image;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Lit le fichier d'une requête `multipart/form-data` pour `POST /api/v1/images` (ADR 0039) : c'est tout ce que le contrôleur sait du fichier. La
 * lecture est BORNÉE : au plus 3 Mio + 1 octet entrent en mémoire, quelle que soit la taille annoncée par le client. Le type, les dimensions et le
 * contenu ne sont pas jugés ici (`ImageNormalizer` le fait, comme pour un lien) ; ni le nom ni le type de contenu du client ne sont lus.
 */
final readonly class UploadedImageReader
{
    /**
     * @param mixed $file ce que la requête a pour le champ `file` : un fichier, ou rien (ou autre chose qu'un fichier : un texte, une liste)
     *
     * @throws ImageException `fileMissing` (pas de fichier, envoi interrompu ou illisible), `tooLarge` (plus de 3 Mio)
     */
    public function read(mixed $file): string
    {
        if (!$file instanceof UploadedFile) {
            throw ImageException::fileMissing();
        }

        // PHP refuse au-delà de `upload_max_filesize` (4 Mio, frankenphp/conf.d/10-app.ini) : sans fichier, mais le refus a un nom
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw ImageException::tooLarge();
        }

        // `isValid` : l'envoi s'est bien terminé ET le fichier vient d'un vrai envoi HTTP (`is_uploaded_file`), jamais d'un chemin choisi par le client
        if (!$file->isValid()) {
            throw ImageException::fileMissing();
        }

        $size = $file->getSize();
        if ($size !== false && $size > ImageNormalizer::MAX_BYTES) {
            throw ImageException::tooLarge();
        }

        $handle = @fopen($file->getPathname(), 'rb');
        if ($handle === false) {
            throw ImageException::fileMissing();
        }

        try {
            $content = stream_get_contents($handle, ImageNormalizer::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if ($content === false) {
            throw ImageException::fileMissing();
        }
        if (strlen($content) > ImageNormalizer::MAX_BYTES) {
            throw ImageException::tooLarge();
        }

        return $content;
    }
}
