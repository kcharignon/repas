<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Store;
use Repas\Drive\Domain\Model\StoreIdSource;

/**
 * Le profil d'Auchan dans le registre (DRV-716, décision `docs/drive/spikes/DRV-715-decision-auchan.md` § 2) : une adresse constante,
 * un identifiant de magasin lu par l'agent, des identifiants de produit et d'offre en UUID. Tous les identifiants de ce fichier sont
 * inventés.
 */
class AuchanProfileTest extends TestCase
{
    private const string UUID = '0b5f8a2e-3c1d-4e7f-9a6b-123456789abc';
    private const string OTHER_UUID = 'c3b2a1d0-5e4f-4a6b-9c8d-7e6f5a4b3c2d';

    public function testTheProfileIsTheOneOfTheDecision(): void
    {
        // When
        $profile = Retailer::AUCHAN->profile();

        // Then
        $this->assertSame('auchan', Retailer::AUCHAN->value);
        $this->assertSame('Auchan', $profile->name);
        $this->assertSame('d’Auchan', $profile->nameDe);
        $this->assertSame(StoreIdSource::EXPLICIT, $profile->storeIdSource);
        $this->assertSame('^https://www\.auchan\.fr/$', $profile->storeAddressPattern);
        $this->assertSame('https://www.auchan.fr/', $profile->storeAddressExample);
        $this->assertSame('^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', $profile->storeIdPattern);
        $this->assertSame($profile->storeIdPattern, $profile->productIdPattern);
        $this->assertSame($profile->storeIdPattern, $profile->offerIdPattern);
        $this->assertTrue($profile->hasOffers());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'l\'adresse constante' => ['https://www.auchan.fr/', true];
        yield 'http' => ['http://www.auchan.fr/', false];
        yield 'sans www' => ['https://auchan.fr/', false];
        yield 'autre sous-domaine' => ['https://drive.auchan.fr/', false];
        yield 'avec un chemin' => ['https://www.auchan.fr/magasin', false];
        yield 'sans barre finale' => ['https://www.auchan.fr', false];
        yield 'paramètre de requête' => ['https://www.auchan.fr/?a=1', false];
        yield 'identifiants dans l\'adresse' => ['https://utilisateur@www.auchan.fr/', false];
        yield 'hôte de l\'enseigne en préfixe d\'un autre' => ['https://www.auchan.fr.autre.example/', false];
        yield 'point remplacé' => ['https://www.auchanxfr/', false];
        yield 'retour à la ligne final' => ["https://www.auchan.fr/\n", false];
        yield 'hôte en majuscules' => ['https://WWW.AUCHAN.FR/', false];
        yield 'vide' => ['', false];
    }

    #[DataProvider('addresses')]
    public function testOnlyTheConstantAddressIsAStoreAddressAndNothingIsDeducedFromIt(string $baseUrl, bool $valid): void
    {
        // Then
        $this->assertSame($valid, Retailer::AUCHAN->isValidStoreUrl($baseUrl));
        $this->assertNull(Retailer::AUCHAN->storeIdOf($baseUrl));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function uuids(): iterable
    {
        yield 'un UUID' => [self::UUID, true];
        yield 'un autre UUID' => [self::OTHER_UUID, true];
        yield 'majuscules' => [strtoupper(self::UUID), false];
        yield 'sans tirets' => [str_replace('-', '', self::UUID), false];
        yield 'un tiret de trop' => [self::UUID . '-0', false];
        yield 'trop court' => [substr(self::UUID, 0, 35), false];
        yield 'des chiffres' => ['123456', false];
        yield 'vide' => ['', false];
        yield 'retour à la ligne final' => [self::UUID . "\n", false];
        yield 'espace' => [' ' . self::UUID, false];
    }

    #[DataProvider('uuids')]
    public function testTheStoreTheProductAndTheOfferAreUuids(string $id, bool $valid): void
    {
        // Then
        $this->assertSame($valid, Retailer::AUCHAN->isValidStoreId($id));
        $this->assertSame($valid, Retailer::AUCHAN->isValidProductId($id));
        $this->assertSame($valid, Retailer::AUCHAN->isValidOfferId($id));
    }

    public function testAStoreIsMadeOfTheConstantAddressAndAnUuidWhoseLengthFitsTheColumn(): void
    {
        // When
        $store = Store::create(Retailer::AUCHAN, self::UUID, ' Ville-Test ', 'https://www.auchan.fr/');

        // Then : l'identifiant de 36 caractères tient sous la borne de 40 de toute enseigne
        $this->assertLessThanOrEqual(Store::ID_MAX_LENGTH, strlen(self::UUID));
        $this->assertSame([self::UUID, 'Ville-Test', 'https://www.auchan.fr/'], [$store->id, $store->label, $store->baseUrl]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidStores(): iterable
    {
        yield 'une adresse de Leclerc' => [self::UUID, 'https://exemple.leclercdrive.fr/magasin-123456-123456'];
        yield 'un identifiant numérique' => ['123456', 'https://www.auchan.fr/'];
        yield 'un identifiant vide' => ['', 'https://www.auchan.fr/'];
        yield 'une autre adresse' => [self::UUID, 'https://www.auchan.fr/magasin'];
    }

    #[DataProvider('invalidStores')]
    public function testAStoreOfAnotherRetailerOrWithoutAnUuidIsRefused(string $id, string $baseUrl): void
    {
        // Then
        $this->expectException(DriveException::class);

        // When
        Store::create(Retailer::AUCHAN, $id, null, $baseUrl);
    }

    public function testTheAddressOfAuchanIsNotAStoreOfLeclercAndTheOtherWayRound(): void
    {
        // Then
        $this->assertFalse(Retailer::LECLERC->isValidStoreUrl('https://www.auchan.fr/'));
        $this->assertFalse(Retailer::AUCHAN->isValidStoreUrl('https://exemple.leclercdrive.fr/magasin-123456-123456'));
        $this->assertFalse(Retailer::LECLERC->isValidProductId(self::UUID));
        $this->assertFalse(Retailer::LECLERC->isValidOfferId(self::UUID));
        $this->assertFalse(Retailer::AUCHAN->isValidProductId('555101'));
    }
}
