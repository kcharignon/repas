<?php

namespace Repas\Repas\Infrastructure\Network;


/**
 * La résolution du système (`gethostbynamel` : le fichier `hosts` puis le DNS configuré) pour l'IPv4 ; les enregistrements A et AAAA (`dns_get_record`)
 * pour `resolveAll`. On ne parle qu'à l'adresse qui a été vérifiée : une réponse sans IPv4 est une panne, pas un détour par l'IPv6 que personne n'a
 * vérifié.
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

    public function resolveAll(string $host): array
    {
        $addresses = $this->resolve($host);

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
