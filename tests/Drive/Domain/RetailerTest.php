<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\StoreIdSource;

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
        yield 'retour chariot final' => ["https://exemple.leclercdrive.fr/magasin-123456-123456\r\n"];
        yield 'retour à la ligne avant' => ["\nhttps://exemple.leclercdrive.fr/magasin-123456-123456"];
        yield 'retour à la ligne dans l\'hôte' => ["https://exemple\n.leclercdrive.fr/magasin-123456-123456"];
        yield 'identifiants avant un second hôte' => ['https://exemple.leclercdrive.fr@autre.example/magasin-123456-123456'];
        yield 'barre oblique inverse comme séparateur' => ['https://autre.example\\@exemple.leclercdrive.fr/magasin-123456-123456'];
        yield 'hôte encodé en pourcentage' => ['https://exemple%2eleclercdrive.fr/magasin-123456-123456'];
        yield 'sous-domaine vide' => ['https://.leclercdrive.fr/magasin-123456-123456'];
        yield 'chiffres arabes-indiens' => ["https://exemple.leclercdrive.fr/magasin-\u{0661}\u{0662}\u{0663}-\u{0661}\u{0662}\u{0663}"];
        yield 'chiffres pleine chasse' => ["https://exemple.leclercdrive.fr/magasin-\u{FF11}\u{FF12}\u{FF13}-\u{FF11}\u{FF12}\u{FF13}"];
        yield 'lettre cyrillique dans le domaine' => ["https://ex\u{0430}mple.leclercdrive.fr/magasin-123456-123456"];
        yield 'nom de domaine internationalisé' => ["https://exempl\u{00E9}.leclercdrive.fr/magasin-123456-123456"];
        yield 'domaine de l\'enseigne avec une autre fin' => ['https://exemple.leclercdrive.fr.example/magasin-123456-123456'];
        yield 'octet nul' => ["https://exemple.leclercdrive.fr/magasin-123456-123456\0"];
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

    public function testTheListOfIdsIsTheListOfTheCases(): void
    {
        // Then : `IDS` sert aux attributs OpenAPI, qui n'acceptent qu'une constante
        $this->assertSame(array_map(static fn(Retailer $retailer): string => $retailer->value, Retailer::cases()), Retailer::IDS);
    }

    public function testTheDefaultRetailerIsLeclercForNow(): void
    {
        $this->assertSame('leclerc', Retailer::default()->value);
    }

    public function testEveryRetailerHasAProfileWhoseExampleRespectsItsOwnRule(): void
    {
        foreach (Retailer::cases() as $retailer) {
            $profile = $retailer->profile();
            $this->assertNotSame('', $profile->name);
            $this->assertStringEndsWith($profile->name, $profile->nameDe);
            $this->assertTrue($retailer->isValidStoreUrl($profile->storeAddressExample));
            foreach (array_filter([$profile->storeAddressPattern, $profile->productIdPattern, $profile->storeIdPattern, $profile->offerIdPattern]) as $pattern) {
                // Motifs du contrat : ancrés, sans `\d` ni construction propre à PCRE (le web, l'agent et l'application les compilent aussi)
                $this->assertStringStartsWith('^', $pattern);
                $this->assertStringEndsWith('$', $pattern);
                $this->assertDoesNotMatchRegularExpression('/\\\\d|\(\?/', $pattern);
            }
            $this->assertSame($profile->storeIdOf($profile->storeAddressExample), $retailer->storeIdOf($profile->storeAddressExample));
            // L'identifiant du magasin d'exemple a la forme annoncée (déduit de l'adresse : celui qu'elle donne ; explicite : aucun exemple à comparer)
            if ($profile->storeIdSource === StoreIdSource::URL) {
                $this->assertTrue($retailer->isValidStoreId((string) $retailer->storeIdOf($profile->storeAddressExample)));
            } else {
                $this->assertNull($retailer->storeIdOf($profile->storeAddressExample));
            }
        }
    }

    public function testTheProfileOfLeclercIsTheOneOfToday(): void
    {
        // When
        $profile = Retailer::LECLERC->profile();

        // Then
        $this->assertSame('Leclerc', $profile->name);
        $this->assertSame('de Leclerc', $profile->nameDe);
        $this->assertSame('^[0-9]{1,12}$', $profile->productIdPattern);
        // DRV-720 : l'identifiant du magasin se déduit de l'adresse, en 1 à 20 chiffres (ce que `Store::create` acceptait déjà)
        $this->assertSame(StoreIdSource::URL, $profile->storeIdSource);
        $this->assertSame('^[0-9]{1,20}$', $profile->storeIdPattern);
        // DRV-721 : pas d'offre chez Leclerc, un identifiant d'offre fourni est refusé
        $this->assertNull($profile->offerIdPattern);
        $this->assertFalse($profile->hasOffers());
        $this->assertFalse(Retailer::LECLERC->isValidOfferId('123456'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function storeIds(): iterable
    {
        yield 'un chiffre' => ['1', true];
        yield 'six chiffres' => ['123456', true];
        yield 'zéros initiaux' => ['000042', true];
        yield 'vingt chiffres' => [str_repeat('1', 20), true];
        yield 'vingt et un chiffres' => [str_repeat('1', 21), false];
        yield 'un identifiant de 36 caractères' => ['0b5f8a2e-3c1d-4e7f-9a6b-123456789abc', false];
        yield 'vide' => ['', false];
        yield 'lettre' => ['12a', false];
        yield 'espace' => [' 12', false];
        yield 'retour à la ligne final' => ["12\n", false];
        yield 'chiffres arabes-indiens' => ["\u{0661}\u{0662}", false];
    }

    #[DataProvider('storeIds')]
    public function testTheStoreIdMustHaveTheFormOfTheRetailer(string $id, bool $valid): void
    {
        $this->assertSame($valid, Retailer::LECLERC->isValidStoreId($id));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function productIds(): iterable
    {
        yield 'un chiffre' => ['1', true];
        yield 'douze chiffres' => ['123456789012', true];
        yield 'zéros initiaux' => ['0001', true];
        yield 'treize chiffres' => ['1234567890123', false];
        yield 'vide' => ['', false];
        yield 'lettre' => ['12a', false];
        yield 'espace' => [' 12', false];
        yield 'retour à la ligne final' => ["12\n", false];
        yield 'chiffres arabes-indiens' => ["\u{0661}\u{0662}", false];
    }

    #[DataProvider('productIds')]
    public function testTheProductIdMustHaveTheFormOfTheRetailer(string $id, bool $valid): void
    {
        $this->assertSame($valid, Retailer::LECLERC->isValidProductId($id));
    }
}
