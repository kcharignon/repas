<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\ImageException;
use Repas\Repas\Domain\Model\ImageUrl;

/**
 * Télécharge l'image qu'un administrateur désigne par un lien direct (ADR 0039). Contrairement au lecteur de page Marmiton, l'hôte est quelconque :
 * c'est l'adaptateur (`Infrastructure\Image`) qui garantit qu'on ne parle jamais à une adresse non publique. Rien n'est écrit, rien n'est décodé :
 * l'appelant fait passer les octets rendus par exactement le même traitement qu'un fichier envoyé (`ImageNormalizer`).
 */
interface ImageFetcher
{
    /**
     * Les octets de l'image, 3 Mio au plus (`ImageNormalizer::MAX_BYTES`).
     *
     * @throws ImageException `urlRefused` (adresse non publique, redirection refusée ou trop nombreuse), `tooLarge` (plus de 3 Mio),
     *                        `notAnImage` (la réponse n'est pas d'un type image), `downloadFailed` (délai, réseau, TLS, statut autre que 200)
     */
    public function fetch(ImageUrl $url): string;
}
