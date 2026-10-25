<?php

namespace Repas\Voice\Domain\Model;


/**
 * L'adresse de la chaîne de certificats d'Amazon (`SignatureCertChainUrl`), une fois jugée acceptable (ADR 0029 décision 7, page
 * « Host a custom skill as a web service »). L'en-tête vient de la requête, donc de n'importe qui : c'est l'adresse que le serveur
 * va télécharger, elle ne sort de ce type que si TOUT ceci est vrai :
 *
 * - après suppression du fragment, le protocole est `https`, l'hôte est `s3.amazonaws.com` (majuscules admises), le port est
 *   absent ou `443` ;
 * - le chemin ne contient que des lettres, des chiffres et `. _ ~ - /` : ni `%` (un `%2e%2e` se normaliserait en douce), ni
 *   `?`, ni `\`, ni `@`, ni espace ou caractère de contrôle ; l'adresse fait 512 octets au plus ;
 * - une fois normalisé (barres doublées fusionnées, segments `.` et `..` résolus, un `..` qui sortirait de la racine refusé), le
 *   chemin commence par `/echo.api/` (majuscules NON admises) et désigne un fichier (il ne finit ni par `/`, ni par `.`, ni par `..`).
 *
 * `value()` est l'adresse NORMALISÉE (`https://s3.amazonaws.com/echo.api/…`, sans port) : c'est elle qu'on télécharge et qui sert de
 * clé de cache, quelle que soit la façon dont Amazon l'a écrite. Plus strict que la page d'Amazon sur la requête et le `%`, par
 * prudence : les adresses réelles (`…/echo.api/echo-api-cert-N.pem`) n'en ont jamais.
 */
final readonly class CertificateChainUrl
{
    public const string ORIGIN = 'https://s3.amazonaws.com';
    public const string DIRECTORY = 'echo.api';
    public const int MAX_LENGTH = 512;

    private const string PATTERN = '#\A(?i:https)://(?i:s3\.amazonaws\.com)(?::443)?(?<path>/[A-Za-z0-9._~/-]*)\z#';

    private function __construct(private string $value)
    {
    }

    /**
     * L'adresse de l'en-tête si elle est acceptable, sinon `null` (l'appelant refuse la requête).
     */
    public static function tryFrom(string $header): ?self
    {
        if ($header === '' || strlen($header) > self::MAX_LENGTH) {
            return null;
        }

        // La page d'Amazon : le fragment s'enlève avant de juger.
        $withoutFragment = explode('#', $header, 2)[0];
        if (preg_match(self::PATTERN, $withoutFragment, $matches) !== 1) {
            return null;
        }

        $path = self::normalizePath($matches['path']);
        if ($path === null) {
            return null;
        }

        return new self(self::ORIGIN . $path);
    }

    /**
     * Le chemin normalisé (`/echo.api/x.pem`), ou `null` s'il ne désigne pas un fichier de `/echo.api/`.
     */
    private static function normalizePath(string $path): ?string
    {
        $segments = explode('/', $path);
        $last = $segments[array_key_last($segments)];
        if (in_array($last, ['', '.', '..'], true)) {
            return null;
        }

        $kept = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($kept === []) {
                    return null;
                }
                array_pop($kept);
                continue;
            }
            $kept[] = $segment;
        }

        if (count($kept) < 2 || $kept[0] !== self::DIRECTORY) {
            return null;
        }

        return '/' . implode('/', $kept);
    }

    public function value(): string
    {
        return $this->value;
    }
}
