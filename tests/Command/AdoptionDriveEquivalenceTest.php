<?php

namespace Repas\Tests\Command;


use DateTimeImmutable;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Infrastructure\Loader\IngredientAdoptionReportPostgreSQLLoader;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le test d'équivalence du drive (`docs/ingredients/migration.md` § 8 dernier paragraphe et § 15) : pour un compte qui a des choix, une
 * liste envoyée et une liste active, le plan d'envoi (`GetDrivePlan`) et l'estimation du coût (`GetDriveEstimate`) donnent, avant et
 * après la migration, EXACTEMENT les mêmes lignes à l'ingrédient près (nom, quantité, unité, produits et leur rang, nombre à commander,
 * contenance, recherche mémorisée, prix) : seul le slug de chaque ligne change, et c'est celui de la copie du compte.
 *
 * Ce sont les vraies requêtes du drive, sur les vraies listes, avec des ingrédients construits par les builders du domaine (unités
 * réelles, dimensions, conversions), pas le jeu SQL de `AdoptionDataset`.
 */
class AdoptionDriveEquivalenceTest extends WriteBudgetTestCase
{
    public function testThePlanAndTheEstimateOfASentAndAnActiveListAreTheSameBeforeAndAfterTheMigration(): void
    {
        // Given : un compte avec trois recettes (communs), une liste ENVOYÉE et une liste active, des choix de produits (rangs, refus,
        // contenance, recherche mémorisée, prix) pour la plupart des lignes
        $user = $this->savedUser();
        $recipes = $this->typicalRecipes($user);
        $sentList = $this->savedShoppingList($user, $recipes, null, static function (ShoppingList $list): void {
            $list->send(new DateTimeImmutable('2026-10-10 12:00:00'));
        });
        $activeList = $this->savedShoppingList($user, $recipes);
        $this->savedDriveSettings($user);
        $slugs = $this->entityManager->getConnection()->fetchFirstColumn('SELECT ingredient FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$sentList->getId()]);
        $this->assertGreaterThanOrEqual(5, count($slugs));
        $this->saveChoices($user->getId(), $slugs);

        // Un autre compte concerné, que la migration ne doit pas toucher dans cet essai (--user)
        $bystander = $this->savedUser();
        $this->savedRecipe($bystander, new \Repas\Tests\Helper\Builder\RecipeBuilder()->isPastaCarbonara());

        $bus = static::getContainer()->get(QueryBusInterface::class);
        /** @return array{plan: array<string, mixed>, estimate: array<string, mixed>} le plan et l'estimation d'une liste, tels que l'API les sert */
        $ask = static fn(string $listId, bool $withPlan): array => [
            // Le plan n'existe que pour une liste envoyée ; l'estimation, pour toute liste
            'plan' => $withPlan ? json_decode(json_encode($bus->ask(new GetDrivePlanQuery($user->getId(), $listId))), true) : null,
            'estimate' => json_decode(json_encode($bus->ask(new GetDriveEstimateQuery($user->getId(), $listId))), true),
        ];
        $sentBefore = $ask($sentList->getId(), true);
        $activeBefore = $ask($activeList->getId(), false);
        $activeVersionBefore = (int) $this->entityManager->getConnection()->fetchOne('SELECT version FROM shopping_list WHERE id = ?', [$activeList->getId()]);
        $this->assertGreaterThanOrEqual(3, $sentBefore['plan']['counts']['known'], 'le jeu d\'essai a des produits mémorisés, sinon la preuve ne prouve rien');
        $this->assertNotEmpty($sentBefore['plan']['lines'][0]['products']);
        $this->assertGreaterThan(0, $sentBefore['estimate']['estimatedLines']);

        // When
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
        $status = $tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [IngredientAdoptionReportPostgreSQLLoader::referenceOf($user->getId())]]);
        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->entityManager->clear();

