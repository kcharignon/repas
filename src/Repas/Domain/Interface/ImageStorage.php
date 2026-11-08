<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Model\ProcessedImage;

/**
 * Garde les images envoyées par l'administrateur (ADR 0039). Le nom du fichier est l'empreinte de son contenu (`ProcessedImage::fileName()`) : écrire
 * deux fois la même image ne change rien. Le dossier est servi tel quel sous `/images/uploads/` ; l'adaptateur (le disque, dans un volume qui survit
 * aux déploiements) est dans l'infrastructure.
 */
interface ImageStorage
{
    /** Le dossier des images envoyées, relatif à la racine publique : le chemin rendu à l'administrateur commence par lui. */
    public const string DIRECTORY = 'images/uploads';

    /**
     * Écrit l'image si elle n'y est pas déjà (écriture atomique : un fichier à moitié écrit n'est jamais visible) et rend son chemin
     * (`images/uploads/{empreinte}.webp`), celui que l'administrateur met dans le champ `image` d'un rayon ou d'un type de recette.
     *
     * @throws ImageException `storageFailed` quand le disque n'a pas pu la garder
     */
    public function store(ProcessedImage $image): string;
}
