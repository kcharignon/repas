<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\PartShare;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;

/**
 * Le total d'un parent dans la liste de courses (ADR 0038 décisions 6 à 12), sur l'agrégat seul : « ligne du parent = quantité directe + plus grand
 * équivalent de ses parties ». Les huit gestes du tableau de l'ADR, le retrait proportionnel, les bornes et la liste envoyée au drive.
 * Le jaune et le blanc d'œuf valent un œuf chacun (ratio 1) ; la vanille a une demi-gousse qui en vaut la moitié (ratio 2).
 */
class ShoppingListPartsTest extends TestCase
{
    private const string EGG = 'oeuf';
    private const string YOLK = 'jaune';
    private const string WHITE = 'blanc';

    // --- Les huit gestes du tableau de l'ADR 0038 ---

    public function testThreeEggsFromARecipe(): void
    {
        // Given / When
        $list = $this->listWith(3);

        // Then : 3 œufs (recette), aucune partie
        $this->assertSame(3.0, $this->egg($list));
        $this->assertSame([], $list->getPartShares());
    }

    public function testTwoYolksAddedToThreeEggsMakeFive(): void
    {
        // Given
        $list = $this->listWith(3);

        // When : + 2 jaunes (une autre recette)
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // Then : 3 + 2 = 5
        $this->assertSame(5.0, $this->egg($list));
        $this->assertEquals(['jaune' => new PartShare('oeuf', 2.0, 2.0)], $list->getPartShares());
    }

    public function testTwoWhitesCompleteTheTwoYolksInsteadOfAddingUp(): void
    {
        // Given : 3 œufs + 2 jaunes
        $list = $this->listWith(3);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // When : + 2 blancs, des mêmes œufs
        $list->addPart(self::WHITE, self::EGG, 'unite', 2, 2);

        // Then : le blanc complète le jaune, 5 et pas 7
        $this->assertSame(5.0, $this->egg($list));
        $this->assertEquals(['jaune' => new PartShare('oeuf', 2.0, 2.0), 'blanc' => new PartShare('oeuf', 2.0, 2.0)], $list->getPartShares());
    }

    public function testOneMoreYolkMakesSix(): void
    {
        // Given : 3 œufs + 2 jaunes + 2 blancs
        $list = $this->listWith(3);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);
        $list->addPart(self::WHITE, self::EGG, 'unite', 2, 2);

        // When : + 1 jaune de plus
        $list->addPart(self::YOLK, self::EGG, 'unite', 1, 1);