        // Then : les mêmes lignes à l'ingrédient près, pour la liste envoyée comme pour la liste active
        $sentAfter = $ask($sentList->getId(), true);
        $activeAfter = $ask($activeList->getId(), false);
        foreach (['plan', 'estimate'] as $document) {
            $this->assertSame(self::normalize($sentBefore[$document]), self::normalize($sentAfter[$document]), "$document de la liste envoyée");
        }
        $this->assertSame(self::normalize($activeBefore['estimate']), self::normalize($activeAfter['estimate']), 'estimation de la liste active');
        $this->assertGreaterThan(0, $activeBefore['estimate']['estimatedLines']);

        // Seul le slug de chaque ligne a changé : c'est celui de la copie du compte, et la version de la liste a monté d'un cran
        $copies = $this->entityManager->getConnection()->fetchAllKeyValue('SELECT alias_slug, ingredient_slug FROM ingredient_alias WHERE user_id = ?', [$user->getId()]);
        foreach (['plan', 'estimate'] as $document) {
            $slugsBefore = array_column($sentBefore[$document]['lines'], 'ingredientSlug');
            $this->assertNotEmpty($slugsBefore);
            $this->assertSame(array_map(static fn(string $slug): string => $copies[$slug], $slugsBefore), array_column($sentAfter[$document]['lines'], 'ingredientSlug'), $document);
            $this->assertNotSame($slugsBefore, array_column($sentAfter[$document]['lines'], 'ingredientSlug'));
        }
        $this->assertSame($sentBefore['plan']['listVersion'] + 1, $sentAfter['plan']['listVersion']);
        $this->assertSame($activeVersionBefore + 1, (int) $this->entityManager->getConnection()->fetchOne('SELECT version FROM shopping_list WHERE id = ?', [$activeList->getId()]));
    }

    /**
     * Les choix du compte, un par ligne (sauf le dernier : une ligne « nouvelle »), avec des produits acceptés et refusés, une contenance, une
     * recherche mémorisée et des prix.
     *
     * @param list<string> $slugs
     */
    private function saveChoices(string $userId, array $slugs): void
    {
        $products = [];
        $choices = [];
        foreach (array_slice($slugs, 0, -1) as $index => $slug) {
            $first = (string) (700000 + $index * 10);
            $second = (string) (700000 + $index * 10 + 1);
            $rejected = (string) (700000 + $index * 10 + 2);
            $products[] = new ProductBuilder()->withRetailerProductId($first)->withLabel("Produit $first")->withPrice(120 + $index * 15, 240 + $index)->build();
            $products[] = new ProductBuilder()->withRetailerProductId($second)->withLabel("Produit $second")->withPrice(99)->build();
            $products[] = new ProductBuilder()->withRetailerProductId($rejected)->withLabel("Produit refusé $rejected")->build();
            $builder = new ChoiceBuilder()->withUserId($userId)->withIngredientSlug($slug)
                ->withAccepted([$this->productId($first), $this->productId($second)])
                ->withRejected([$this->productId($rejected)]);
            if ($index % 2 === 0) {
                $builder->withSearchQuery("recherche $index")->withPackSize($this->productId($first), 20, 'unite');
            }
            $choices[] = $builder->build();
        }
        static::getContainer()->get(ProductRepository::class)->saveAll($products);
        static::getContainer()->get(ChoiceRepository::class)->saveAll($choices);
    }

    private function productId(string $retailerProductId): string
    {
        return Product::idFor(Retailer::LECLERC, '123456', $retailerProductId);
    }

    /**
     * Le plan ou l'estimation sans ce qui change légitimement : le slug de chaque ligne, la version de la liste et l'horodatage.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $document): array
    {
        unset($document['generatedAt'], $document['listVersion']);
        if (isset($document['lines'])) {
            $document['lines'] = array_map(static function (array $line): array {
                unset($line['ingredientSlug']);

                return $line;
            }, $document['lines']);
        }

        return $document;
    }
}
