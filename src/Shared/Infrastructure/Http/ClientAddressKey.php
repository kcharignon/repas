<?php

namespace Repas\Shared\Infrastructure\Http;


/**
 * La clé d'un plafond de débit « par adresse », commune à tous les limiteurs du back (`Voice`, `User`, `Drive` ; ADR 0029 décision 7) :
 * ce qu'il faut compter pour qu'un même abonné ait un seul compteur, quelle que soit l'adresse qu'il choisit dans son préfixe.
 * Pure : une chaîne en entrée, une chaîne en sortie, aucun service.
 *
 * - une adresse IPv4 est inchangée ;
 * - une adresse IPv4 mappée en IPv6 (`::ffff:203.0.113.5`, ou `::ffff:cb00:7105`) se traite comme l'adresse IPv4 qu'elle porte ;
 * - une adresse IPv6 est ramenée à son préfixe /64 (`2001:db8:1::/64`) : un abonné reçoit au moins un /64, il y choisit autant d'adresses
 *   qu'il veut, et sans cela chacune aurait son propre compteur ;
 * - une adresse absente ou invalide donne `unknown`, une clé stable et sûre (jamais la chaîne reçue : elle ne se retrouve ni dans le cache ni
 *   dans un journal).
 */
final readonly class ClientAddressKey
{
    public const string UNKNOWN = 'unknown';

    /** La longueur du préfixe IPv6 compté, en bits puis en octets. */
    private const int IPV6_PREFIX_BITS = 64;
    private const int IPV6_PREFIX_BYTES = self::IPV6_PREFIX_BITS / 8;

    private function __construct()
    {
    }

    public static function of(?string $address): string
    {
        if ($address === null || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return self::UNKNOWN;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return self::UNKNOWN;
        }

        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }

        // ::ffff:0:0/96 : l'adresse IPv4 mappée est dans les quatre derniers octets
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        $prefix = substr($packed, 0, self::IPV6_PREFIX_BYTES) . str_repeat("\0", 16 - self::IPV6_PREFIX_BYTES);

        return inet_ntop($prefix) . '/' . self::IPV6_PREFIX_BITS;
    }
}