        // Then : jaune 3, blanc 2, le plus grand est 3, donc 3 + 3 = 6
        $this->assertSame(6.0, $this->egg($list));
        $this->assertEquals(new PartShare('oeuf', 3.0, 3.0), $list->partShareOf(self::YOLK));
        $this->assertEquals(new PartShare('oeuf', 2.0, 2.0), $list->partShareOf(self::WHITE));
    }

    public function testRemovingTheRecipeOfTheThreeEggsLeavesWhatThePartsMake(): void
    {
        // Given : 3 œufs + jaune 3 + blanc 2 = 6
        $list = $this->listWith(3);
        $list->addPart(self::YOLK, self::EGG, 'unite', 3, 3);
        $list->addPart(self::WHITE, self::EGG, 'unite', 2, 2);
        $this->assertSame(6.0, $this->egg($list));

        // When : on retire la recette des 3 œufs
        $list->subtractLine(self::EGG, 3);

        // Then : il reste ce que les parties valent, 3 (le plus grand de 3 jaunes et 2 blancs)
        $this->assertSame(3.0, $this->egg($list));
        $this->assertCount(2, $list->getPartShares());
    }

    public function testTwoWhitesThenThreeYolksMakeThreeAndNotFive(): void
    {
        // Given
        $list = $this->emptyList();

        // When : 2 blancs, puis 3 jaunes
        $list->addPart(self::WHITE, self::EGG, 'unite', 2, 2);
        $this->assertSame(2.0, $this->egg($list));
        $list->addPart(self::YOLK, self::EGG, 'unite', 3, 3);

        // Then : 3 œufs, jamais 5
        $this->assertSame(3.0, $this->egg($list));
    }

    public function testRemovingTheRecipeOfTheThreeYolksLeavesTheTwoWhites(): void
    {
        // Given : 2 blancs puis 3 jaunes = 3
        $list = $this->emptyList();
        $list->addPart(self::WHITE, self::EGG, 'unite', 2, 2);
        $list->addPart(self::YOLK, self::EGG, 'unite', 3, 3);

        // When : on retire la recette des 3 jaunes
        $list->subtractLine(self::YOLK, 3);

        // Then : il reste les 2 blancs, donc 2 œufs
        $this->assertSame(2.0, $this->egg($list));
        $this->assertSame(['blanc'], array_keys($list->getPartShares()));
    }

    public function testRemovingTheLastRecipeMakesTheLineDisappear(): void
    {
        // Given : il ne reste que 2 blancs
        $list = $this->emptyList();
        $list->addPart(self::WHITE, self::EGG, 'unite', 2, 2);

        // When
        $list->subtractLine(self::WHITE, 2);

        // Then : plus de ligne, plus de registre
        $this->assertNull($list->findRow(self::EGG));
        $this->assertSame([], $list->getPartShares());
        $this->assertSame(0, $list->countRows());
    }

    // --- Le retrait proportionnel ---

    public function testARemovalIsProportionalToWhatTheListKept(): void
    {
        // Given : 4 demi-gousses à raison de 2 par gousse : 2 gousses (quantité 4, équivalent 2)
        $list = $this->emptyList();
        $list->addPart('demi-gousse', 'vanille', 'unite', 4, 2);

        // When : on en retire 1
        $removed = $list->subtractPart('demi-gousse', 1);

        // Then : un quart de l'équivalent part avec, quel que soit le ratio du jour
        $this->assertEquals(new PartShare('vanille', 1.0, 0.5), $removed);
        $this->assertEquals(new PartShare('vanille', 3.0, 1.5), $list->partShareOf('demi-gousse'));
        $this->assertSame(1.5, $list->findRow('vanille')->getQuantity());
    }

    public function testAddingAfterTheRatioChangedStaysConsistentWithWhatTheListKept(): void
    {
        // Given : 2 jaunes à 1 par œuf déjà dans la liste
        $list = $this->emptyList();
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // When : le ratio est passé à 2 depuis, la recette suivante apporte 2 jaunes qui valent 1 œuf
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 1);

        // Then : l'entrée additionne les quantités et les équivalents, sans rien recalculer
        $this->assertEquals(new PartShare('oeuf', 4.0, 3.0), $list->partShareOf(self::YOLK));
        $this->assertSame(3.0, $this->egg($list));

        // And : retirer 2 jaunes retire la moitié de ce qui a été gardé, pas un équivalent recalculé
        $list->subtractPart(self::YOLK, 2);
        $this->assertSame(1.5, $this->egg($list));
    }

    public function testWithdrawingAtLeastWhatTheEntryHoldsRemovesTheEntry(): void
    {
        // Given
        $list = $this->emptyList();
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // When : on retire plus que ce que la liste a gardé
        $removed = $list->subtractPart(self::YOLK, 5);

        // Then : jamais de quantité négative ni de restes, et ce qui a été ôté est ce qu'il y avait
        $this->assertEquals(new PartShare('oeuf', 2.0, 2.0), $removed);
        $this->assertSame([], $list->getPartShares());
        $this->assertNull($list->findRow(self::EGG));
    }

    public function testFloatingPointDustLeavesNeitherAnEntryNorALine(): void
    {
        // Given : 0,3 en trois fois (0,3 - 0,1 - 0,2 n'est pas tout à fait 0)
        $list = $this->emptyList();
        $list->addPart('creme', 'lait', 'litre', 0.3, 0.3);

        // When
        $list->subtractPart('creme', 0.1);
        $list->subtractPart('creme', 0.2);

        // Then
        $this->assertSame([], $list->getPartShares());
        $this->assertNull($list->findRow('lait'));
    }

    public function testAPartThatIsNotInTheRegistryIsLeftAlone(): void
    {
        // Given
        $list = $this->listWith(3);
        $versionBefore = $list->getVersion();

        // When
        $removed = $list->subtractPart(self::YOLK, 2);

        // Then : rien n'est modifié, l'appelant retire alors une ligne ordinaire
        $this->assertNull($removed);
        $this->assertSame(3.0, $this->egg($list));
        $this->assertSame([], $list->getPartShares());
        $this->assertSame($versionBefore, $list->getVersion());
    }

    public function testRemovingALineThatIsNotAPartTouchesTheRow(): void
    {
        // Given : une ligne ordinaire
        $list = $this->listWith(3);

        // When : `subtractLine` retire de la ligne quand la liste n'a pas gardé de partie sous ce nom
        $list->subtractLine(self::EGG, 2);

        // Then
        $this->assertSame(1.0, $this->egg($list));
    }

    public function testTheLineKeepsTheDirectQuantityWhenThePartsAreRemoved(): void
    {
        // Given : 3 œufs directs + 2 jaunes + 1 blanc = 5
        $list = $this->listWith(3);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);
        $list->addPart(self::WHITE, self::EGG, 'unite', 1, 1);

        // When : les jaunes partent
        $list->subtractPart(self::YOLK, 2);

        // Then : 3 directs + le blanc qui reste (1) = 4
        $this->assertSame(4.0, $this->egg($list));
    }

    // --- La ligne du parent ---

    public function testAddingToTheParentItselfLeavesTheRegistryAlone(): void
    {
        // Given : 2 jaunes
        $list = $this->emptyList();
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);
        $shares = $list->getPartShares();

        // When : un œuf entier ajouté à la main
        $list->addRow(self::EGG, 'unite', 1);

        // Then : 3, le registre n'a pas bougé
        $this->assertSame(3.0, $this->egg($list));
        $this->assertEquals($shares, $list->getPartShares());
    }

    public function testACheckedLineKeepsItsStateWhenAPartMakesItGrow(): void
    {
        // Given : la ligne des œufs est cochée
        $list = $this->listWith(3);
        $list->findRow(self::EGG)->tick();

        // When
        $list->addPart(self::YOLK, self::EGG, 'unite', 5, 5);

        // Then : elle grandit et reste cochée (comme une quantité qui augmente aujourd'hui)
        $this->assertSame(8.0, $this->egg($list));
        $this->assertTrue($list->findRow(self::EGG)->isChecked());
    }

    public function testTheParentRowIsCreatedWithTheParentsUnit(): void
    {
        // Given / When
        $list = $this->emptyList();
        $list->addPart('demi-gousse', 'vanille', 'gousse', 4, 2);

        // Then : la ligne est celle du parent, dans SON unité d'achat
        $this->assertSame('gousse', $list->findRow('vanille')->getUnitSlug());
        $this->assertNull($list->findRow('demi-gousse'));
    }

    public function testTheLargestEquivalentOfEachParentIsIndependent(): void
    {
        // Given : deux parents
        $list = $this->emptyList();
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);
        $list->addPart('demi-gousse', 'vanille', 'gousse', 4, 2);

        // When / Then
        $this->assertSame(2.0, $list->findRow(self::EGG)->getQuantity());
        $this->assertSame(2.0, $list->findRow('vanille')->getQuantity());
        $this->assertSame(['jaune'], array_keys($list->partsOf(self::EGG)));
        $this->assertSame(['demi-gousse'], array_keys($list->partsOf('vanille')));
    }

    // --- Tenir l'invariant quand la ligne du parent baisse autrement ---

    public function testRemovingTheWholeLineAtTheVoiceRemovesItsParts(): void
    {
        // Given : 3 œufs + 2 jaunes
        $list = $this->listWith(3);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // When : « retire les œufs » à la voix, la ligne entière
        $left = $list->removeQuantity(self::EGG, 99);

        // Then : la ligne disparaît avec tout ce qu'elle contenait
        $this->assertSame(0.0, $left);
        $this->assertNull($list->findRow(self::EGG));
        $this->assertSame([], $list->getPartShares());
    }

    public function testALineThatDropsBelowItsPartsShrinksThemInTheSameProportion(): void
    {
        // Given : 3 œufs + 2 jaunes + 2 blancs = 5
        $list = $this->listWith(3);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);
        $list->addPart(self::WHITE, self::EGG, 'unite', 2, 2);

        // When : à la voix, on en retire 4 d'un coup : il reste 1 œuf, moins que les parties (2)
        $left = $list->removeQuantity(self::EGG, 4);

        // Then : le total qui reste est la vérité, les parties s'y ramènent (jamais une quantité directe négative)
        $this->assertSame(1.0, $left);
        $this->assertEquals(new PartShare('oeuf', 1.0, 1.0), $list->partShareOf(self::YOLK));
        $this->assertEquals(new PartShare('oeuf', 1.0, 1.0), $list->partShareOf(self::WHITE));

        // And : retirer ensuite la recette des deux jaunes retire le dernier œuf, sans rien de négatif
        $list->subtractPart(self::YOLK, 2);
        $this->assertSame(1.0, $this->egg($list));
        $list->subtractPart(self::WHITE, 2);
        $this->assertNull($list->findRow(self::EGG));
    }

    public function testSubtractingTheParentBelowItsPartsShrinksThemToo(): void
    {
        // Given : 1 œuf direct + 2 jaunes = 3
        $list = $this->listWith(1);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // When : une soustraction à la main qui descend sous les parties
        $list->subtractRow(self::EGG, 2);

        // Then : 1 œuf, les jaunes ramenés à 1
        $this->assertSame(1.0, $this->egg($list));
        $this->assertEquals(new PartShare('oeuf', 1.0, 1.0), $list->partShareOf(self::YOLK));
    }

    public function testSubtractingTheParentToNothingRemovesItsParts(): void
    {
        // Given
        $list = $this->listWith(1);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // When
        $list->subtractRow(self::EGG, 99);

        // Then
        $this->assertNull($list->findRow(self::EGG));
        $this->assertSame([], $list->getPartShares());
    }

    public function testAPartKeepsTheParentItWasPostedUnderEvenIfItChangedParent(): void
    {
        // Given : le jaune est dans la liste sous « Œuf »
        $list = $this->emptyList();
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // When : l'ingrédient a changé de parent depuis (« Caille ») et reçoit 2 de plus, qui vaudraient 1 chez le nouveau parent
        $list->addPart(self::YOLK, 'caille', 'unite', 2, 1);

        // Then : la liste reste d'une seule pièce : l'entrée garde son parent d'origine, l'équivalent est proportionnel à ce qu'elle a déjà
        $this->assertEquals(new PartShare('oeuf', 4.0, 4.0), $list->partShareOf(self::YOLK));
        $this->assertSame(4.0, $this->egg($list));
        $this->assertNull($list->findRow('caille'));
    }

    // --- Les bornes ---

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'une quantité nulle' => [0.0, 1.0];
        yield 'une quantité négative' => [-1.0, 1.0];
        yield 'un équivalent nul' => [1.0, 0.0];
        yield 'un équivalent négatif' => [1.0, -2.0];
        yield 'une quantité non finie' => [INF, 1.0];
        yield 'un équivalent qui n\'est pas un nombre' => [1.0, NAN];
    }

    #[DataProvider('invalidQuantities')]
    public function testAQuantityThatIsNotAFinitePositiveNumberIsRefusedAndNothingIsWritten(float $quantity, float $equivalent): void
    {
        // Given
        $list = $this->listWith(3);

        // When / Then
        try {
            $list->addPart(self::YOLK, self::EGG, 'unite', $quantity, $equivalent);
            $this->fail('Une quantité sans sens doit être refusée.');
        } catch (ShoppingListException $exception) {
            $this->assertSame(ShoppingListException::QUANTITY_INVALID, $exception->getMessage());
        }
        $this->assertSame([], $list->getPartShares());
        $this->assertSame(3.0, $this->egg($list));
    }

    public function testASubtractedQuantityThatIsNotPositiveIsRefused(): void
    {
        // Given
        $list = $this->emptyList();
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);

        // When / Then
        $this->expectException(ShoppingListException::class);
        $this->expectExceptionMessage(ShoppingListException::QUANTITY_INVALID);
        $list->subtractPart(self::YOLK, 0);
    }

    // --- La liste envoyée au drive ---

    public function testASentListRefusesToAddAPartAndWritesNothing(): void
    {
        // Given : une liste envoyée au drive, 3 œufs et 2 jaunes
        $list = $this->listWith(3);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);
        $list->setStatus(ShoppingListStatus::SENT);

        // When / Then
        try {
            $list->addPart(self::WHITE, self::EGG, 'unite', 9, 9);
            $this->fail('Une liste envoyée au drive ne se modifie pas.');
        } catch (ShoppingListException $exception) {
            $this->assertSame(ShoppingListException::SENT, $exception->getMessage());
            $this->assertSame(409, $exception->getCode());
        }
        $this->assertSame(5.0, $this->egg($list));
        $this->assertSame(['jaune'], array_keys($list->getPartShares()));
    }

    public function testASentListRefusesToSubtractAPartAndWritesNothing(): void
    {
        // Given
        $list = $this->listWith(3);
        $list->addPart(self::YOLK, self::EGG, 'unite', 2, 2);
        $list->setStatus(ShoppingListStatus::SENT);

        // When / Then
        foreach ([fn() => $list->subtractPart(self::YOLK, 1), fn() => $list->subtractLine(self::YOLK, 1), fn() => $list->subtractLine(self::EGG, 1)] as $attempt) {
            try {
                $attempt();
                $this->fail('Une liste envoyée au drive ne se modifie pas.');
            } catch (ShoppingListException $exception) {
                $this->assertSame(ShoppingListException::SENT, $exception->getMessage());
            }
        }
        $this->assertSame(5.0, $this->egg($list));
        $this->assertEquals(new PartShare('oeuf', 2.0, 2.0), $list->partShareOf(self::YOLK));
    }

    private function emptyList(): ShoppingList
    {
        return new ShoppingListBuilder()->build();
    }

    /**
     * Une liste dont la ligne « Œuf » porte `$eggs` œufs posés par une recette (la quantité directe).
     */
    private function listWith(float $eggs): ShoppingList
    {
        $list = $this->emptyList();
        $list->addRow(self::EGG, 'unite', $eggs);

        return $list;
    }

    private function egg(ShoppingList $list): float
    {
        return $list->findRow(self::EGG)->getQuantity();
    }
}
