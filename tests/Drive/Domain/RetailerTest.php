<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\Retailer;

class RetailerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validStoreUrls(): iterable
    {
        yield 'identifiant simple' => ['https://exemple.leclercdrive.fr/magasin-123456-123456', '123456'];
        yield 'zéros initiaux conservés' => ['https://exemple.leclercdrive.fr/magasin-000042-000042', '000042'];
        yield 'le premier groupe de chiffres est l\'identifiant' => ['https://exemple.leclercdrive.fr/magasin-123456-654321', '123456'];
        yield 'sous-domaine avec tiret et chiffres' => ['https://test-courses9.leclercdrive.fr/magasin-7-8', '7'];
    }

    #[DataProvider('validStoreUrls')]
    public function testAStoreUrlOfTheRetailerIsValidAndGivesItsStoreId(string $baseUrl, string $storeId): void
    {
        // Then
        $this->assertTrue(Retailer::LECLERC->isValidStoreUrl($baseUrl));
        $this->assertSame($storeId, Retailer::LECLERC->storeIdOf($baseUrl));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStoreUrls(): iterable
    {
        yield 'vide' => [''];
        yield 'http' => ['http://exemple.leclercdrive.fr/magasin-123456-123456'];
        yield 'autre hôte' => ['https://exemple.example.com/magasin-123456-123456'];
        yield 'hôte de l\'enseigne en préfixe d\'un autre' => ['https://exemple.leclercdrive.fr.autre.example/magasin-123456-123456'];
        yield 'hôte de l\'enseigne dans le chemin' => ['https://autre.example/.leclercdrive.fr/magasin-123456-123456'];
        yield 'domaine nu, sans sous-domaine' => ['https://leclercdrive.fr/magasin-123456-123456'];
        yield 'deux niveaux de sous-domaine' => ['https://a.b.leclercdrive.fr/magasin-123456-123456'];
        yield 'identifiants dans l\'adresse' => ['https://utilisateur@exemple.leclercdrive.fr/magasin-123456-123456'];
        yield 'port' => ['https://exemple.leclercdrive.fr:8443/magasin-123456-123456'];
        yield 'hôte en majuscules' => ['https://EXEMPLE.leclercdrive.fr/magasin-123456-123456'];
        yield 'identifiant avec des lettres' => ['https://exemple.leclercdrive.fr/magasin-12a456-123456'];
        yield 'second identifiant absent' => ['https://exemple.leclercdrive.fr/magasin-123456'];
        yield 'autre page du site' => ['https://exemple.leclercdrive.fr/recherche.aspx'];
        yield 'barre finale' => ['https://exemple.leclercdrive.fr/magasin-123456-123456/'];
        yield 'retour à la ligne final' => ["https://exemple.leclercdrive.fr/magasin-123456-123456\n"];
        yield 'paramètre de requête' => ['https://exemple.leclercdrive.fr/magasin-123456-123456?a=1'];
        yield 'fragment' => ['https://exemple.leclercdrive.fr/magasin-123456-123456#repas=x'];
        yield 'suite de chemin' => ['https://exemple.leclercdrive.fr/magasin-123456-123456/../autre'];
        yield 'espace' => [' https://exemple.leclercdrive.fr/magasin-123456-123456'];
    }

    #[DataProvider('invalidStoreUrls')]
    public function testAnythingElseThanAStoreUrlOfTheRetailerIsRefused(string $baseUrl): void
    {
        // Then
        $this->assertFalse(Retailer::LECLERC->isValidStoreUrl($baseUrl));
        $this->assertNull(Retailer::LECLERC->storeIdOf($baseUrl));
    }

    public function testTheValueOfTheRetailerIsItsApiName(): void
    {
        $this->assertSame('leclerc', Retailer::LECLERC->value);
        $this->assertSame(Retailer::LECLERC, Retailer::from('leclerc'));
    }
}
