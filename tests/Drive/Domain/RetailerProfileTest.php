<?php

namespace Repas\Tests\Drive\Domain;


use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\RetailerProfile;
use Repas\Drive\Domain\Model\StoreIdSource;
use Repas\Tests\Helper\FictionalRetailerProfiles;

/**
 * Des profils fictifs (`FictionalRetailerProfiles`), ajoutés ici seulement : leurs règles s'appliquent sans toucher au code de
 * production (DRV-701, DRV-720). Les enseignes n'existent pas, leurs adresses non plus.
 */
class RetailerProfileTest extends TestCase
{
    public function testAStoreAddressIsValidatedByTheRuleOfTheRetailerAndGivesItsStoreId(): void
    {
        // Given
        $profile = FictionalRetailerProfiles::storeInTheAddress();

        // Then : le premier groupe est l'identifiant du magasin
        $this->assertSame(StoreIdSource::URL, $profile->storeIdSource);
        $this->assertTrue($profile->isValidStoreUrl('https://boutique.exemple.invalid/magasin/ville'));
        $this->assertSame('ville', $profile->storeIdOf('https://boutique.exemple.invalid/magasin/ville'));
        $this->assertFalse($profile->isValidStoreUrl('https://boutique.exemple.invalid/magasin/ville/'));
        $this->assertFalse($profile->isValidStoreUrl("https://boutique.exemple.invalid/magasin/ville\n"));
        $this->assertFalse($profile->isValidStoreUrl('https://exemple.leclercdrive.fr/magasin-123456-123456'));
        $this->assertNull($profile->storeIdOf('https://autre.exemple.invalid/magasin/ville'));
    }

    public function testAProductIdIsValidatedByTheRuleOfTheRetailer(): void
    {
        // Given
        $profile = FictionalRetailerProfiles::storeInTheAddress();

        // Then : un identifiant qui n'est pas numérique est valable chez cette enseigne, et un numérique ne l'est pas
        $this->assertTrue($profile->isValidProductId('AB-1234'));
        $this->assertFalse($profile->isValidProductId('123456'));
        $this->assertFalse($profile->isValidProductId("AB-1234\n"));
    }

    public function testAStoreIdIsValidatedByTheRuleOfTheRetailer(): void
    {
        // Given
        $profile = FictionalRetailerProfiles::storeInTheAddress();

        // Then : la forme seulement (que l'identifiant soit celui de l'adresse, c'est `Store::create` qui le juge)
        $this->assertTrue($profile->isValidStoreId('ville'));
        $this->assertTrue($profile->isValidStoreId('abcdefghij'));
        $this->assertFalse($profile->isValidStoreId('abcdefghijk'));
        $this->assertFalse($profile->isValidStoreId(''));
        $this->assertFalse($profile->isValidStoreId('Ville'));
        $this->assertFalse($profile->isValidStoreId("ville\n"));
    }

    public function testAnOfferIdIsValidatedByTheRuleOfTheRetailerAndRefusedWhenItHasNoOffer(): void
    {
        // Given : une enseigne à offres, une sans
        $withOffers = FictionalRetailerProfiles::explicitStore();
        $withoutOffers = FictionalRetailerProfiles::storeInTheAddress();

        // Then : la forme d'un UUID, ancrée
        $this->assertTrue($withOffers->hasOffers());
        $this->assertTrue($withOffers->isValidOfferId(FictionalRetailerProfiles::EXPLICIT_STORE_ID));
        $this->assertFalse($withOffers->isValidOfferId('pas-un-uuid'));
        $this->assertFalse($withOffers->isValidOfferId(FictionalRetailerProfiles::EXPLICIT_STORE_ID . "\n"));
        $this->assertFalse($withOffers->isValidOfferId(''));
        // Une enseigne sans offre n'en accepte aucune, quelle que soit sa forme
        $this->assertNull($withoutOffers->offerIdPattern);
        $this->assertFalse($withoutOffers->hasOffers());
        $this->assertFalse($withoutOffers->isValidOfferId(FictionalRetailerProfiles::EXPLICIT_STORE_ID));
        $this->assertFalse($withoutOffers->isValidOfferId('1'));
    }

    public function testAnInvalidOfferIdPatternIsRefused(): void
    {
        // Then
        $this->expectException(InvalidArgumentException::class);
        new RetailerProfile('Chez Autre', 'de Chez Autre', '^https://www\.exemple\.invalid/$', 'https://www.exemple.invalid/', '^[0-9]+$', StoreIdSource::EXPLICIT, '^[0-9]+$', '^[0-9');
    }

