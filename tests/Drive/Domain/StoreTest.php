<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Store;

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
