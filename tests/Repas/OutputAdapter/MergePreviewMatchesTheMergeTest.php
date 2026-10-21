<?php

namespace Repas\Tests\Repas\OutputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Application\Query\GetMergePreview\GetMergePreviewQuery;
use Repas\Repas\Application\Query\GetMergePreview\MergePreviewDTO;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;
use Repas\User\Domain\Model\User;

/**
 * « L'aperçu dit EXACTEMENT ce que la fusion ferait » (ING-005) : pour une série de situations, l'aperçu (la lecture, le verdict d'un service
 * de domaine sur des faits lus en SQL) est comparé à l'écriture (le cas d'usage, les mêmes règles sur les faits de ses ports), avec les vrais
 * bus et la vraie base. Si l'aperçu autorise : la fusion réussit et les nombres annoncés sont ceux de la base d'après (recettes et listes
 * dont la version a monté, choix du drive qui ont changé d'ingrédient, rayon, quantités ré-exprimées). S'il refuse : la fusion lève l'exception
 * de ce même cas et AUCUNE table n'a changé.
 */
class MergePreviewMatchesTheMergeTest extends DatabaseTestCase
{
    /** Le code d'erreur de la fusion pour chaque refus de l'aperçu. */
    private const array ERROR_OF_BLOCK = [
        'SAME_INGREDIENT' => 'CANNOT_MERGE_INTO_ITSELF',
        'USED_BY_ANOTHER_USER' => 'INGREDIENT_USED_BY_ANOTHER_USER',
        'IN_SENT_LIST' => 'INGREDIENT_IN_SENT_LIST',
        'UNIT_MISSING' => 'MERGE_UNIT_MISSING',
    ];

