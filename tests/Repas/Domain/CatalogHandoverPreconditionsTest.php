<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\CatalogHandoverState;
use Repas\Repas\Domain\Model\HandoverPrecondition;
use Repas\Repas\Domain\Service\CatalogHandoverPreconditions;

/**
 * Les préconditions de la passation du catalogue (ING-009, P1 à P7) : pures, jugées sur un état lu. Pour chacune, un test où elle manque (et
 * une seule), et la projection « une fois l'amorçage fait » que le rapport à blanc affiche.
 */
class CatalogHandoverPreconditionsTest extends TestCase
{
    private const array FILE = ['beurre' => true, 'farine' => true, 'piment' => false];
    private const string OWNER = 'owner-1';

    public function testAHealthyStateMeetsEveryPrecondition(): void
    {
        $preconditions = $this->evaluate($this->state());

        $this->assertSame(['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7'], array_map(static fn(HandoverPrecondition $p): string => $p->code, $preconditions));
        $this->assertSame([], array_filter($preconditions, static fn(HandoverPrecondition $p): bool => !$p->met));
        $this->assertSame([], new CatalogHandoverPreconditions()->unmet($this->state(), self::FILE, true));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, string, string}>
     */
    public static function unmetCases(): iterable
    {
        yield 'P1 : le propriétaire n\'existe pas' => [['ownerId' => null, 'ownerStatus' => null, 'ownerIsAdministrator' => false], true, 'P1', "n'existe pas"];
        yield 'P1 : le propriétaire n\'est pas actif' => [['ownerStatus' => 'DISABLED'], true, 'P1', "n'est pas actif"];
        yield 'P1 : le propriétaire n\'est pas administrateur' => [['ownerIsAdministrator' => false], true, 'P1', "n'est pas administrateur"];
        yield 'P2 : une ligne d\'un autre compte sur un ingrédient sans créateur' => [['foreignLinesByAccount' => ['compte-a' => ['recettes' => 2, 'choix du drive' => 1]]], true, 'P2', 'choix du drive : 1, recettes : 2 (dans 1 compte(s))'];
        yield 'P3 : un compte actif sans amorçage' => [['activeNotSeeded' => ['compte-a']], true, 'P3', '1 compte(s) actif(s) sans amorçage'];
        yield 'P3 : un compte actif sans aucun ingrédient' => [['activeWithoutIngredient' => ['compte-a', 'compte-b']], true, 'P3', '2 compte(s) actif(s) sans aucun ingrédient'];
        yield 'P4 : l\'amorçage à l\'activation est coupé' => [[], false, 'P4', 'il est coupé'];
        yield 'P5 : un ingrédient sans créateur hors fichier' => [['slugsWithoutCreator' => ['beurre', 'farine', 'inconnu', 'piment'], 'markersWithoutCreator' => ['beurre' => true, 'farine' => true, 'inconnu' => false, 'piment' => false]], true, 'P5', '1 ingrédient(s) sans créateur ne sont pas dans le fichier'];
        yield 'P5 : un ingrédient du fichier manquant' => [['slugsWithoutCreator' => ['beurre', 'farine'], 'markersWithoutCreator' => ['beurre' => true, 'farine' => true]], true, 'P5', '1 ingrédient(s) du fichier ne sont pas sans créateur'];
        yield 'P5 : un ingrédient sans créateur qui a une origine' => [['withoutCreatorWithOrigin' => 1], true, 'P5', '1 ont une origine'];
        yield 'P5 : un slug au format d\'un slug de compte' => [['withoutCreatorAccountFormat' => 3], true, 'P5', "3 ont un slug de la forme d'un slug de compte"];
        yield 'P6 : une référence orpheline' => [['orphans' => ['conversions' => 1]], true, 'P6', 'conversions : 1'];
        yield 'P7 : une ligne du propriétaire sur l\'ingrédient d\'un autre' => [['ownerLinesOnOthers' => ['lignes de liste' => 1]], true, 'P7', 'lignes de liste : 1'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('unmetCases')]
    public function testEachPreconditionIsTheOnlyOneToFailWhenItsConditionIsMissing(array $override, bool $seedingEnabled, string $code, string $detail): void
    {
        // When
        $preconditions = $this->evaluate($this->state($override), $seedingEnabled);
        $unmet = array_values(array_filter($preconditions, static fn(HandoverPrecondition $p): bool => !$p->met));

        // Then : elle seule manque, et son détail est un nombre, jamais un identifiant
        $this->assertSame([$code], array_map(static fn(HandoverPrecondition $p): string => $p->code, $unmet));
        $this->assertStringContainsString($detail, $unmet[0]->detail);
        $this->assertStringNotContainsString('compte-a', $unmet[0]->detail);
        $this->assertSame([$unmet[0]->describe()], new CatalogHandoverPreconditions()->unmet($this->state($override), self::FILE, $seedingEnabled));
    }

    public function testSeedingLiftsP2AndP3ForTheAccountsItWillServeAndOnlyForThem(): void
    {
        // Given : le compte vide a une ligne sur un ancien commun et n'est pas amorcé ; un autre compte, en attente, a lui aussi une ligne
        $state = $this->state([
            'foreignLinesByAccount' => ['compte-vide' => ['lignes de liste' => 2], 'compte-en-attente' => ['recettes' => 1]],
            'activeNotSeeded' => ['compte-vide'],
            'activeWithoutIngredient' => ['compte-vide'],
        ]);

        // When : l'amorçage servirait le compte vide
        $byCode = $this->byCode($this->evaluate($state, true, ['compte-vide']));

        // Then : P3 est levée (le compte vide est servi) ; P2 ne l'est pas (le compte en attente garde sa ligne) ; ni l'une ni l'autre aujourd'hui
        $this->assertFalse($byCode['P3']->met);
        $this->assertTrue($byCode['P3']->metAfterSeeding);
        $this->assertFalse($byCode['P2']->met);
        $this->assertFalse($byCode['P2']->metAfterSeeding);

        // Et si l'amorçage servait aussi l'autre compte, P2 serait levée
        $this->assertTrue($this->byCode($this->evaluate($state, true, ['compte-vide', 'compte-en-attente']))['P2']->metAfterSeeding);
    }

    public function testTheOtherPreconditionsAreNotLiftedBySeeding(): void
    {
        $byCode = $this->byCode($this->evaluate($this->state(['ownerStatus' => 'DISABLED', 'orphans' => ['recettes' => 1], 'ownerLinesOnOthers' => ['recettes' => 1]]), false, ['compte-a']));

        foreach (['P1', 'P4', 'P6', 'P7'] as $code) {
            $this->assertFalse($byCode[$code]->metAfterSeeding, $code);
        }
    }

    public function testTheStateKnowsWhenTheHandOverIsDone(): void
    {
        $done = $this->state(['slugsWithoutCreator' => [], 'markersWithoutCreator' => [], 'fileSlugsAtOwner' => self::FILE]);

        $this->assertTrue($done->isHandedOver(self::FILE));
        $this->assertFalse($this->state()->isHandedOver(self::FILE), 'il reste des ingrédients sans créateur');
        $this->assertFalse($this->state(['slugsWithoutCreator' => [], 'markersWithoutCreator' => [], 'fileSlugsAtOwner' => ['beurre' => true]])->isHandedOver(self::FILE), 'une partie seulement est chez le propriétaire');
        $this->assertFalse($done->isHandedOver([]), 'un classement vide n\'est jamais « fait »');
    }

    /**
     * @param list<string> $accountsToSeed
     *
     * @return list<HandoverPrecondition>
     */
    private function evaluate(CatalogHandoverState $state, bool $seedingEnabled = true, array $accountsToSeed = []): array
    {
        return new CatalogHandoverPreconditions()->evaluate($state, self::FILE, $seedingEnabled, $accountsToSeed);
    }

    /**
     * @param list<HandoverPrecondition> $preconditions
     *
     * @return array<string, HandoverPrecondition>
     */
    private function byCode(array $preconditions): array
    {
        return array_column(array_map(static fn(HandoverPrecondition $p): array => [$p->code, $p], $preconditions), 1, 0);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function state(array $override = []): CatalogHandoverState
    {
        $values = $override + [
            'ownerId' => self::OWNER,
            'ownerStatus' => 'ACTIVE',
            'ownerIsAdministrator' => true,
            'administrators' => 1,
            'slugsWithoutCreator' => ['beurre', 'farine', 'piment'],
            'markersWithoutCreator' => self::FILE,
            'withoutCreatorWithOrigin' => 0,
            'withoutCreatorAccountFormat' => 0,
            'ownConversionsWithoutCreator' => 4,
            'fileSlugsAtOwner' => [],
            'foreignLinesByAccount' => [],
            'ownerLinesOnOthers' => [],
            'activeNotSeeded' => [],
            'activeWithoutIngredient' => [],
            'orphans' => [],
        ];

        return new CatalogHandoverState(...$values);
    }
}
