<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Application\Command\ReopenShoppingList\ReopenShoppingListCommand;
use Repas\Repas\Application\Command\SendShoppingList\SendShoppingListCommand;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\MergeDataset;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Repas\User\Domain\Model\User;

/**
 * Le test d'équivalence du drive pour la fusion (`docs/ingredients/migration.md` § 15, ING-005) : une liste qui utilise « Tomates » donne,
 * avant et après la fusion de « Tomates » dans « Tomate », le MÊME plan d'envoi (`GetDrivePlan`) et la MÊME estimation (`GetDriveEstimate`) à
 * l'ingrédient près (son slug et son nom : le gardé garde le sien) : les produits mémorisés de l'absorbé sont ceux du gardé (quantité, unité,
 * produits, rang, contenance, recherche mémorisée, prix). Quand le gardé a déjà son choix, c'est SON choix qui sert le plan.
 *
 * Ce sont les vraies requêtes du drive sur les vraies listes, avec les vrais bus. La liste est envoyée pour lire le plan, rouverte pour
 * fusionner (une liste envoyée bloque la fusion), puis envoyée de nouveau.
 */
class MergeDriveEquivalenceTest extends WriteBudgetTestCase
{
    private MergeDataset $dataset;
    private User $user;
    private Ingredient $kept;
    private Ingredient $absorbed;
    private string $listId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataset = new MergeDataset($this->entityManager, $this->persister);
        $this->dataset->units();
        $this->user = $this->savedUser();
        $this->savedDriveSettings($this->user);
        $this->kept = $this->dataset->ingredient('Tomate', $this->user);
        $this->absorbed = $this->dataset->ingredient('Tomates', $this->user);
        $this->dataset->conversion($this->kept);
        $this->dataset->conversion($this->absorbed);
        $control = $this->dataset->ingredient('Concombre', $this->user);

