<?php

namespace Repas\Tests\Helper;


use Repas\Drive\Domain\Model\RetailerProfile;
use Repas\Drive\Domain\Model\StoreIdSource;

/**
 * Des profils d'enseigne fictifs (DRV-701, DRV-720) : leurs règles s'appliquent sans toucher au code de production. Aucune de ces
 * enseignes n'existe, aucune de ces adresses non plus (`exemple.invalid`).
 */
final class FictionalRetailerProfiles
{
    public const string UUID = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';
    /** Un identifiant de magasin de 36 caractères, de la forme de `UUID` (inventé). */
    public const string EXPLICIT_STORE_ID = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc';
    public const string EXPLICIT_STORE_ADDRESS = 'https://www.exemple.invalid/';

    /**
     * Le magasin est une adresse : son identifiant s'en déduit.
     */
    public static function storeInTheAddress(): RetailerProfile
    {
        return new RetailerProfile(
            name: 'Chez Test',
            nameDe: 'de Chez Test',
            storeAddressPattern: '^https://boutique\.exemple\.invalid/magasin/([a-z]+)$',
            storeAddressExample: 'https://boutique.exemple.invalid/magasin/ville',
            productIdPattern: '^[A-Z]{2}-[0-9]{4}$',
            storeIdSource: StoreIdSource::URL,
            storeIdPattern: '^[a-z]{1,10}$',
        );
    }

    /**
     * Le magasin n'est pas dans l'adresse (une constante d'enseigne) : l'agent lit son identifiant, un UUID de 36 caractères.
     */
    public static function explicitStore(): RetailerProfile
    {
        return new RetailerProfile(
            name: 'Chez Autre',
            nameDe: 'de Chez Autre',
            storeAddressPattern: '^https://www\.exemple\.invalid/$',
            storeAddressExample: self::EXPLICIT_STORE_ADDRESS,
            productIdPattern: self::UUID,
            storeIdSource: StoreIdSource::EXPLICIT,
            storeIdPattern: self::UUID,
            offerIdPattern: self::UUID,
        );
    }
}