    public function testAnExplicitStoreIsNotDeducedFromTheAddress(): void
    {
        // Given
        $profile = FictionalRetailerProfiles::explicitStore();

        // Then : l'adresse est une constante d'enseigne, rien ne s'en déduit ; seule sa forme est contrôlée
        $this->assertSame(StoreIdSource::EXPLICIT, $profile->storeIdSource);
        $this->assertTrue($profile->isValidStoreUrl('https://www.exemple.invalid/'));
        $this->assertNull($profile->storeIdOf('https://www.exemple.invalid/'));
        $this->assertFalse($profile->isValidStoreUrl('https://www.exemple.invalid/magasin/ville'));
        $this->assertFalse($profile->isValidStoreUrl('https://www.exemple.invalid'));
        $this->assertFalse($profile->isValidStoreUrl("https://www.exemple.invalid/\n"));
        $this->assertFalse($profile->isValidStoreUrl('https://boutique.exemple.invalid/'));
    }

    public function testAnExplicitStoreIdIsValidatedByItsPattern(): void
    {
        // Given
        $profile = FictionalRetailerProfiles::explicitStore();

        // Then : un identifiant de 36 caractères (un UUID) est valable chez cette enseigne
        $this->assertSame(36, strlen(FictionalRetailerProfiles::EXPLICIT_STORE_ID));
        $this->assertTrue($profile->isValidStoreId(FictionalRetailerProfiles::EXPLICIT_STORE_ID));
        $this->assertFalse($profile->isValidStoreId('0B5F8A2E-3C1D-4E7F-9A6B-123456789ABC'));
        $this->assertFalse($profile->isValidStoreId('0b5f8a2e-3c1d-4e7f-9a6b-123456789ab'));
        $this->assertFalse($profile->isValidStoreId('123456'));
        $this->assertFalse($profile->isValidStoreId(''));
        $this->assertFalse($profile->isValidStoreId(FictionalRetailerProfiles::EXPLICIT_STORE_ID . "\n"));
    }

    public function testAnExampleThatBreaksTheRuleIsRefused(): void
    {
        // Then
        $this->expectException(InvalidArgumentException::class);
        new RetailerProfile('Chez Test', 'de Chez Test', '^https://boutique\.exemple\.invalid/magasin/([a-z]+)$', 'https://autre.exemple.invalid/magasin/ville', '^[0-9]+$', StoreIdSource::URL, '^[a-z]+$');
    }

    public function testAnIdThatTheExampleGivesButThePatternRefusesIsRefused(): void
    {
        // Then : le contrat dirait aux autres couches que les identifiants sont des chiffres alors que l'adresse donne des lettres
        $this->expectException(InvalidArgumentException::class);
        new RetailerProfile('Chez Test', 'de Chez Test', '^https://boutique\.exemple\.invalid/magasin/([a-z]+)$', 'https://boutique.exemple.invalid/magasin/ville', '^[0-9]+$', StoreIdSource::URL, '^[0-9]+$');
    }

    public function testAnAddressRuleWithoutAGroupIsRefusedWhenTheIdIsDeducedFromTheAddress(): void
    {
        // Then : source `URL` sans groupe capturant : aucune adresse ne donnerait d'identifiant
        $this->expectException(InvalidArgumentException::class);
        new RetailerProfile('Chez Test', 'de Chez Test', '^https://boutique\.exemple\.invalid/$', 'https://boutique.exemple.invalid/', '^[0-9]+$', StoreIdSource::URL, '^[a-z]+$');
    }

    public function testAnInvalidStoreIdPatternIsRefused(): void
    {
        // Then
        $this->expectException(InvalidArgumentException::class);
        new RetailerProfile('Chez Autre', 'de Chez Autre', '^https://www\.exemple\.invalid/$', 'https://www.exemple.invalid/', '^[0-9]+$', StoreIdSource::EXPLICIT, '^[0-9$');
    }

    /**
     * @return iterable<string, array{RetailerProfile}>
     */
    public static function profiles(): iterable
    {
        yield 'identifiant déduit de l\'adresse' => [FictionalRetailerProfiles::storeInTheAddress()];
        yield 'identifiant explicite' => [FictionalRetailerProfiles::explicitStore()];
    }

    #[DataProvider('profiles')]
    public function testTheContractPatternsStayInTheSubsetCommonToPcreAndJavaScript(RetailerProfile $profile): void
    {
        // Given : les motifs sont recopiés tels quels par le web, l'agent et l'application

        // Then : ancrés, sans `\d` (qui n'a pas le même sens partout) ni construction propre à PCRE
        foreach (array_filter([$profile->storeAddressPattern, $profile->productIdPattern, $profile->storeIdPattern, $profile->offerIdPattern]) as $pattern) {
            $this->assertStringStartsWith('^', $pattern);
            $this->assertStringEndsWith('$', $pattern);
            $this->assertDoesNotMatchRegularExpression('/\\\\d|\(\?|\\\\[AzZhHRK]/', $pattern);
        }
    }

    public function testTheSourcesOfTheStoreIdAreNamedAsTheContractWritesThem(): void
    {
        // Then : le contrat `retailers.sample.json` écrit ces deux valeurs, le web, l'agent et l'application les lisent telles quelles
        $this->assertSame(['URL', 'EXPLICIT'], array_map(static fn(StoreIdSource $source): string => $source->value, StoreIdSource::cases()));
    }
}
