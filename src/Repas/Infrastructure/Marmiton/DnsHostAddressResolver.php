<?php

namespace Repas\Repas\Infrastructure\Marmiton;


/**
 * La résolution du système (`gethostbynamel` : le fichier `hosts` puis le DNS configuré), limitée à l'IPv4. On ne parle qu'à l'adresse
 * qui a été vérifiée : une réponse sans IPv4 est une panne, pas un détour par l'IPv6 que personne n'a vérifié.
 */
final readonly class DnsHostAddressResolver implements HostAddressResolver
{
    public function resolve(string $host): array
    {
        $addresses = gethostbynamel($host);
        if ($addresses === false) {
            return [];
        }

        return array_values(array_filter($addresses, static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
    }
}
