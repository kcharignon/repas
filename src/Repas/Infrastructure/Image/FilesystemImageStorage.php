<?php

namespace Repas\Repas\Infrastructure\Image;


use Psr\Log\LoggerInterface;
use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageStorage;
use Repas\Repas\Domain\Model\ProcessedImage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Garde les images envoyées dans `{racine publique}/images/uploads/` (ADR 0039). En production ce dossier est le volume Docker `uploads`
 * (deploy/compose.yaml) : il survit aux déploiements et à la mise à jour du conteneur, et il est sauvegardé (deploy/backup.sh). Le serveur de fichiers du
 * back le sert tel quel sous `/images/uploads/`.
 *
 * - **Le nom est l'empreinte du contenu** (`ProcessedImage::fileName()`, jamais un nom venu de la personne) : écrire deux fois la même image ne fait
 *   rien (le fichier est déjà là), et deux envois simultanés de la même image écrivent le même contenu au même endroit.
 * - **Écriture atomique** : le contenu va dans un fichier temporaire du même dossier, puis `rename` le met à sa place d'un coup. Un fichier à moitié
 *   écrit n'est jamais servi, et une écriture interrompue ne remplace jamais une bonne image (au pire un fichier `.….partial` orphelin, que rien ne sert).
 * - **Droits** : dossier 0755, fichiers 0644 (lisibles par le serveur de fichiers, quel que soit l'utilisateur qui les a écrits).
 *
 * Un échec (dossier absent et impossible à créer, disque plein, lecture seule) lève `storageFailed` ; le journal reçoit la raison, jamais le contenu.
 */
final readonly class FilesystemImageStorage implements ImageStorage
{
    private const int DIRECTORY_MODE = 0755;
    private const int FILE_MODE = 0644;

    public function __construct(
        /** La racine publique du back (`public/`) ; les images vont dans son sous-dossier `images/uploads`. */
        #[Autowire('%repas.images.public_directory%')]
        private string $publicDirectory,
        private LoggerInterface $logger,
    ) {
    }

    public function store(ProcessedImage $image): string
    {
        $directory = rtrim($this->publicDirectory, '/') . '/' . self::DIRECTORY;
        $target = $directory . '/' . $image->fileName();

        // Déjà là, de la bonne taille : rien à écrire (les octets sont ceux de l'empreinte, un fichier de la même taille est le même)
        if (is_file($target) && filesize($target) === strlen($image->bytes)) {
            return self::DIRECTORY . '/' . $image->fileName();
        }

        if (!is_dir($directory) && !@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            throw $this->failed('le dossier des images ne peut pas être créé');
        }

        $temporary = $directory . '/.' . bin2hex(random_bytes(8)) . '.partial';
        if (@file_put_contents($temporary, $image->bytes) !== strlen($image->bytes)) {
            @unlink($temporary);

            throw $this->failed('l\'écriture du fichier a échoué');
        }

        @chmod($temporary, self::FILE_MODE);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);

            throw $this->failed('le fichier ne peut pas être mis en place');
        }

        return self::DIRECTORY . '/' . $image->fileName();
    }

    private function failed(string $reason): ImageException
    {
        $this->logger->error('Image envoyée non gardée.', ['reason' => $reason]);

        return ImageException::storageFailed();
    }
}
