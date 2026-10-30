<?php

namespace Repas\Tests\Drive\Domain;


use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\RetailerProfile;

/**
 * Un profil fictif, ajouté ici seulement : ses règles s'appliquent sans toucher au code de production (DRV-701). L'enseigne n'existe
 * pas, ses adresses non plus.
 */
class RetailerProfileTest extends TestCase
{
    private function fictional(): RetailerProfile
    {
        return new RetailerProfile(
            name: 'Chez Test',
            nameDe: 'de Chez Test',
            storeAddressPattern: '^https://boutique\.exemple\.invalid/magasin/([a-z]+)$',
            storeAddressExample: 'https://boutique.exemple.invalid/magasin/ville',
            productIdPattern: '^[A-Z]{2}-[0-9]{4}$',
        );
    }

    public function testAStoreAddressIsValidatedByTheRuleOfTheRetailerAndGivesItsStoreId(): void
    {
        // Given
        $profile = $this->fictional();

        // Then : le premier groupe est l'identifiant du magasin
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
        $profile = $this->fictional();

        // Then : un identifiant qui n'est pas numérique est valable chez cette enseigne, et un numérique ne l'est pas
        $this->assertTrue($profile->isValidProductId('AB-1234'));
        $this->assertFalse($profile->isValidProductId('123456'));
        $this->assertFalse($profile->isValidProductId("AB-1234\n"));
    }

    public function testAnExampleThatBreaksTheRuleIsRefused(): void
    {
        // Then
        $this->expectException(InvalidArgumentException::class);
        new RetailerProfile('Chez Test', 'de Chez Test', '^https://boutique\.exemple\.invalid/magasin/([a-z]+)$', 'https://autre.exemple.invalid/magasin/ville', '^[0-9]+$');
    }

    public function testTheContractPatternsStayInTheSubsetCommonToPcreAndJavaScript(): void
    {
        // Given : les motifs sont recopiés tels quels par le web, l'agent et l'application
        $profile = $this->fictional();

        // Then : ancrés, sans `\d` (qui n'a pas le même sens partout) ni construction propre à PCRE
        foreach ([$profile->storeAddressPattern, $profile->productIdPattern] as $pattern) {
            $this->assertStringStartsWith('^', $pattern);
            $this->assertStringEndsWith('$', $pattern);
            $this->assertDoesNotMatchRegularExpression('/\\\\d|\(\?|\\\\[AzZhHRK]/', $pattern);
        }
    }
}
