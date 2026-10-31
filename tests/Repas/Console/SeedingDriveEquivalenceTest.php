<?php

namespace Repas\Tests\Repas\Console;


use DateTimeImmutable;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Infrastructure\Loader\IngredientSeedingReportPostgreSQLLoader;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le test d'équivalence du drive pour le RATTRAPAGE (ING-008, comme `AdoptionDriveEquivalenceTest` pour ING-003) : un compte qui a des choix,
 * une liste envoyée et une liste active sur des ingrédients du catalogue — dont un qui n'est PAS « de base » — reçoit ses ingrédients ; le plan
 * d'envoi (`GetDrivePlan`) et l'estimation du coût (`GetDriveEstimate`) donnent, avant et après, EXACTEMENT les mêmes lignes à l'ingrédient près
 * (nom, quantité, unité, produits et leur rang, nombre à commander, contenance, recherche mémorisée, prix) : seul le slug de chaque ligne
 * change, et c'est celui de la copie du compte.
 *
 * Ce sont les vraies requêtes du drive, sur les vraies listes, avec des ingrédients construits par les builders du domaine.
 */
class SeedingDriveEquivalenceTest extends WriteBudgetTestCase
{
    public function testThePlanAndTheEstimateOfASentAndAnActiveListAreTheSameBeforeAndAfterTheCatchUp(): void
    {
        // Given : le propriétaire du catalogue, et un compte avec trois recettes (ingrédients du catalogue), une liste ENVOYÉE et une liste active,
        // des choix de produits pour la plupart des lignes
        $owner = new UserBuilder()->isCatalogOwner()->build();
        $this->persister->saveUser($owner);
        $user = $this->savedUser();
        $recipes = $this->typicalRecipes($user, ownedByAuthor: false);
        $sentList = $this->savedShoppingList($user, $recipes, null, static function (ShoppingList $list): void {
            $list->send(new DateTimeImmutable('2026-10-10 12:00:00'));
        });
        $activeList = $this->savedShoppingList($user, $recipes);
        $this->savedDriveSettings($user);
        // Les ingrédients du catalogue sont ceux du propriétaire, d'où le rattrapage les copie
        $this->giveDefaultIngredientsToTheCatalogOwner($owner->getId());
        $connection = $this->entityManager->getConnection();
        $slugs = $connection->fetchFirstColumn('SELECT ingredient FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$sentList->getId()]);
        $this->assertGreaterThanOrEqual(5, count($slugs));
        $this->saveChoices($user->getId(), $slugs);
        // Le classement : tous les ingrédients du catalogue sont « de base » sauf le premier des ingrédients de la liste (il sera copié parce que les lignes le référencent)
        $connection->executeStatement('UPDATE ingredient SET is_base = TRUE WHERE creator = ?', [$owner->getId()]);
        $connection->executeStatement('UPDATE ingredient SET is_base = FALSE WHERE slug = ?', [$slugs[0]]);

        $bus = static::getContainer()->get(QueryBusInterface::class);
        /** @return array{plan: ?array<string, mixed>, estimate: array<string, mixed>} */
        $ask = static fn(string $listId, bool $withPlan): array => [
            'plan' => $withPlan ? json_decode(json_encode($bus->ask(new GetDrivePlanQuery($user->getId(), $listId))), true) : null,
            'estimate' => json_decode(json_encode($bus->ask(new GetDriveEstimateQuery($user->getId(), $listId))), true),
        ];
        $sentBefore = $ask($sentList->getId(), true);
        $activeBefore = $ask($activeList->getId(), false);
        $activeVersionBefore = (int) $connection->fetchOne('SELECT version FROM shopping_list WHERE id = ?', [$activeList->getId()]);
        $this->assertGreaterThanOrEqual(3, $sentBefore['plan']['counts']['known'], 'le jeu d\'essai a des produits mémorisés, sinon la preuve ne prouve rien');
        $this->assertGreaterThan(0, $sentBefore['estimate']['estimatedLines']);

        // When : le rattrapage de ce compte seul
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:seed'));
        $status = $tester->execute(['--apply' => true, '--accept-sent' => true, '--user' => [IngredientSeedingReportPostgreSQLLoader::referenceOf($user->getId())]]);
        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->entityManager->clear();

        // Then : les mêmes lignes à l'ingrédient près, pour la liste envoyée comme pour la liste active
        $sentAfter = $ask($sentList->getId(), true);
        $activeAfter = $ask($activeList->getId(), false);
        foreach (['plan', 'estimate'] as $document) {
            $this->assertSame(self::normalize($sentBefore[$document]), self::normalize($sentAfter[$document]), "$document de la liste envoyée");
        }
        $this->assertSame(self::normalize($activeBefore['estimate']), self::normalize($activeAfter['estimate']), 'estimation de la liste active');

        // Seul le slug de chaque ligne a changé : c'est celui de la copie du compte (même pour l'ingrédient qui n'est pas « de base »), la version de la liste a monté d'un cran
        $copies = $connection->fetchAllKeyValue("SELECT alias_slug, ingredient_slug FROM ingredient_alias WHERE user_id = ? AND kind = 'adoption'", [$user->getId()]);
        $this->assertArrayHasKey($slugs[0], $copies, 'l\'ingrédient non « de base » référencé par ses lignes est copié aussi');
        foreach (['plan', 'estimate'] as $document) {
            $slugsBefore = array_column($sentBefore[$document]['lines'], 'ingredientSlug');
            $this->assertNotEmpty($slugsBefore);
            $this->assertSame(array_map(static fn(string $slug): string => $copies[$slug], $slugsBefore), array_column($sentAfter[$document]['lines'], 'ingredientSlug'), $document);
            $this->assertNotSame($slugsBefore, array_column($sentAfter[$document]['lines'], 'ingredientSlug'));
        }
        $this->assertSame($sentBefore['plan']['listVersion'] + 1, $sentAfter['plan']['listVersion']);
        $this->assertSame($activeVersionBefore + 1, (int) $connection->fetchOne('SELECT version FROM shopping_list WHERE id = ?', [$activeList->getId()]));
        $this->assertSame('SENT', $connection->fetchOne('SELECT status FROM shopping_list WHERE id = ?', [$sentList->getId()]));
    }

    /**
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
