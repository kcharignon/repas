<?php

namespace Repas\Repas\Application\Command\StoreUploadedImage;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Interface\ImageStorage;
use Repas\Repas\Domain\Service\ImageNormalizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Garde l'image d'un fichier envoyé par l'administrateur et rend son chemin (`images/uploads/{empreinte}.webp`), celui du champ `image` d'un rayon ou
 * d'un type de recette (ADR 0039). Aucune base de données : le contenu est reconnu, décodé et ré-encodé (`ImageNormalizer`), puis écrit sous son
 * empreinte (`ImageStorage`). Un refus n'écrit rien.
 */
#[AsMessageHandler]
readonly class StoreUploadedImageHandler
{
    public function __construct(
        private ImageNormalizer $normalizer,
        private ImageStorage $storage,
    ) {
    }

    /**
     * @return string le chemin de l'image, relatif à la racine publique
     *
     * @throws ImageException `tooLarge`, `notAnImage`, `unsupported`, `processingFailed` ou `storageFailed`
     */
    public function __invoke(StoreUploadedImageCommand $command): string
    {
        return $this->storage->store($this->normalizer->normalize($command->content));
    }
}
