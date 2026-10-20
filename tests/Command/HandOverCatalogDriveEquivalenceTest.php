<?php

namespace Repas\Tests\Command;


use DateTimeImmutable;
use Repas\Drive\Application\Query\GetDriveEstimate\GetDriveEstimateQuery;
use Repas\Drive\Application\Query\GetDrivePlan\GetDrivePlanQuery;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptCommonIngredientsForUserCommand;
use Repas\Repas\Domain\Interface\BaseIngredientClassification;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\BaseIngredientClassificationInMemory;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\ProductBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\WriteBudgetTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Le test d'équivalence du drive pour la PASSATION (ING-009, comme `AdoptionDriveEquivalenceTest` pour ING-003 et `SeedingDriveEquivalenceTest`
 * pour ING-008) : le plan d'envoi (`GetDrivePlan`) et l'estimation du coût (`GetDriveEstimate`) du propriétaire du catalogue ET d'un compte déjà
 * migré donnent, avant et après la passation, EXACTEMENT les mêmes lignes — nom, quantité, unité, produits et leur rang, nombre à commander,
 * contenance, recherche mémorisée, prix, et jusqu'au slug de chaque ligne : la passation ne repointe rien. Ce sont les vraies requêtes du
 * drive, sur les vraies listes (dont une ENVOYÉE), avec des ingrédients construits par les builders du domaine.
 */
class HandOverCatalogDriveEquivalenceTest extends WriteBudgetTestCase
{
    public function testThePlanAndTheEstimateOfTheOwnerAndOfAMigratedAccountAreIdenticalBeforeAndAfterTheHandOver(): void
    {
        // Given : le propriétaire du catalogue et un compte migré, chacun avec trois recettes, une liste ENVOYÉE et une liste active sur les anciens
        // communs, des réglages du drive et des choix de produits
        $owner = new UserBuilder()->isCatalogOwner()->build();
        $this->persister->saveUser($owner);
        $migrated = $this->savedUser();
        $subjects = [];
        foreach ([$owner, $migrated] as $user) {
            $recipes = $this->typicalRecipes($user);
            $sent = $this->savedShoppingList($user, $recipes, null, static function (ShoppingList $list): void {
                $list->send(new DateTimeImmutable('2026-10-10 12:00:00'));
            });
            $active = $this->savedShoppingList($user, $recipes);
            $this->savedDriveSettings($user);
            $slugs = $this->entityManager->getConnection()->fetchFirstColumn('SELECT ingredient FROM shopping_list_row WHERE shopping_list = ? ORDER BY ingredient', [$sent->getId()]);
            $this->assertGreaterThanOrEqual(5, count($slugs));
            $this->saveChoices($user->getId(), $slugs);
            $subjects[] = [$user, $sent, $active];
        }
        $connection = $this->entityManager->getConnection();
        // Le compte migré par le vrai cas d'usage d'ING-003 : ses lignes pointent vers ses copies
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new AdoptCommonIngredientsForUserCommand($migrated->getId(), 'migration-essai', true));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE l.owner = ? AND i.creator IS NULL', [$migrated->getId()]));
        // Le classement du fichier est remplacé par celui des anciens communs de ce jeu (tous « de base »), l'interrupteur de l'amorçage est allumé
        $oldCommons = array_map('strval', $connection->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator IS NULL ORDER BY slug'));
        $this->assertGreaterThanOrEqual(5, count($oldCommons));
        static::getContainer()->set(BaseIngredientClassification::class, new BaseIngredientClassificationInMemory(array_fill_keys($oldCommons, true)));
        $this->switchOnIngredientSeeding();

        $bus = static::getContainer()->get(QueryBusInterface::class);
        $ask = static function (string $userId, string $listId, bool $withPlan) use ($bus): array {
            return [
                'plan' => $withPlan ? json_decode(json_encode($bus->ask(new GetDrivePlanQuery($userId, $listId))), true) : null,
                'estimate' => json_decode(json_encode($bus->ask(new GetDriveEstimateQuery($userId, $listId))), true),
            ];
        };
        $before = [];
        foreach ($subjects as $index => [$user, $sent, $active]) {
            $before[$index] = ['sent' => $ask($user->getId(), $sent->getId(), true), 'active' => $ask($user->getId(), $active->getId(), false)];
            $this->assertGreaterThanOrEqual(3, $before[$index]['sent']['plan']['counts']['known'], 'le jeu d\'essai a des produits mémorisés, sinon la preuve ne prouve rien');
            $this->assertGreaterThan(0, $before[$index]['sent']['estimate']['estimatedLines']);
        }
        $versionsBefore = $connection->fetchAllKeyValue('SELECT id, version FROM shopping_list ORDER BY id');

        // When : la passation (le classement, puis l'UPDATE du créateur) par la commande de console
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:hand-over-catalog'));
        $status = $tester->execute(['--apply' => true, '--accept-sent' => true]);
        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM ingredient WHERE creator IS NULL'));
        $this->entityManager->clear();

        // Then : les mêmes documents, au slug près (pour le propriétaire comme pour le compte migré), seul l'horodatage de génération peut changer
        foreach ($subjects as $index => [$user, $sent, $active]) {
            $after = ['sent' => $ask($user->getId(), $sent->getId(), true), 'active' => $ask($user->getId(), $active->getId(), false)];
            foreach (['plan', 'estimate'] as $document) {
                $this->assertSame(self::withoutTimestamp($before[$index]['sent'][$document]), self::withoutTimestamp($after['sent'][$document]), "$document de la liste envoyée, compte $index");
            }
            $this->assertSame(self::withoutTimestamp($before[$index]['active']['estimate']), self::withoutTimestamp($after['active']['estimate']), "estimation de la liste active, compte $index");
            $this->assertSame('SENT', $connection->fetchOne('SELECT status FROM shopping_list WHERE id = ?', [$sent->getId()]));
        }
        // La version de chaque liste n'a pas bougé (aucune ligne repointée)
        $this->assertSame($versionsBefore, $connection->fetchAllKeyValue('SELECT id, version FROM shopping_list ORDER BY id'));
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
     * Le plan ou l'estimation sans l'horodatage de génération.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function withoutTimestamp(array $document): array
    {
        unset($document['generatedAt']);

        return $document;
    }
}
