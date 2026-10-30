<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\RetailerProfile;
use Repas\Drive\Domain\Model\Store;
use Repas\Drive\Domain\Model\StoreIdSource;
use Repas\Tests\Helper\FictionalRetailerProfiles;

class StoreTest extends TestCase
{
    private const string URL = 'https://exemple.leclercdrive.fr/magasin-123456-123456';

    public function testAValidStoreKeepsItsValues(): void
    {
        // When
        $store = Store::create(Retailer::LECLERC, '123456', 'Ville-Test', self::URL);

        // Then
        $this->assertSame(['123456', 'Ville-Test', self::URL], [$store->id, $store->label, $store->baseUrl]);
    }

    public function testTheLabelIsOptionalTrimmedAndAnEmptyOneBecomesNull(): void
    {
        // Then
        $this->assertNull(Store::create(Retailer::LECLERC, '123456', null, self::URL)->label);
        $this->assertNull(Store::create(Retailer::LECLERC, '123456', '   ', self::URL)->label);
        $this->assertSame('Ville-Test', Store::create(Retailer::LECLERC, '123456', '  Ville-Test ', self::URL)->label);
    }

    public function testTheLabelMayHave255CharactersNotMore(): void
    {
        // Then : 255 caractères accentués (510 octets) passent, 256 non
        $this->assertSame(255, mb_strlen(Store::create(Retailer::LECLERC, '123456', str_repeat('é', 255), self::URL)->label));

        $this->expectExceptionObject(DriveException::storeInvalid());
        Store::create(Retailer::LECLERC, '123456', str_repeat('é', 256), self::URL);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidStores(): iterable
    {
        yield 'identifiant différent de celui de l\'adresse' => ['654321', self::URL];
        yield 'identifiant vide' => ['', self::URL];
        yield 'identifiant sans ses zéros initiaux' => ['42', 'https://exemple.leclercdrive.fr/magasin-000042-000042'];
        yield 'adresse d\'un autre hôte' => ['123456', 'https://exemple.example.com/magasin-123456-123456'];
        yield 'adresse sans https' => ['123456', 'http://exemple.leclercdrive.fr/magasin-123456-123456'];
        yield 'identifiant de plus de 20 chiffres' => [str_repeat('1', 21), 'https://exemple.leclercdrive.fr/magasin-' . str_repeat('1', 21) . '-1'];
        yield 'adresse de plus de 255 caractères' => ['1', 'https://' . str_repeat('a', 240) . '.leclercdrive.fr/magasin-1-1'];
    }

    #[DataProvider('invalidStores')]
    public function testAStoreThatIsNotOfTheRetailerIsRefused(string $id, string $baseUrl): void
    {
        // Then
        $this->expectExceptionObject(DriveException::storeInvalid());

        // When
        Store::create(Retailer::LECLERC, $id, 'Ville-Test', $baseUrl);
    }

    public function testAStoreOfLeclercKeepsTheLimitOf20DigitsEvenThoughTheColumnsAreWider(): void
    {
        // Then : DRV-720 élargit les colonnes à 40, mais le motif d'identifiant de cette enseigne reste « 1 à 20 chiffres »
        $id = str_repeat('1', 20);
        $this->assertSame($id, Store::create(Retailer::LECLERC, $id, null, 'https://exemple.leclercdrive.fr/magasin-' . $id . '-1')->id);
    }

    public function testAStoreWhoseIdIsReadByTheAgentIsCreatedWithAnIdOf36CharactersAndAConstantAddress(): void
    {
        // When : une enseigne fictive à identifiant explicite (un UUID), adresse constante
        $store = Store::createFor(FictionalRetailerProfiles::explicitStore(), FictionalRetailerProfiles::EXPLICIT_STORE_ID, '  Ville-Test ', FictionalRetailerProfiles::EXPLICIT_STORE_ADDRESS);

        // Then : l'identifiant n'est pas déduit de l'adresse ; le libellé est rangé comme avant
        $this->assertSame([FictionalRetailerProfiles::EXPLICIT_STORE_ID, 'Ville-Test', FictionalRetailerProfiles::EXPLICIT_STORE_ADDRESS], [$store->id, $store->label, $store->baseUrl]);
        $this->assertSame(36, strlen($store->id));
    }

    public function testAnExplicitIdIsNeverComparedWithTheAddressEvenIfTheAddressRuleHasAGroup(): void
    {
        // Given : une règle d'adresse qui capture un groupe (« courses »), mais un identifiant explicite : le groupe n'est pas l'identifiant
        $profile = new RetailerProfile('Chez Autre', 'de Chez Autre', '^https://www\.exemple\.invalid/(courses)?$', 'https://www.exemple.invalid/', '^[0-9]+$', StoreIdSource::EXPLICIT, '^[0-9]{1,5}$');

        // Then
        $this->assertSame('42', Store::createFor($profile, '42', null, 'https://www.exemple.invalid/courses')->id);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidExplicitStores(): iterable
    {
        $address = FictionalRetailerProfiles::EXPLICIT_STORE_ADDRESS;
        yield 'identifiant hors motif' => ['123456', $address];
        yield 'identifiant en majuscules' => [strtoupper(FictionalRetailerProfiles::EXPLICIT_STORE_ID), $address];
        yield 'identifiant de 35 caractères' => [substr(FictionalRetailerProfiles::EXPLICIT_STORE_ID, 0, 35), $address];
        yield 'identifiant de 37 caractères' => [FictionalRetailerProfiles::EXPLICIT_STORE_ID . 'a', $address];
        yield 'identifiant vide' => ['', $address];
        yield 'identifiant avec retour à la ligne final' => [FictionalRetailerProfiles::EXPLICIT_STORE_ID . "\n", $address];
        yield 'adresse d\'une autre enseigne' => [FictionalRetailerProfiles::EXPLICIT_STORE_ID, 'https://exemple.leclercdrive.fr/magasin-123456-123456'];
        yield 'adresse sans https' => [FictionalRetailerProfiles::EXPLICIT_STORE_ID, 'http://www.exemple.invalid/'];
        yield 'adresse avec un chemin' => [FictionalRetailerProfiles::EXPLICIT_STORE_ID, 'https://www.exemple.invalid/courses'];
        yield 'adresse vide' => [FictionalRetailerProfiles::EXPLICIT_STORE_ID, ''];
    }

    #[DataProvider('invalidExplicitStores')]
    public function testAnExplicitStoreThatBreaksTheRulesOfTheRetailerIsRefusedWithTheSameError(string $id, string $baseUrl): void
    {
        // Then
        $this->expectExceptionObject(DriveException::storeInvalid());

        // When
        Store::createFor(FictionalRetailerProfiles::explicitStore(), $id, 'Ville-Test', $baseUrl);
    }

    public function testAnIdLongerThan40CharactersIsRefusedWhateverTheRetailerPatternAllows(): void
    {
        // Given : un motif d'enseigne qui laisserait passer 41 caractères
        $profile = new RetailerProfile('Chez Autre', 'de Chez Autre', '^https://www\.exemple\.invalid/$', 'https://www.exemple.invalid/', '^[0-9]+$', StoreIdSource::EXPLICIT, '^[a-z0-9-]{1,64}$');

        // Then : 40 passent, 41 non (la colonne ne contient pas plus)
        $this->assertSame(40, strlen(Store::createFor($profile, str_repeat('a', 40), null, 'https://www.exemple.invalid/')->id));
        $this->expectExceptionObject(DriveException::storeInvalid());
        Store::createFor($profile, str_repeat('a', 41), null, 'https://www.exemple.invalid/');
    }

    public function testTheLabelAndTheAddressOfAnExplicitStoreKeepTheirLimits(): void
    {
        // Given
        $profile = FictionalRetailerProfiles::explicitStore();

        // Then : 255 caractères accentués passent, 256 non
        $this->assertSame(255, mb_strlen(Store::createFor($profile, FictionalRetailerProfiles::EXPLICIT_STORE_ID, str_repeat('é', 255), FictionalRetailerProfiles::EXPLICIT_STORE_ADDRESS)->label));
        $this->expectExceptionObject(DriveException::storeInvalid());
        Store::createFor($profile, FictionalRetailerProfiles::EXPLICIT_STORE_ID, str_repeat('é', 256), FictionalRetailerProfiles::EXPLICIT_STORE_ADDRESS);
    }

    public function testAnIdDeducedFromTheAddressMustStillBeTheOneOfTheAddress(): void
    {
        // Given : une enseigne fictive dont l'identifiant se déduit de l'adresse
        $profile = FictionalRetailerProfiles::storeInTheAddress();

        // Then : le même magasin, deux identifiants : seul celui de l'adresse est accepté
        $this->assertSame('ville', Store::createFor($profile, 'ville', null, 'https://boutique.exemple.invalid/magasin/ville')->id);
        $this->expectExceptionObject(DriveException::storeInvalid());
        Store::createFor($profile, 'autre', null, 'https://boutique.exemple.invalid/magasin/ville');
    }

    public function testLoadingFromPersistenceChecksNothing(): void
    {
        // When
        $store = Store::load(['id' => 'x', 'label' => null, 'base_url' => 'n\'importe quoi']);

        // Then
        $this->assertSame(['x', null, 'n\'importe quoi'], [$store->id, $store->label, $store->baseUrl]);
    }

    public function testTheDomainErrorsCarryTheirHttpStatusAndCode(): void
    {
        $this->assertSame([422, 'DRIVE_STORE_INVALID'], [DriveException::storeInvalid()->getCode(), DriveException::storeInvalid()->getMessage()]);
        $this->assertSame([409, 'DRIVE_NOT_CONFIGURED'], [DriveException::notConfigured()->getCode(), DriveException::notConfigured()->getMessage()]);
        $this->assertSame([404, 'DRIVE_DISABLED'], [DriveException::disabled()->getCode(), DriveException::disabled()->getMessage()]);
    }
}
