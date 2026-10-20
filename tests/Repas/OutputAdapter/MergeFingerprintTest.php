<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Doctrine\DBAL\Connection;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;

/**
 * La preuve par empreinte de la fusion (ING-005), sur un jeu de données qui ressemble à celui d'un utilisateur réel : avant et après, le CONTENU
 * vu par nom d'ingrédient, par rayon et par statut de liste est celui que la règle de la fusion annonce, calculé ici de façon indépendante du code
 * (une recette garde toutes ses lignes et leurs quantités, une liste a une ligne par ingrédient dont la quantité est la somme, cochée seulement
 * si toutes les lignes l'étaient), et tout ce que la fusion ne doit pas toucher a la même empreinte : les autres recettes et listes (versions
 * comprises), les listes de tous les statuts, les produits mémorisés du drive (contenances, recherches), les choix et les recettes d'un autre
 * utilisateur.
 */
class MergeFingerprintTest extends DatabaseTestCase
{
    public function testTheContentSeenByNameDepartmentAndStatusIsWhatTheRuleAnnouncesAndNothingElseMoves(): void
    {
        // Given
        $dataset = new MergeDataset($this->entityManager, new DataPersister(static::getContainer()));
        $dataset->units();
        $user = $dataset->user();
        $other = $dataset->user();
        $a = $dataset->ingredient('Tomate', $user);
        $b = $dataset->ingredient('Tomates', $user);
        $c = $dataset->ingredient('Concombre', $user);
        $otherB = $dataset->ingredient('Tomates', $other);
        // « Tomate » et « Tomates » savent compter l'unité en grammes (150) ; celle de « Tomates » disparaît avec lui, sans être reportée
        $dataset->conversion($a);
        $dataset->conversion($b, 160.0);
        $dataset->conversion($otherB);
        $u = $user->getId();

        $dataset->recipe('r1', $u, [[$b->getSlug(), 100.0, 'gramme'], [$a->getSlug(), 50.0, 'gramme'], [$b->getSlug(), 2.0, 'unite']]);
        $dataset->recipe('r2', $u, [[$b->getSlug(), 250.0, 'gramme'], [$c->getSlug(), 10.0, 'gramme']]);
        $dataset->recipe('r3', $u, [[$c->getSlug(), 5.0, 'gramme']], version: 11);
        $dataset->recipe('ro', $other->getId(), [[$otherB->getSlug(), 100.0, 'gramme']]);
        $dataset->shoppingList('l1', $u, 'ACTIVE', [['l1a', $b->getSlug(), 0.5, 'kilo', true], ['l1b', $a->getSlug(), 1.0, 'kilo', false], ['l1c', $c->getSlug(), 2.0, 'kilo', true]], [['l1x', $b->getSlug(), 2.0, 'kilo'], ['l1y', $c->getSlug(), 1.0, 'kilo']]);
        $dataset->shoppingList('l2', $u, 'PAUSED', [['l2a', $b->getSlug(), 0.75, 'kilo', true]], [['l2x', $a->getSlug(), 1.0, 'kilo'], ['l2y', $b->getSlug(), 2.0, 'kilo']]);
        $dataset->shoppingList('l3', $u, 'COMPLETED', [['l3a', $c->getSlug(), 1.0, 'kilo', false]], [], version: 9);
        $dataset->shoppingList('l4', $u, 'ACTIVE', [['l4a', $a->getSlug(), 4.0, 'kilo', true]], [], version: 12);
        $dataset->shoppingList('lo', $other->getId(), 'ACTIVE', [['loa', $otherB->getSlug(), 1.0, 'kilo', false]]);
        $leclerc = Retailer::LECLERC->value;
        $dataset->choice('ch-a', $u, $a->getSlug(), '111111', $leclerc, 'tomate ronde');
        $dataset->choice('ch-b1', $u, $b->getSlug(), '111111', $leclerc, 'tomates en grappe');
        $dataset->choice('ch-b2', $u, $b->getSlug(), '222222', $leclerc, 'tomates cerises');
        $dataset->choice('ch-b3', $u, $b->getSlug(), '333333', AdoptionDataset::SECOND_RETAILER);
        $dataset->choice('ch-c', $u, $c->getSlug(), '111111', $leclerc);
        $dataset->choice('ch-o', $other->getId(), $otherB->getSlug(), '111111', $leclerc);
        $this->entityManager->clear();

        $connection = $dataset->connection;
        $before = $this->content($connection, $u, 'Tomates', 'Tomate');
        $versionsBefore = ['recipes' => $dataset->recipeVersions(), 'lists' => $dataset->listVersions()];
        $otherBefore = $dataset->hashOfUser($other->getId());
        $statusBefore = $connection->fetchAllKeyValue("SELECT id, status || '|' || coalesce(sent_at::text, '') FROM shopping_list ORDER BY id");
        $namesBefore = $connection->fetchFirstColumn('SELECT DISTINCT name FROM ingredient WHERE creator = ? ORDER BY name', [$u]);
        $movedProducts = $connection->fetchAllAssociative("SELECT * FROM drive_choice_product WHERE choice_id IN ('ch-b2', 'ch-b3') ORDER BY choice_id, product_id");
        $keptProducts = $connection->fetchAllAssociative("SELECT * FROM drive_choice_product WHERE choice_id IN ('ch-a', 'ch-c') ORDER BY choice_id, product_id");

        // When
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new MergeIngredientsCommand($u, $b->getSlug(), $a->getSlug()));
        $this->entityManager->clear();

