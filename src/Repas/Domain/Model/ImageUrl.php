<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\ImageException;

/**
 * Le lien direct d'une image qu'un administrateur demande à l'application de télécharger (ADR 0039). Contrairement à `MarmitonUrl`, l'hôte est
 * quelconque : la forme est donc jugée STRICTEMENT ici, et l'adaptateur HTTP (`Infrastructure\Image\HttpImageFetcher`) vérifie ensuite que le nom se
 * résout vers des adresses publiques, à chaque redirection. Ce qui est accepté, rien d'autre :
 *
 * - le schéma `https` (jamais `http`, jamais un autre schéma) ;
 * - un NOM DE DOMAINE d'au moins deux étiquettes, en ASCII (un nom accentué s'écrit en `xn--`), dont la dernière commence par une lettre : jamais
 *   une adresse IP (`1.2.3.4`, `2130706433`, `[::1]`), jamais un nom sans point (`localhost`, `database`, `php`), jamais un suffixe réservé au réseau
 *   local (`.local`, `.internal`, `.lan`, `.arpa`…) ;
 * - ni nom d'utilisateur (`user@`), ni mot de passe, ni barre oblique inverse, ni espace, ni caractère de contrôle ;
 * - le port 443, écrit ou non (tout autre port est refusé) ;
 * - 2 000 caractères au plus. Les octets non ASCII du chemin ou de la requête sont encodés (`%C3%A9`) ; le fragment (`#…`) est écarté.
 *
 * Aucun analyseur d'adresse n'est utilisé : une expression ancrée aux deux bouts, puis une vérification du nom.
 */
final readonly class ImageUrl
{
    public const int MAX_LENGTH = 2000;
    public const int PORT = 443;

    private const string PATTERN = '~^https://([^/?#@:]+)(?::([0-9]{1,5}))?([/?][^#]*)?(?:#.*)?\z~Di';
    private const string HOST_PATTERN = '~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z~D';
    /** Les suffixes réservés aux réseaux locaux : refusés avant même de résoudre le nom. */
    private const array LOCAL_SUFFIXES = ['.local', '.localhost', '.localdomain', '.internal', '.intranet', '.lan', '.home', '.corp', '.private', '.arpa'];

    private function __construct(
        /** Le nom de domaine, en minuscules ASCII. */
        public string $host,
        /** Le chemin et la requête, toujours précédés de `/` (`/images/a.png?v=2`). */
        public string $target,
    ) {
    }

    /**
     * @throws ImageException `urlRefused` pour tout ce qui n'est pas un lien https vers un nom de domaine public
     */
    public static function parse(string $raw): self
    {
        $value = trim($raw, " \t\n\r");
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw ImageException::urlRefused();
        }

        // Ni espace, ni caractère de contrôle, ni octet nul, ni barre oblique inverse (certains clients la lisent comme une barre oblique).
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            throw ImageException::urlRefused();
        }

        // Un octet non ASCII du chemin ou de la requête s'écrit en pourcent ; dans le nom, il fera échouer la vérification qui suit.
        $value = (string) preg_replace_callback('/[\x80-\xff]/', static fn (array $byte): string => sprintf('%%%02X', ord($byte[0])), $value);

        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw ImageException::urlRefused();
        }

        $host = strtolower($matches[1]);
        if (!self::isPublicName($host)) {
            throw ImageException::urlRefused();
        }

        $port = $matches[2] ?? '';
        if ($port !== '' && (int) $port !== self::PORT) {
            throw ImageException::urlRefused();
        }

        $target = $matches[3] ?? '';
        if ($target === '') {
            $target = '/';
        } elseif ($target[0] === '?') {
            $target = '/' . $target;
        }

        return new self($host, $target);
    }

    /** L'adresse appelée, toujours en `https` sur le port 443 : `https://{nom}{chemin}`. */
    public function toString(): string
    {
        return 'https://' . $this->host . $this->target;
    }

    /**
     * Où mène l'en-tête `Location` d'une redirection de `$this` : une adresse `https` complète, une adresse sans schéma (`//autre.exemple/a.png`)
     * ou un chemin absolu (`/a.png`, sur le même nom). Un chemin relatif (`a.png`), un autre schéma ou une adresse refusée par `parse` sont refusés.
     *
     * @throws ImageException `urlRefused`
     */
    public function redirectedTo(string $location): self
    {
        $location = trim($location, " \t\n\r");

        if (str_starts_with($location, '//')) {
            return self::parse('https:' . $location);
        }

        if (str_starts_with($location, '/')) {
            return self::parse('https://' . $this->host . $location);
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location) === 1) {
            return self::parse($location);
        }

        throw ImageException::urlRefused();
    }

    private static function isPublicName(string $host): bool
    {
        if (strlen($host) > 253 || preg_match(self::HOST_PATTERN, $host) !== 1) {
            return false;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
