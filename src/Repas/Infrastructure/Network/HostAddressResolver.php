<?php

namespace Repas\Repas\Infrastructure\Marmiton;


/**
 * Les adresses IPv4 d'un nom d'hôte, telles que le système les résout (ADR 0037 décision 13, MAR-001). Le lecteur de page Marmiton résout
 * lui-même `www.marmiton.org`, vérifie que chaque adresse est publique, puis impose au client HTTP de se connecter à cette adresse
 * (option `resolve`) : le client ne refait jamais sa propre résolution entre la vérification et la connexion.
 */
interface HostAddressResolver
{
    /**
     * @return list<string> les adresses IPv4 du nom, dans l'ordre de la réponse ; vide si le nom ne se résout pas
     */
    public function resolve(string $host): array;
}
