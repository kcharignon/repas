<?php

namespace Repas\Voice\Domain\Interface;


use Repas\Voice\Domain\Exception\VoiceException;

/**
 * Prouve qu'une requête reçue par le webhook vient bien d'Alexa et vise bien notre skill (ADR 0029 décision 7). Le webhook est
 * public : sans ce contrôle, n'importe qui écrirait dans une liste en se faisant passer pour Alexa. À appeler AVANT tout
 * traitement de la requête, avec le corps BRUT lu une seule fois (la signature porte sur ses octets exacts, pas sur le JSON décodé).
 *
 * Tout est refusé sauf si tout ceci est vrai : l'identifiant du skill est configuré ; le corps est un JSON d'Alexa dont
 * `request.timestamp` est à 150 s près de l'horloge, et dont l'`applicationId` est celui de notre skill ; l'adresse de la chaîne de
 * certificats est celle d'Amazon ; le certificat est valide, nomme `echo-api.amazon.com` et remonte à une racine de confiance ;
 * la signature (`Signature-256`) est celle du corps par ce certificat.
 */
interface AlexaRequestVerifier
{
    /**
     * @param string $rawBody       le corps de la requête, octet pour octet
     * @param string $certChainUrl  l'en-tête `SignatureCertChainUrl`
     * @param string $signature256  l'en-tête `Signature-256` (signature RSA SHA-256 du corps, en base64)
     *
     * @throws VoiceException `invalidRequest()` quel que soit le motif du refus (le motif va au journal, jamais à l'appelant)
     */
    public function verify(string $rawBody, string $certChainUrl, string $signature256): void;
}
