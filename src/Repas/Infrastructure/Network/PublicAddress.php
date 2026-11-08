<?php

namespace Repas\Repas\Infrastructure\Network;


/**
 * Une adresse IP routable sur l'Internet public, ou non. Partagée par les deux appels sortants de l'application, qui ne parlent jamais qu'à une
 * adresse publique : le lecteur de page Marmiton (ADR 0037) et le téléchargement d'une image par lien (ADR 0039).
 *
 * - **IPv4** : ni privée, ni loopback, ni lien local (dont les métadonnées des nuages, `169.254.169.254`), ni partagée (`100.64/10`), ni réservée, ni de
 *   documentation (`FILTER_FLAG_GLOBAL_RANGE`), ni multicast (`224/4`, que ce filtre laisse passer).
 * - **IPv6** : le même filtre, et en plus ni multicast (`ff00::/8`), ni site-local (`fec0::/10`) que le filtre laisse passer, et une adresse NAT64
 *   (`64:ff9b::/96`) n'est publique que si l'IPv4 qu'elle porte l'est. Une adresse IPv4 écrite en IPv6 (`::ffff:127.0.0.1`) est refusée.
 *
 * Au moindre doute, l'adresse est refusée : une adresse qui n'est pas du tout une adresse IP aussi.
 */
final class PublicAddress
{
    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isPublicIpv4($address);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::isPublicIpv6($address);
        }

        return false;
    }

    private static function isPublicIpv4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $long = ip2long($address);

        return $long !== false && ($long & 0xF0000000) !== 0xE0000000;
    }

    private static function isPublicIpv6(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $bytes = inet_pton($address);
        if ($bytes === false || strlen($bytes) !== 16) {
            return false;
        }

        $first = ord($bytes[0]);
        $second = ord($bytes[1]);
        if ($first === 0xFF) { // multicast
            return false;
        }
        if ($first === 0xFE && ($second & 0xC0) === 0xC0) { // site-local, fec0::/10
            return false;
        }

        // NAT64 (64:ff9b::/96) : l'adresse IPv4 portée par les quatre derniers octets décide
        if (str_starts_with($bytes, "\x00\x64\xFF\x9B" . str_repeat("\x00", 8))) {
            $embedded = inet_ntop(substr($bytes, 12));

            return $embedded !== false && self::isPublicIpv4($embedded);
        }

        return true;
    }
}
