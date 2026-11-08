<?php

namespace Repas\Repas\Application\Command\StoreUploadedImage;


/**
 * Garder l'image qu'un administrateur vient d'envoyer en fichier (ADR 0039). `content` est le contenu du fichier tel que le client l'a envoyé : rien n'en
 * est cru, `ImageNormalizer` en reconnaît le type, le décode et le ré-encode. Le contrôleur n'a lu que 3 Mio + 1 octet au plus pour le constituer.
 */
readonly class StoreUploadedImageCommand
{
    public function __construct(
        public string $content,
    ) {
    }
}
