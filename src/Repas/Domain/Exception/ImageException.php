<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

/**
 * Les refus de l'envoi d'une image par l'administrateur (ADR 0039). Les messages sont des constantes que le front traduit en une phrase simple :
 * ils ne se renomment pas, et ils ne disent JAMAIS rien du lien, de l'adresse appelée ni du contenu reçu. Le code HTTP est celui de l'ADR 0039
 * (`413` pour une image trop grosse, `422` pour ce que la personne peut corriger, `502` quand le site du lien ne répond pas comme il faut).
 */
final class ImageException extends DomainException
{
    /** Pas de fichier dans la requête (ou un envoi interrompu). */
    public const string FILE_MISSING = 'IMAGE_FILE_MISSING';
    /** Plus de 3 Mio, des dimensions qui dépassent ce que l'application décode (bombe de décompression) ou un SVG trop complexe (éléments, profondeur). */
    public const string TOO_LARGE = 'IMAGE_TOO_LARGE';
    /** Le contenu n'est pas une image que l'on sait lire (page web, texte, fichier abîmé). */
    public const string NOT_AN_IMAGE = 'IMAGE_NOT_AN_IMAGE';
    /**
     * Pas d'un format accepté : seuls PNG, WebP et SVG le sont (le JPEG ne gère pas la transparence ; ni GIF, ni le reste), ou un SVG dont il ne reste rien
     * à garder une fois assaini (ADR 0039). Le site dit « PNG, WebP ou SVG ».
     */
    public const string UNSUPPORTED = 'IMAGE_UNSUPPORTED';
    /** Le lien n'est pas accepté : pas en https, port, adresse locale ou privée, trop de redirections. */
    public const string URL_REFUSED = 'IMAGE_URL_REFUSED';
    /** Le site du lien n'a pas répondu comme il faut (délai dépassé, erreur réseau ou TLS, statut autre que 200). */
    public const string DOWNLOAD_FAILED = 'IMAGE_DOWNLOAD_FAILED';
    /** Le plafond de téléchargements par lien de l'administrateur est atteint : `429` + `Retry-After`, portée par `RateLimitedException`. */
    public const string RATE_LIMITED = 'IMAGE_DOWNLOAD_RATE_LIMITED';
    /** Le disque du serveur n'a pas pu garder l'image (dossier absent, plein ou en lecture seule). */
    public const string STORAGE_FAILED = 'IMAGE_STORAGE_FAILED';
    /** Le serveur n'a pas pu transformer une image pourtant valide (extension d'image absente ou en panne) : une panne, pas une faute de la personne. */
    public const string PROCESSING_FAILED = 'IMAGE_PROCESSING_FAILED';

    public static function fileMissing(): self
    {
        return new self(self::FILE_MISSING, 422);
    }

    public static function tooLarge(): self
    {
        return new self(self::TOO_LARGE, 413);
    }

    public static function notAnImage(): self
    {
        return new self(self::NOT_AN_IMAGE, 422);
    }

    public static function unsupported(): self
    {
        return new self(self::UNSUPPORTED, 422);
    }

    public static function urlRefused(): self
    {
        return new self(self::URL_REFUSED, 422);
    }

    public static function downloadFailed(): self
    {
        return new self(self::DOWNLOAD_FAILED, 502);
    }

    public static function storageFailed(): self
    {
        return new self(self::STORAGE_FAILED, 500);
    }

    public static function processingFailed(): self
    {
        return new self(self::PROCESSING_FAILED, 500);
    }
}