    /**
     * @return iterable<string, array{\Closure}> la situation : une fonction qui remplit la base, et peut remplacer l'un des deux ingrédients (passés par référence)
     */
    public static function situations(): iterable
    {
        yield 'rien ne l\'utilise' => [static function (): void {
        }];

        yield 'recettes, listes et choix du drive' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->recipe('r1', $u->getId(), [[$b->getSlug(), 1.0, 'gramme']]);
            $d->recipe('r2', $u->getId(), [[$a->getSlug(), 1.0, 'kilo'], [$b->getSlug(), 2.0, 'unite']]);
            $d->recipe('r3', $u->getId(), [[$a->getSlug(), 1.0, 'kilo']]);
            $d->shoppingList('l1', $u->getId(), rows: [['x1', $b->getSlug(), 1.0, 'kilo']], items: [['y1', $b->getSlug(), 2.0, 'kilo']]);
            $d->shoppingList('l2', $u->getId(), rows: [['x2a', $a->getSlug(), 1.0, 'kilo'], ['x2b', $b->getSlug(), 1.0, 'kilo']]);
            $d->shoppingList('l3', $u->getId(), rows: [['x3', $a->getSlug(), 1.0, 'kilo']]);
            $d->choice('c1', $u->getId(), $b->getSlug(), '111111');
            $d->choice('c2', $u->getId(), $a->getSlug(), '111111');
            $d->choice('c3', $u->getId(), $b->getSlug(), '222222');
        }];

        yield 'deux ingrédients, chacun utilisé dans une seule recette (le cas de deux « Melon »)' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->recipe('r1', $u->getId(), [[$a->getSlug(), 1.0, 'gramme']]);
            $d->recipe('r2', $u->getId(), [[$b->getSlug(), 1.0, 'gramme']]);
        }];

        yield 'des recettes et des listes qui utilisent déjà les deux comptent une fois' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->recipe('both', $u->getId(), [[$a->getSlug(), 1.0, 'gramme'], [$b->getSlug(), 2.0, 'gramme'], [$b->getSlug(), 3.0, 'kilo']]);
            $d->recipe('only-b', $u->getId(), [[$b->getSlug(), 1.0, 'gramme']]);
            $d->recipe('only-a', $u->getId(), [[$a->getSlug(), 1.0, 'gramme']]);
            $d->recipe('theirs', $other->getId(), [[$a->getSlug(), 1.0, 'gramme']]);
            $d->shoppingList('l-both', $u->getId(), rows: [['x1', $a->getSlug(), 1.0, 'kilo']], items: [['y1', $b->getSlug(), 1.0, 'kilo']]);
            $d->shoppingList('l-only-b', $u->getId(), rows: [['x2', $b->getSlug(), 1.0, 'kilo']], items: [['y2', $b->getSlug(), 1.0, 'kilo']]);
            $d->shoppingList('l-only-a', $u->getId(), 'PAUSED', items: [['y3', $a->getSlug(), 1.0, 'kilo']]);
            $d->shoppingList('l-none', $u->getId(), rows: [['x4', 'autre', 1.0, 'kilo']]);
        }];

        yield 'une conversion du gardé qui lui manque, récupérée de l\'absorbé' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->conversion($b, 25.0, new UnitBuilder()->isSlice(), new UnitBuilder()->isGramme());
            $d->recipe('r1', $u->getId(), [[$b->getSlug(), 4.0, 'tranche']]);
        }];

        yield 'deux conversions de l\'absorbé qui relient la même unité nouvelle : une seule est copiée' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->conversion($b, 25.0, new UnitBuilder()->isSlice(), new UnitBuilder()->isGramme());
            $d->conversion($b, 0.03, new UnitBuilder()->isSlice(), new UnitBuilder()->isKilo());
            $d->recipe('r1', $u->getId(), [[$b->getSlug(), 4.0, 'tranche']]);
        }];

        yield 'un coefficient en conflit : celui du gardé gagne, rien n\'est copié' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->conversion($b, 160.0);
            $d->recipe('r1', $u->getId(), [[$b->getSlug(), 2.0, 'unite']]);
        }];

        yield 'une unité impossible même après la récupération' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->conversion($b, 25.0, new UnitBuilder()->isSlice(), new UnitBuilder()->isGramme());
            $d->recipe('r1', $u->getId(), [[$b->getSlug(), 4.0, 'tranche'], [$b->getSlug(), 1.0, 'litre']]);
        }];

        yield 'une unité de liste débloquée par la conversion de l\'absorbé' => [static function (MergeDataset $d, User $u, User $other, Ingredient &$a, Ingredient &$b): void {
            $b = $d->ingredient('Tomates au litre', $u, new UnitBuilder()->isLiter());
            $d->conversion($b, 1000.0, new UnitBuilder()->isLiter(), new UnitBuilder()->isGramme());
            $d->shoppingList('l1', $u->getId(), rows: [['x1', $b->getSlug(), 2.0, 'litre']], items: [['y1', $b->getSlug(), 1.0, 'litre']]);
        }];

        yield 'une liste en pause, une liste terminée et les lignes d\'un autre utilisateur sur le gardé' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->shoppingList('paused', $u->getId(), 'PAUSED', rows: [['x1', $b->getSlug(), 1.0, 'kilo']]);
            $d->shoppingList('done', $u->getId(), 'COMPLETED', items: [['y1', $b->getSlug(), 1.0, 'kilo']]);
            $d->recipe('theirs', $other->getId(), [[$a->getSlug(), 1.0, 'kilo']]);
        }];

        yield 'une unité des recettes qui manque' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->recipe('r1', $u->getId(), [[$b->getSlug(), 1.0, 'tranche']]);
            $d->shoppingList('l1', $u->getId(), rows: [['x1', $b->getSlug(), 1.0, 'kilo']]);
        }];

        yield 'une liste envoyée qui utilise l\'absorbé' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->shoppingList('sent', $u->getId(), 'SENT', rows: [['x1', $b->getSlug(), 1.0, 'kilo']]);
        }];

        yield 'une liste envoyée qui utilise le gardé' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->shoppingList('sent', $u->getId(), 'SENT', items: [['y1', $a->getSlug(), 1.0, 'kilo']]);
            $d->shoppingList('active', $u->getId(), rows: [['x2', $b->getSlug(), 1.0, 'kilo']]);
        }];

        yield 'une ligne héritée d\'un autre utilisateur' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->recipe('legacy', $other->getId(), [[$b->getSlug(), 1.0, 'gramme']]);
        }];

        yield 'un choix du drive hérité d\'un autre utilisateur' => [static function (MergeDataset $d, User $u, User $other, Ingredient $a, Ingredient $b): void {
            $d->choice('legacy', $other->getId(), $b->getSlug());
        }];

        yield 'un autre rayon' => [static function (MergeDataset $d, User $u, User $other, Ingredient &$a, Ingredient &$b): void {
            $b = $d->ingredient('Tomates surgelées', $u, department: new DepartmentBuilder()->isMeat());
            $d->recipe('r1', $u->getId(), [[$b->getSlug(), 1.0, 'gramme']]);
        }];

        yield 'une autre unité d\'achat, convertible' => [static function (MergeDataset $d, User $u, User $other, Ingredient &$a, Ingredient &$b): void {
            $a = $d->ingredient('Tomate au gramme', $u, new UnitBuilder()->isGramme());
            $d->shoppingList('l1', $u->getId(), rows: [['x1', $b->getSlug(), 1.5, 'kilo']], items: [['y1', $b->getSlug(), 2.0, 'kilo']]);
        }];

        yield 'une autre unité d\'achat, qui ne se convertit pas' => [static function (MergeDataset $d, User $u, User $other, Ingredient &$a, Ingredient &$b): void {
            $b = $d->ingredient('Tomates au litre', $u, new UnitBuilder()->isLiter());
            $d->shoppingList('l1', $u->getId(), rows: [['x1', $b->getSlug(), 1.0, 'litre']]);
        }];

        yield 'le même ingrédient' => [static function (MergeDataset $d, User $u, User $other, Ingredient &$a, Ingredient &$b): void {
            $b = $a;
        }];
    }

    #[DataProvider('situations')]
    public function testThePreviewAnnouncesWhatTheMergeThenDoes(callable $arrange): void
    {
        // Given
        $dataset = new MergeDataset($this->entityManager, new DataPersister(static::getContainer()));
        $dataset->units();
        $user = $dataset->user();
        $other = $dataset->user();
        $kept = $dataset->ingredient('Tomate', $user);
        $absorbed = $dataset->ingredient('Tomates', $user);
        $dataset->conversion($kept);
        $arrange($dataset, $user, $other, $kept, $absorbed);
        $this->entityManager->clear();

        // When : l'aperçu, puis la fusion
        /** @var MergePreviewDTO $preview */
        $preview = static::getContainer()->get(QueryBusInterface::class)->ask(new GetMergePreviewQuery($user->getId(), $absorbed->getSlug(), $kept->getSlug()));
        $recipeVersions = $dataset->recipeVersions();
        $listVersions = $dataset->listVersions();
        $choicesBefore = $dataset->choiceSlugs();
        $conversionsBefore = $dataset->ownConversions($kept->getSlug());
        $unitsBefore = $this->listUnits($dataset);
        $hashes = $dataset->tableHashes();
        $this->entityManager->clear();

        $error = null;
        try {
            static::getContainer()->get(CommandBusInterface::class)->dispatch(new MergeIngredientsCommand($user->getId(), $absorbed->getSlug(), $kept->getSlug()));
        } catch (IngredientException $exception) {
            $error = $exception->getMessage();
        }

        // Then
        $this->assertSame($preview->blockedBy === null, $preview->canMerge);
        if ($preview->blockedBy !== null) {
            // refusée : la même raison, et rien n'a bougé nulle part
            $this->assertSame(self::ERROR_OF_BLOCK[$preview->blockedBy], $error);
            $this->assertSame($hashes, $dataset->tableHashes());
            // rien n'est écrit, donc aucune conversion n'est copiée : l'aperçu ne l'annonce pas non plus
            $this->assertSame(0, $preview->recoveredConversionCount);

            return;
        }

        $this->assertNull($error, 'l\'aperçu autorisait la fusion');
        $this->assertSame(0, $dataset->count('ingredient', 'slug = ?', [$absorbed->getSlug()]));
        // autant de recettes et de listes que l'aperçu en annonçait ont vu leur version monter, et aucune autre
        $this->assertSame($preview->recipeCount, count(array_filter($dataset->recipeVersions(), static fn(int $version, string $id): bool => $version !== $recipeVersions[$id], ARRAY_FILTER_USE_BOTH)));
        $this->assertSame($preview->listCount, count(array_filter($dataset->listVersions(), static fn(int $version, string $id): bool => $version !== $listVersions[$id], ARRAY_FILTER_USE_BOTH)));
        // autant de choix du drive ont changé d'ingrédient que l'aperçu en annonçait
        $choicesAfter = $dataset->choiceSlugs();
        $this->assertSame($preview->driveChoiceCount, count(array_filter($choicesAfter, static fn(string $slug, string $id): bool => ($choicesBefore[$id] ?? $slug) !== $slug, ARRAY_FILTER_USE_BOTH)));
        // le rayon du gardé l'emporte, quel que soit celui de l'absorbé
        $this->assertSame($preview->departmentChanges, $preview->absorbed->departmentSlug !== $preview->keep->departmentSlug);
        $this->assertSame($preview->keep->departmentSlug, $dataset->connection->fetchOne('SELECT department FROM ingredient WHERE slug = ?', [$kept->getSlug()]));
        // des quantités de liste ont changé d'unité si et seulement si l'aperçu le disait
        $unitsAfter = $this->listUnits($dataset);
        $changed = array_filter($unitsAfter, static fn(string $unit, string $id): bool => ($unitsBefore[$id] ?? $unit) !== $unit, ARRAY_FILTER_USE_BOTH);
        $this->assertSame($preview->listQuantitiesConverted, $changed !== []);
        // le TOTAL annoncé est celui de la base d'après : les recettes et les listes DISTINCTES de l'utilisateur qui utilisent le gardé
        $used = $dataset->usedBy($user->getId(), $kept->getSlug());
        $this->assertSame([$preview->resultRecipeCount, $preview->resultListCount], [$used['recipes'], $used['lists']]);
        // autant de conversions ont été copiées sur le gardé que l'aperçu en annonçait, et seulement celles-là
        $this->assertSame($preview->recoveredConversionCount, count($dataset->ownConversions($kept->getSlug())) - count($conversionsBefore));
        $this->assertSame($conversionsBefore, $this->keptOriginals($dataset->ownConversions($kept->getSlug()), $conversionsBefore), 'celles du gardé n\'ont pas bougé');
        // et rien n'est resté sur l'absorbé
        $this->assertSame(0, $dataset->count('conversion', 'ingredient = ?', [$absorbed->getSlug()]));
    }

    /**
     * Parmi les conversions du gardé d'après, celles qu'il avait avant (les siennes ne changent jamais : ni unités, ni coefficient).
     *
     * @param list<array{string, string, float}> $after
     * @param list<array{string, string, float}> $before
     * @return list<array{string, string, float}>
     */
    private function keptOriginals(array $after, array $before): array
    {
        return array_values(array_filter($after, static fn(array $conversion): bool => in_array($conversion, $before, true)));
    }

    /**
     * @return array<string, string> l'unité de chaque ligne de liste et de chaque ingrédient ajouté à la main, par identifiant
     */
    private function listUnits(MergeDataset $dataset): array
    {
        return $dataset->connection->fetchAllKeyValue('SELECT id, unit FROM shopping_list_row UNION ALL SELECT id, unit FROM shopping_list_ingredient');
    }
}
