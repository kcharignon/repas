<?php

namespace Repas\Voice\Domain\Interface;


use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Model\CertificateChainUrl;

/**
 * Obtient la chaîne de certificats avec laquelle Amazon signe ses requêtes (celle que désigne `SignatureCertChainUrl`). Seule une
 * adresse déjà jugée acceptable (`CertificateChainUrl`) peut être demandée : le port ne télécharge jamais une adresse libre.
 */
interface CertificateChainFetcher
{
    /**
     * @return string les certificats X.509 de la chaîne au format PEM, le certificat de signature d'abord (ni clé, ni autre contenu)
     *
     * @throws VoiceException `certificateChainUnavailable()` : réseau, statut, taille ou forme inacceptables, chaîne expirée
     */
    public function fetch(CertificateChainUrl $url): string;
}
