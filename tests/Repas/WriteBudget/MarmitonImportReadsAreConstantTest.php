<?php

namespace Repas\Tests\Repas\WriteBudget;


use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportMarmitonRecipeCommand;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Domain\Model\User;

/**
 * Importer une recette Marmiton ne lit pas plus quand la recette a plus de lignes (ADR 0037, MAR-003) : les candidats, les ingrédients, les unités, les
 * types et les alias se lisent en une requête chacun, quel que soit le nombre de lignes. Seules les écritures suivent le nombre d'ingrédients créés ou
 * copiés. Le budget par scénario (`budgets.php`) fige le total ; ce test prouve que la part des lectures ne dépend pas du nombre de lignes, dans les deux
 * cas ordinaires : la copie d'une neutre déjà connue chez une personne qui a reçu le catalogue, et la création d'une neutre dont tous les ingrédients
 * existent déjà pour Marmiton.
 */
class MarmitonImportReadsAreConstantTest extends WriteBudgetTestCase
{
    private ?User $catalogOwner = null;

    public function testCopyingAKnownNeutralReadsTheSameWhateverTheNumberOfLines(): void
    {
        $this->assertSame(
            $this->selects(fn() => $this->arrangedCopyOfAKnownNeutral('alpha', 3)),
            $this->selects(fn() => $this->arrangedCopyOfAKnownNeutral('omega', 12)),
        );
    }

    public function testCreatingANeutralFromIngredientsMarmitonAlreadyKnowsReadsTheSameWhateverTheNumberOfLines(): void
    {
        $this->assertSame(
            $this->selects(fn() => $this->arrangedNeutralFromKnownIngredients('sigma', 3)),
            $this->selects(fn() => $this->arrangedNeutralFromKnownIngredients('theta', 12)),
        );
    }

    /**
     * @param callable(): object $arrange
     */
    private function selects(callable $arrange): int
    {
        [, $byTable] = $this->measure(fn() => $arrange());

        return array_sum(array_filter($byTable, static fn(string $key): bool => str_starts_with($key, 'SELECT'), ARRAY_FILTER_USE_KEY));
    }

    private function arrangedCopyOfAKnownNeutral(string $prefix, int $lines): ImportMarmitonRecipeCommand
    {
        $user = $this->savedUser();
        $this->catalogIngredients($prefix, $lines, $user);
        $id = $this->pageOf($prefix, $lines);
        $this->service(CommandBusInterface::class)->dispatch(new ImportMarmitonRecipeCommand($this->urlOf($id), $this->savedUser()->getId(), UuidGenerator::new()));

        return new ImportMarmitonRecipeCommand($this->urlOf($id), $user->getId(), UuidGenerator::new());
    }

    private function arrangedNeutralFromKnownIngredients(string $prefix, int $lines): ImportMarmitonRecipeCommand
    {
        $user = $this->savedUser();
        $this->catalogIngredients($prefix, $lines, $user);
        // Une première recette crée tous les ingrédients de Marmiton ; la mesure est celle d'une seconde recette, qui les réutilise tous
        $first = $this->pageOf($prefix, $lines);
        $this->service(CommandBusInterface::class)->dispatch(new ImportMarmitonRecipeCommand($this->urlOf($first), $this->savedUser()->getId(), UuidGenerator::new()));
        $second = $this->pageOf($prefix, $lines);

        return new ImportMarmitonRecipeCommand($this->urlOf($second), $user->getId(), UuidGenerator::new());
    }

    /**
     * Le catalogue de `$lines` ingrédients « de base », dont `$user` a reçu une copie et son alias (l'état d'un compte activé).
     */
    private function catalogIngredients(string $prefix, int $lines, User $user): void
    {
        $this->savedMarmitonReferenceData();
        $this->catalogOwner ??= $this->savedUser(new UserBuilder()->isCatalogOwner());
        for ($index = 1; $index <= $lines; $index++) {
            $origin = new IngredientBuilder()->withName($prefix . $index)->withDepartment(new DepartmentBuilder()->isBakery())->withDefaultCookingUnit(new UnitBuilder()->isGramme())
                ->withDefaultPurchaseUnit(new UnitBuilder()->isGramme())->withCreator($this->catalogOwner, true)->withBase()->build();
            $this->persister->saveIngredient($origin);
            $copy = Ingredient::copyFromOriginal($origin, $user->getId(), $origin->getSlug() . $user->getId());
            $this->persister->saveIngredient($copy);
            $this->persister->saveIngredientAlias(IngredientAlias::create($user->getId(), $origin->getSlug(), $copy->getSlug(), AliasKind::ADOPTION, 'activation', new \DateTimeImmutable()));
        }
    }

    /**
     * Programme la page d'une recette de `$lines` lignes (« 100 g de alpha1 »…), sous un identifiant neuf ; rend cet identifiant.
     */
    private function pageOf(string $prefix, int $lines): string
    {
        static $next = 1000;
        $id = (string) $next++;
        $this->marmitonPageReader($id, array_map(static fn(int $index): string => sprintf('100 g de %s%d', $prefix, $index), range(1, $lines)));

        return $id;
    }

    private function urlOf(string $id): string
    {
        return sprintf('https://www.marmiton.org/recettes/recette_tarte_%s.aspx', $id);
    }
}