        // Une liste : 1,5 kilo de « Tomates », 2 de « Concombre » (témoin), et un ajout à la main de « Tomates »
        $list = new ShoppingListBuilder()->withOwner($this->user)->build();
        $list->addRow($this->absorbed->getSlug(), 'kilo', 1.5);
        $list->addRow($control->getSlug(), 'kilo', 2.0);
        $list->addIngredient($this->absorbed->getSlug(), 'kilo');
        static::getContainer()->get(ShoppingListRepository::class)->save($list);
        $this->listId = $list->getId();
    }

    public function testThePlanAndTheEstimateAreTheSameWhenOnlyTheAbsorbedIngredientHadAChoice(): void
    {
        // Given : des choix pour « Tomates » (produits acceptés et refusé, contenance, recherche mémorisée, prix) et pour le témoin
        $this->saveChoices($this->absorbed->getSlug(), 700000, true);
        $this->saveChoices('concombre' . $this->user->getId(), 710000, false);
        [$planBefore, $estimateBefore] = $this->readPlanAndEstimate();
        $this->assertSame('KNOWN', $this->lineOf($planBefore, $this->absorbed->getSlug())['status']);
        $this->assertCount(2, $this->lineOf($planBefore, $this->absorbed->getSlug())['products']);
        $this->assertGreaterThan(0, $estimateBefore['estimatedLines']);
        $this->reopen();

        // When
        $this->merge();
        [$planAfter, $estimateAfter] = $this->readPlanAndEstimate();

        // Then : les mêmes lignes à l'ingrédient près ; la ligne de « Tomates » est désormais celle de « Tomate », avec les produits de « Tomates »
        $this->assertSame(self::normalize($planBefore), self::normalize($planAfter));
        $this->assertSame(self::normalize($estimateBefore), self::normalize($estimateAfter));
        $this->assertSame(
            array_map(static fn(array $line): string => $line['ingredientSlug'], $planBefore['lines']),
            array_map(fn(array $line): string => $line['ingredientSlug'] === $this->kept->getSlug() ? $this->absorbed->getSlug() : $line['ingredientSlug'], $planAfter['lines']),
        );
        $tomate = $this->lineOf($planAfter, $this->kept->getSlug());
        $this->assertSame('Tomate', $tomate['ingredientName']);
        $this->assertSame(['700000', '700001'], array_column($tomate['products'], 'retailerProductId'));
        $this->assertSame('recherche 700000', $tomate['searchQuery']);
        $this->assertSame(0, $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM drive_choice WHERE ingredient_slug = ?", [$this->absorbed->getSlug()]));
    }

    public function testThePlanAndTheEstimateAreTheSameWhenTheKeptIngredientRecoversAConversionOfTheAbsorbedOne(): void
    {
        // Given : « Tomate » ne sait pas compter l'unité en grammes, « Tomates » le sait (150) ; « Tomates » a son choix
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM conversion WHERE ingredient = ?', [$this->kept->getSlug()]);
        $this->assertSame([], $this->dataset->ownConversions($this->kept->getSlug()));
        $this->saveChoices($this->absorbed->getSlug(), 700000, true);
        [$planBefore, $estimateBefore] = $this->readPlanAndEstimate();
        $this->reopen();

        // When
        $this->merge();
        [$planAfter, $estimateAfter] = $this->readPlanAndEstimate();

        // Then : « Tomate » a récupéré la conversion, et le plan et l'estimation sont inchangés à l'ingrédient près (le drive ne lit pas les conversions)
        $this->assertSame([['unite', 'gramme', 150.0]], $this->dataset->ownConversions($this->kept->getSlug()));
        $this->assertSame(self::normalize($planBefore), self::normalize($planAfter));
        $this->assertSame(self::normalize($estimateBefore), self::normalize($estimateAfter));
    }

    public function testTheChoiceOfTheKeptIngredientServesThePlanWhenBothHadOne(): void
    {
        // Given : « Tomate » et « Tomates » ont chacun leurs produits
        $this->saveChoices($this->absorbed->getSlug(), 700000, true);
        $this->saveChoices($this->kept->getSlug(), 720000, true);
        $this->reopenAfterReading();

        // When
        $this->merge();
        [$planAfter] = $this->readPlanAndEstimate();

        // Then : le plan sert les produits de « Tomate », ceux de « Tomates » ont disparu avec leur choix
        $tomate = $this->lineOf($planAfter, $this->kept->getSlug());
        $this->assertSame(['720000', '720001'], array_column($tomate['products'], 'retailerProductId'));
        $this->assertSame('recherche 720000', $tomate['searchQuery']);
        $this->assertSame(0, $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM drive_choice WHERE ingredient_slug = ?", [$this->absorbed->getSlug()]));
        $this->assertSame(0, $this->entityManager->getConnection()->fetchOne("SELECT count(*) FROM drive_choice_product WHERE product_id LIKE ?", ['%' . Product::idFor(Retailer::LECLERC, '123456', '700000')]));
    }

    public function testThePlanOfANewIngredientStaysNewWhenNeitherHadAChoice(): void
    {
        // Given : aucun choix
        $this->reopenAfterReading();

        // When
        $this->merge();
        [$planAfter] = $this->readPlanAndEstimate();

        // Then : « Tomate » est une ligne nouvelle, comme « Tomates » l'était
        $line = $this->lineOf($planAfter, $this->kept->getSlug());
        $this->assertSame(['NEW', []], [$line['status'], $line['products']]);
    }

    private function reopenAfterReading(): void
    {
        $this->readPlanAndEstimate();
        $this->reopen();
    }

    private function reopen(): void
    {
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new ReopenShoppingListCommand($this->listId));
    }

    private function merge(): void
    {
        $this->entityManager->clear();
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new MergeIngredientsCommand($this->user->getId(), $this->absorbed->getSlug(), $this->kept->getSlug()));
        $this->entityManager->clear();
    }

    /**
     * Envoie la liste (si elle ne l'est pas) puis lit le plan et l'estimation, tels que l'API les sert.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function readPlanAndEstimate(): array
    {
        $this->entityManager->clear();
        if (!static::getContainer()->get(ShoppingListRepository::class)->isSent($this->listId)) {
            static::getContainer()->get(CommandBusInterface::class)->dispatch(new SendShoppingListCommand($this->listId));
        }
        $bus = static::getContainer()->get(QueryBusInterface::class);

        return [
            json_decode(json_encode($bus->ask(new GetDrivePlanQuery($this->user->getId(), $this->listId))), true),
            json_decode(json_encode($bus->ask(new GetDriveEstimateQuery($this->user->getId(), $this->listId))), true),
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function lineOf(array $document, string $slug): array
    {
        foreach ($document['lines'] as $line) {
            if ($line['ingredientSlug'] === $slug) {
                return $line;
            }
        }

        $this->fail("Le plan n'a pas de ligne pour $slug.");
    }

    /**
     * Le choix de l'utilisateur pour un ingrédient : deux produits acceptés (le premier avec une contenance et un prix) et un refusé.
     */
    private function saveChoices(string $slug, int $firstProductId, bool $withSearch): void
    {
        $first = (string) $firstProductId;
        $second = (string) ($firstProductId + 1);
        $rejected = (string) ($firstProductId + 2);
        $productId = static fn(string $retailerProductId): string => Product::idFor(Retailer::LECLERC, '123456', $retailerProductId);
        static::getContainer()->get(ProductRepository::class)->saveAll([
            new ProductBuilder()->withRetailerProductId($first)->withLabel("Produit $first")->withPrice(120, 240)->build(),
            new ProductBuilder()->withRetailerProductId($second)->withLabel("Produit $second")->withPrice(99)->build(),
            new ProductBuilder()->withRetailerProductId($rejected)->withLabel("Produit refusé $rejected")->build(),
        ]);
        $builder = new ChoiceBuilder()->withUserId($this->user->getId())->withIngredientSlug($slug)
            ->withAccepted([$productId($first), $productId($second)])->withRejected([$productId($rejected)]);
        if ($withSearch) {
            $builder->withSearchQuery("recherche $first")->withPackSize($productId($first), 20, 'unite');
        }
        static::getContainer()->get(ChoiceRepository::class)->save($builder->build());
    }

    /**
     * Le plan ou l'estimation sans ce qui change légitimement : le slug et le nom de chaque ligne (le gardé garde son nom), la version de la liste
     * et l'horodatage.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function normalize(array $document): array
    {
        unset($document['generatedAt'], $document['listVersion']);
        if (isset($document['lines'])) {
            $document['lines'] = array_map(static function (array $line): array {
                unset($line['ingredientSlug'], $line['ingredientName']);

                return $line;
            }, $document['lines']);
        }

        return $document;
    }
}
