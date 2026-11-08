<?php

namespace Repas\Repas\Infrastructure\Network;


/**
 * Les adresses d'un nom d'hôte, telles que le système les résout (ADR 0037 décision 13, MAR-001 ; ADR 0039). Un appel sortant résout lui-même le nom,
 * vérifie que chaque adresse est publique (`PublicAddress`), puis impose au client HTTP de se connecter à cette adresse (option `resolve`) : le client
 * ne refait jamais sa propre résolution entre la vérification et la connexion.
 */
interface HostAddressResolver
{
    /**
     * Les adresses IPv4 seules : celles auxquelles on se connecte (le lecteur de page Marmiton, ADR 0037).
     *
     * @return list<string> les adresses IPv4 du nom, dans l'ordre de la réponse ; vide si le nom ne se résout pas
     */
    public function resolve(string $host): array;

    /**
     * Toutes les adresses du nom, IPv4 et IPv6 (enregistrements A et AAAA), pour juger qu'AUCUNE n'est privée : un nom qui annonce une adresse locale,
     * même en IPv6, est refusé en entier (le téléchargement d'une image par lien, ADR 0039).
     *
     * @return list<string> sans doublon, l'IPv4 d'abord ; vide si le nom ne se résout pas
     */
    public function resolveAll(string $host): array;
}
