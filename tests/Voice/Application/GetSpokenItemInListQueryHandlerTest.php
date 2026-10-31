<?php

namespace Repas\Tests\Voice\Application;

use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Voice\Application\Query\GetSpokenItemInList\GetSpokenItemInListQuery;
use Repas\Voice\Application\Query\GetSpokenItemInList\GetSpokenItemInListQueryHandler;
use Repas\Voice\Application\Query\GetSpokenItemInList\SpokenItemInListDTO;
use Repas\Voice\Domain\Model\ListedLine;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;
use Repas\Voice\Domain\Service\SpokenListAnswerer;

/**
 * « Est-ce qu'il y a des croquettes dans la liste ? » (ADR 0029 décision 12, VOX-304) : le handler transmet la phrase au port de lecture et en tire
 * la réponse parlée, sans rien écrire. Le vrai adapter de lecture et le vrai Open Host Service de `Repas` sur ses Loaders en mémoire (`RepasForVoice`) :
 * aucune base.
 */
class GetSpokenItemInListQueryHandlerTest extends TestCase
{
    private RepasForVoice $repas;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
    }

    /** Le handler lit ce que $this->repas porte au moment de l'appel. */
    private function handler(): GetSpokenItemInListQueryHandler
    {
        return new GetSpokenItemInListQueryHandler($this->repas->lineLookupQueryPort(), new SpokenListAnswerer(new SpokenIngredientNamer()));
    }

    private static function line(string $slug, string $name, string $departmentName, float $quantity, string $unitSlug = 'unite', string $unitName = 'unité'): ListedLine
    {
        return new ListedLine($slug, $name, StringTool::slugify($departmentName), $departmentName, $quantity, $unitSlug, $unitName);
    }

    public function testTheLineOfTheProductIsSaidWithItsQuantityAndUnit(): void
    {
        // Given : deux paquets de croquettes dans la liste
        $this->repas->listHas(self::line('croquette', 'croquette', 'animal', 2.0, 'paquet', 'paquet'));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'des croquettes', sessionIsOpen: false));

        // Then : appelé avec l'utilisateur du jeton et la phrase
        $this->assertSame([['user-1', 'des croquettes']], $this->repas->lookedUp);
        $this->assertEquals(new SpokenItemInListDTO('Oui, tu en as 2 paquets.', keepSessionOpen: false), $dto);
    }

    public function testAProductInTheListOfATypeNamesIt(): void
    {
        // Given : deux paquets de viande hachée, dans Boucherie seulement
        $this->repas->listHas(new ListedLine('viande-hachee', 'viande hachée', 'viande', 'viande', 2.0, 'paquet', 'paquet', 'Boucherie'));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'de la viande hachée', sessionIsOpen: false));

        // Then
        $this->assertSame('Oui, tu en as 2 paquets dans la liste boucherie.', $dto->speech);
    }

    public function testAProductInSeveralListsIsSaidWithItsTotalPerList(): void
    {
        // Given : un paquet dans la liste principale, deux dans Boucherie
        $this->repas->listHas(new ListedLine('viande-hachee', 'viande hachée', 'viande', 'viande', 1.0, 'paquet', 'paquet'));
        $this->repas->listHas(new ListedLine('viande-hachee', 'viande hachée', 'viande', 'viande', 2.0, 'paquet', 'paquet', 'Boucherie'));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'de la viande hachée', sessionIsOpen: false));

        // Then
        $this->assertSame('Oui : 1 paquet dans ta liste, 2 paquets dans la liste boucherie.', $dto->speech);
    }

    public function testProductsThatTieAreEnumeratedWithOneTotalEachWhateverTheirLists(): void
    {
        // Given : « tomate » en fruit et légume (1 dans la liste principale, 2 dans Marché) et en surgelé (1)
        $this->repas->listHas(self::line('tomate', 'tomate', 'fruit et légume', 1.0));
        $this->repas->listHas(new ListedLine('tomate', 'tomate', 'fruit-et-legume', 'fruit et légume', 2.0, 'unite', 'unité', 'Marché'));
        $this->repas->listHas(self::line('tomate-surgelee', 'tomate', 'surgelé', 1.0));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'des tomates', sessionIsOpen: false));

        // Then : 3 tomates en fruit et légume (1 + 2), 1 en surgelé
        $this->assertSame('Oui : 3 tomates en fruit et légume, 1 en surgelé.', $dto->speech);
    }

    public function testACountIsSaidWithoutAUnit(): void
    {
        // Given
        $this->repas->listHas(self::line('pain', 'pain', 'boulangerie', 3.0));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame('Oui, tu en as 3.', $dto->speech);
    }

    public function testADecimalQuantityIsSaidWithAComma(): void
    {
        // Given
        $this->repas->listHas(self::line('lait', 'lait', 'crèmerie', 1.5, 'litre', 'litre'));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'du lait', sessionIsOpen: false));

        // Then
        $this->assertSame('Oui, tu en as 1,5 litre.', $dto->speech);
    }

    public function testAProductNotInTheListIsAnsweredNoWithItsName(): void
    {
        // Given
        $this->repas->hasActiveList()->owns('croquette', 'croquette', 'animal');

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'des croquettes', sessionIsOpen: false));

        // Then
        $this->assertSame("Non, il n'y a pas de croquette dans ta liste.", $dto->speech);
    }

    public function testAnUnknownProductIsAnsweredNoWithTheHeardName(): void
    {
        // Given : rien ne ressemble à la phrase
        $this->repas->hasActiveList();

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'de la ciboulette', sessionIsOpen: false));

        // Then : jamais de proposition ni de création
        $this->assertSame("Non, il n'y a pas de ciboulette dans ta liste.", $dto->speech);
    }

    public function testWithoutAnActiveListItSaysSoAndNeverCreatesOne(): void
    {
        // Given : le double répond « pas de liste » par défaut
        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertSame("Tu n'as pas de liste en cours.", $dto->speech);
    }

    public function testSeveralLinesThatTieAreAnsweredTogetherOnePerDepartment(): void
    {
        // Given : « tomate » en fruit et légume (2) ET en surgelé (1), toutes deux dans la liste
        $this->repas
            ->listHas(self::line('tomate', 'tomate', 'fruit et légume', 2.0))
            ->listHas(self::line('tomate-surgelee', 'tomate', 'surgelé', 1.0));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'des tomates', sessionIsOpen: false));

        // Then : une seule phrase, pas de question
        $this->assertSame('Oui : 2 tomates en fruit et légume, 1 en surgelé.', $dto->speech);
    }

    public function testTiedLinesWithAUnitSayTheUnitAndTheProductOnce(): void
    {
        // Given
        $this->repas
            ->listHas(self::line('lait', 'lait', 'crèmerie', 2.0, 'litre', 'litre'))
            ->listHas(self::line('lait-infantile', 'lait', 'bébé', 1.0, 'boite', 'boîte'));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'du lait', sessionIsOpen: false));

        // Then
        $this->assertSame('Oui : 2 litres de lait en crèmerie, 1 boîte en bébé.', $dto->speech);
    }

    public function testANumberAndAUnitLeftInTheItemSlotDoNotChangeWhatIsLookedFor(): void
    {
        // Given : « deux paquets de croquettes » : on cherche « croquettes », la réponse dit combien il y en a
        $this->repas->listHas(self::line('croquette', 'croquette', 'animal', 5.0, 'paquet', 'paquet'));

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'deux paquets de croquettes', sessionIsOpen: false));

        // Then
        $this->assertSame([['user-1', 'croquettes']], $this->repas->lookedUp);
        $this->assertSame('Oui, tu en as 5 paquets.', $dto->speech);
    }

    public function testASentListIsSaidToBeGoneToTheDriveNotAbsent(): void
    {
        // Given : la seule liste de l'utilisateur est partie au drive (elle n'est pas lue)
        $this->repas->hasSentList();

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: false));

        // Then : une phrase vraie et courte, pas « Tu n'as pas de liste en cours. »
        $this->assertSame('Ta liste est déjà partie au drive.', $dto->speech);
    }

    public function testTheSessionStaysOpenWhenItWasOpen(): void
    {
        // When
        $open = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: true));
        $direct = ($this->handler())(new GetSpokenItemInListQuery('user-1', 'du pain', sessionIsOpen: false));

        // Then
        $this->assertTrue($open->keepSessionOpen);
        $this->assertFalse($direct->keepSessionOpen);
    }

    public function testAPhraseWithoutLettersIsNotUnderstoodWhenNothingIsKnown(): void
    {
        // Given
        $this->repas->hasActiveList();

        // When
        $dto = ($this->handler())(new GetSpokenItemInListQuery('user-1', '12', sessionIsOpen: false));

        // Then
        $this->assertSame("Je n'ai pas compris.", $dto->speech);
    }
}