        // Then : le contenu vu par NOM (« Tomates » s'appelle désormais « Tomate »), tel que la règle l'annonce
        $this->assertSame($before, $this->content($connection, $u));
        // les recettes concernées ont monté d'un cran, pas les autres ; les listes concernées aussi, pas les autres
        $this->assertSame(['r1' => 4, 'r2' => 4, 'r3' => 11, 'ro' => 3], $dataset->recipeVersions());
        $this->assertSame(['l1' => 6, 'l2' => 6, 'l3' => 9, 'l4' => 12, 'lo' => 5], $dataset->listVersions());
        $this->assertSame(array_keys($versionsBefore['recipes']), array_keys($dataset->recipeVersions()));
        // le statut et la date d'envoi de chaque liste n'ont pas bougé
        $this->assertSame($statusBefore, $connection->fetchAllKeyValue("SELECT id, status || '|' || coalesce(sent_at::text, '') FROM shopping_list ORDER BY id"));
        // les ingrédients : « Tomates » a disparu, rien d'autre, aucune conversion des autres n'a bougé
        $this->assertSame(array_values(array_diff($namesBefore, ['Tomates'])), $connection->fetchFirstColumn('SELECT DISTINCT name FROM ingredient WHERE creator = ? ORDER BY name', [$u]));
        $this->assertSame(0, $dataset->count('conversion', 'ingredient = ?', [$b->getSlug()]));
        $this->assertSame([150.0], array_map('floatval', $connection->fetchFirstColumn('SELECT coefficient FROM conversion WHERE ingredient = ?', [$a->getSlug()])), 'celle de « Tomate » est intacte, celle de « Tomates » n\'a pas été reportée');
        $this->assertSame(1, $dataset->count('conversion', 'ingredient = ?', [$otherB->getSlug()]));
        // le drive : le choix de « Tomate » est resté, ceux de « Tomates » du premier magasin supprimés avec leurs produits, les autres sont passés sur
        // « Tomate » avec leurs produits, contenances et recherches
        $this->assertSame(['ch-a' => $a->getSlug(), 'ch-b2' => $a->getSlug(), 'ch-b3' => $a->getSlug(), 'ch-c' => $c->getSlug(), 'ch-o' => $otherB->getSlug()], $dataset->choiceSlugs());
        $this->assertSame($movedProducts, $connection->fetchAllAssociative("SELECT * FROM drive_choice_product WHERE choice_id IN ('ch-b2', 'ch-b3') ORDER BY choice_id, product_id"));
        $this->assertSame($keptProducts, $connection->fetchAllAssociative("SELECT * FROM drive_choice_product WHERE choice_id IN ('ch-a', 'ch-c') ORDER BY choice_id, product_id"));
        $this->assertSame(0, $dataset->count('drive_choice_product', "choice_id = 'ch-b1'"));
        $this->assertSame(['tomate ronde', 'tomates cerises', null], [
            $connection->fetchOne("SELECT search_query FROM drive_choice WHERE id = 'ch-a'"),
            $connection->fetchOne("SELECT search_query FROM drive_choice WHERE id = 'ch-b2'"),
            $connection->fetchOne("SELECT search_query FROM drive_choice WHERE id = 'ch-b3'"),
        ]);
        $this->assertSame('20.000', $connection->fetchOne("SELECT pack_size_value::text FROM drive_choice_product WHERE choice_id = 'ch-b2' AND rank = 1"));
        // un autre utilisateur n'a pas bougé d'un octet
        $this->assertSame($otherBefore, $dataset->hashOfUser($other->getId()));
    }

    /**
     * Le contenu d'un utilisateur vu par NOM et par rayon, sans aucun slug, tel qu'un lecteur le voit. Avec `$absorbedName`, le contenu « attendu » :
     * ce nom devient `$keptName`, et la règle de la fusion est appliquée à la main (une ligne de liste par ingrédient et par liste, les quantités
     * s'ajoutent, cochée seulement si toutes l'étaient ; un ajout à la main par ingrédient, unité et liste, les pas s'ajoutent).
     *
     * @return array<string, mixed>
     */
    private function content(Connection $connection, string $userId, ?string $absorbedName = null, ?string $keptName = null): array
    {
        $rename = static fn(string $name): string => $name === $absorbedName ? (string) $keptName : $name;

        $recipes = [];
        foreach ($connection->fetchAllNumeric(
            'SELECT x.recipe, i.name, d.name, x.quantity, x.unit FROM recipe_row x JOIN recipe r ON r.id = x.recipe AND r.author = ? JOIN ingredient i ON i.slug = x.ingredient JOIN department d ON d.slug = i.department',
            [$userId],
        ) as [$recipe, $name, $department, $quantity, $unit]) {
            $recipes[$recipe][] = [$rename($name), $department, (float) $quantity, $unit];
        }

        $lists = [];
        foreach ($connection->fetchAllNumeric(
            'SELECT x.shopping_list, i.name, d.name, x.unit, x.quantity, x.checked FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = ? JOIN ingredient i ON i.slug = x.ingredient JOIN department d ON d.slug = i.department',
            [$userId],
        ) as [$list, $name, $department, $unit, $quantity, $checked]) {
            $key = $rename($name) . '|' . $department . '|' . $unit;
            $current = $lists[$list]['rows'][$key] ?? ['quantity' => 0.0, 'checked' => true];
            $lists[$list]['rows'][$key] = ['quantity' => $current['quantity'] + (float) $quantity, 'checked' => $current['checked'] && (bool) $checked];
        }
        foreach ($connection->fetchAllNumeric(
            'SELECT x.shopping_list, i.name, d.name, x.unit, x.quantity FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = ? JOIN ingredient i ON i.slug = x.ingredient JOIN department d ON d.slug = i.department',
            [$userId],
        ) as [$list, $name, $department, $unit, $quantity]) {
            $key = $rename($name) . '|' . $department . '|' . $unit;
            $lists[$list]['items'][$key] = ($lists[$list]['items'][$key] ?? 0.0) + (float) $quantity;
        }

        foreach ($recipes as &$rows) {
            usort($rows, static fn(array $x, array $y): int => $x <=> $y);
        }
        unset($rows);
        ksort($recipes);
        foreach ($lists as &$content) {
            ksort($content['rows']);
            if (isset($content['items'])) {
                ksort($content['items']);
            }
        }
        unset($content);
        ksort($lists);

        return ['recipes' => $recipes, 'lists' => $lists];
    }
}
