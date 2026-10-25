<?php

namespace Repas\Tests\Voice\Application;

use PHPUnit\Framework\TestCase;
use Repas\Shared\Application\Dto\ListedLine;
use Repas\Shared\Application\Dto\SpokenListLookupResult;
use Repas\Tests\Helper\SpokenListLookupInMemory;
use Repas\Voice\Application\Query\GetSpokenItemInList\GetSpokenItemInListQuery;
use Repas\Voice\Application\Query\GetSpokenItemInList\GetSpokenItemInListQueryHandler;
use Repas\Voice\Application\Query\GetSpokenItemInList\SpokenItemInListDTO;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * « Est-ce qu'il y a des croquettes dans la liste ? » (ADR 0029 décision 12, VOX-304) : le handler transmet la phrase au port de lecture et en tire
 * la réponse parlée, sans rien écrire. Un double idiot du port, aucune base.
 */
class GetSpokenItemInListQueryHandlerTest extends TestCase
{
    private SpokenListLookupInMemory $lookup;
    private GetSpokenItemInListQueryHandler $handler;

    protected function setUp(): void
    {
        $this->lookup = new SpokenListLookupInMemory();
        $this->handler = new GetSpokenItemInListQueryHandler($this->lookup, new SpokenIngredientNamer());
    }

    private static function line(string $slug, string $name, string $departmentName, float $quantity, string $unitSlug = 'unite', string $unitName = 'unité'): ListedLine
    {
        return new ListedLine($slug, $name, 'rayon', $departmentName, $quantity, $unitSlug, $unitName);
    }

    public function testTheLineOfTheProductIsSaidWithItsQuantityAndUnit(): void
    {
        // Given : deux paquets de croquettes dans la liste
        $this->lookup->willFind('des croquettes', SpokenListLookupResult::found(self::line('croquette', 'croquette', 'animal', 2.0, 'paquet', 'paquet')));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'des croquettes', sessionIsOpen: false));

        // Then : appelé avec l'utilisateur du jeton et la phrase
        $this->assertSame([['user-1', 'des croquettes']], $this->lookup->asked);
        $this->assertEquals(new SpokenItemInListDTO('Oui, tu en as 2 paquets.', keepSessionOpen: false), $dto);
    }

    public function testACountIsSaidWithoutAUnit(): void
    {
        // Given
        $this->lookup->willFind('du pain', SpokenListLookupResult::found(self::line('pain', 'pain', 'boulangerie', 3.0)));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame('Oui, tu en as 3.', $dto->speech);
    }

    public function testADecimalQuantityIsSaidWithAComma(): void
    {
        // Given
        $this->lookup->willFind('du lait', SpokenListLookupResult::found(self::line('lait', 'lait', 'crèmerie', 1.5, 'litre', 'litre')));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'du lait', sessionIsOpen: false));

        // Then
        $this->assertSame('Oui, tu en as 1,5 litre.', $dto->speech);
    }

    public function testAProductNotInTheListIsAnsweredNoWithItsName(): void
    {
        // Given
        $this->lookup->willFind('des croquettes', SpokenListLookupResult::notInList('croquette'));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'des croquettes', sessionIsOpen: false));

        // Then
        $this->assertSame("Non, il n'y a pas de croquette dans ta liste.", $dto->speech);
    }

    public function testAnUnknownProductIsAnsweredNoWithTheHeardName(): void
    {
        // Given : rien ne ressemble à la phrase
        $this->lookup->willFind('de la ciboulette', SpokenListLookupResult::notInList(null));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'de la ciboulette', sessionIsOpen: false));

        // Then : jamais de proposition ni de création
        $this->assertSame("Non, il n'y a pas de ciboulette dans ta liste.", $dto->speech);
    }

    public function testWithoutAnActiveListItSaysSoAndNeverCreatesOne(): void
    {
        // Given : le double répond « pas de liste » par défaut
        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame("Tu n'as pas de liste en cours.", $dto->speech);
    }

    public function testSeveralLinesThatTieAreAnsweredTogetherOnePerDepartment(): void
    {
        // Given : « tomate » en fruit et légume (2) ET en surgelé (1), toutes deux dans la liste
        $this->lookup->willFind('des tomates', SpokenListLookupResult::tied([
            self::line('tomate', 'tomate', 'fruit et légume', 2.0),
            self::line('tomate-surgelee', 'tomate', 'surgelé', 1.0),
        ]));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'des tomates', sessionIsOpen: false));

        // Then : une seule phrase, pas de question
        $this->assertSame('Oui : 2 tomates en fruit et légume, 1 en surgelé.', $dto->speech);
    }

    public function testTiedLinesWithAUnitSayTheUnitAndTheProductOnce(): void
    {
        // Given
        $this->lookup->willFind('du lait', SpokenListLookupResult::tied([
            self::line('lait', 'lait', 'crèmerie', 2.0, 'litre', 'litre'),
            self::line('lait-infantile', 'lait', 'bébé', 1.0, 'boite', 'boîte'),
        ]));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'du lait', sessionIsOpen: false));

        // Then
        $this->assertSame('Oui : 2 litres de lait en crèmerie, 1 boîte en bébé.', $dto->speech);
    }

    public function testANumberAndAUnitLeftInTheItemSlotDoNotChangeWhatIsLookedFor(): void
    {
        // Given : « deux paquets de croquettes » : on cherche « croquettes », la réponse dit combien il y en a
        $this->lookup->willFind('croquettes', SpokenListLookupResult::found(self::line('croquette', 'croquette', 'animal', 5.0, 'paquet', 'paquet')));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'deux paquets de croquettes', sessionIsOpen: false));

        // Then
        $this->assertSame([['user-1', 'croquettes']], $this->lookup->asked);
        $this->assertSame('Oui, tu en as 5 paquets.', $dto->speech);
    }

    public function testTheSessionStaysOpenWhenItWasOpen(): void
    {
        // When
        $open = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: true));
        $direct = ($this->handler)(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertTrue($open->keepSessionOpen);
        $this->assertFalse($direct->keepSessionOpen);
    }

    public function testAPhraseWithoutLettersIsNotUnderstoodWhenNothingIsKnown(): void
    {
        // Given
        $this->lookup->willFind('12', SpokenListLookupResult::notInList(null));

        // When
        $dto = ($this->handler)(new GetSpokenItemInListQuery('user-1', '12', sessionIsOpen: false));

        // Then
        $this->assertSame("Je n'ai pas compris.", $dto->speech);
    }
}
