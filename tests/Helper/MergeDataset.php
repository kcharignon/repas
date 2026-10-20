<?php

namespace Repas\Tests\Helper;


use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Model\User;

/**
 * De quoi fabriquer, pour les tests de la fusion de deux ingrédients (ING-005), un utilisateur qui a deux ingrédients voisins (« Tomate »,
 * gardé, et « Tomates », absorbé), leurs conversions, des recettes, des listes de courses de tous les statuts et des choix du drive, avec
 * des identifiants et des quantités CHOISIS par le test (il les relit pour prouver ce que la fusion a fait). Les ingrédients, unités et
 * rayons passent par les modèles du domaine (les lectures et les écritures du cas d'usage les relisent par leurs ports) ; les recettes,
 * les listes et les choix sont écrits en SQL direct. Valeurs factices uniquement : aucun nom d'ingrédient personnel, aucun numéro de
 * magasin réel.
 */
final class MergeDataset
{
    public Connection $connection;

    private AdoptionDataset $sql;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataPersister $persister,
    ) {
        $this->connection = $entityManager->getConnection();
        $this->sql = new AdoptionDataset($entityManager, $persister);
    }

    public function user(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    /**
     * Un ingrédient personnel de `$owner`, au kilo par défaut (cuisiné au gramme), dans le rayon des céréales.
     */
    public function ingredient(string $name, User $owner, ?UnitBuilder $purchase = null, ?DepartmentBuilder $department = null, ?string $adoptedFrom = null): Ingredient
    {
        $ingredient = new IngredientBuilder()
            ->withName($name)
            ->withDepartment($department ?? new DepartmentBuilder()->isCereal())
            ->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit($purchase ?? new UnitBuilder()->isKilo())
            ->withCreator($owner, true)
            ->withAdoptedFrom($adoptedFrom)
            ->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    /**
     * Les unités et la conversion commune dont les scénarios ont besoin : kilo <-> gramme, et le nom des unités qui ne mènent nulle part.
     */
    public function units(): void
    {
        foreach ([new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isUnite(), new UnitBuilder()->isSlice(), new UnitBuilder()->isLiter()] as $unit) {
            $this->persister->saveUnit($unit->build());
        }
        if ($this->count('conversion', 'ingredient IS NULL AND start_unit = ? AND end_unit = ?', ['kilo', 'gramme']) === 0) {
            $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        }
    }

    /**
     * Une conversion PROPRE à l'ingrédient (`unite` -> `gramme` par défaut).
     */
    public function conversion(Ingredient $ingredient, float $coefficient = 150.0, ?UnitBuilder $from = null, ?UnitBuilder $to = null): void
    {
        $this->persister->saveConversion(
            new ConversionBuilder()->withIngredient($ingredient)->withStartUnit($from ?? new UnitBuilder()->isUnite())->withEndUnit($to ?? new UnitBuilder()->isGramme())->withCoefficient($coefficient)->build(),
        );
    }

    /**
     * @param list<array{0: string, 1: float, 2: string}> $rows `[slug de l'ingrédient, quantité, unité]`
     */
    public function recipe(string $id, string $author, array $rows, int $version = 3): void
    {
        $this->sql->insert('recipe', ['id' => $id, 'name' => 'Recette ' . $id, 'slug' => $id . '-' . $author, 'serving' => 4, 'author' => $author, 'type' => 'plat', 'orignal' => null, 'version' => $version]);
        foreach ($rows as $position => [$ingredient, $quantity, $unit]) {
            $this->sql->insert('recipe_row', ['id' => $id . '-row-' . $position, 'ingredient' => $ingredient, 'quantity' => $quantity, 'unit' => $unit, 'recipe' => $id, 'position' => $position]);
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: float, 3: string, 4?: bool}> $rows `[identifiant, slug de l'ingrédient, quantité, unité, coché]`
     * @param list<array{0: string, 1: string, 2: float, 3: string}> $items `[identifiant, slug de l'ingrédient, quantité (pas), unité]`
     */
    public function shoppingList(string $id, string $owner, string $status = 'ACTIVE', array $rows = [], array $items = [], int $version = 5): void
    {
        $this->sql->insert('shopping_list', ['id' => $id, 'owner' => $owner, 'created_at' => '2026-10-01 10:00:00', 'status' => $status, 'name' => 'Liste ' . $id, 'sent_at' => $status === 'SENT' ? '2026-10-10 12:00:00' : null, 'version' => $version]);
        foreach ($rows as $row) {
            $this->sql->insert('shopping_list_row', ['id' => $row[0], 'shopping_list' => $id, 'ingredient' => $row[1], 'quantity' => $row[2], 'unit' => $row[3], 'checked' => ($row[4] ?? false) ? 'true' : 'false']);
        }
        foreach ($items as [$itemId, $ingredient, $quantity, $unit]) {
            $this->sql->insert('shopping_list_ingredient', ['id' => $itemId, 'shopping_list' => $id, 'ingredient' => $ingredient, 'unit' => $unit, 'quantity' => $quantity]);
        }
    }

    /**
     * Un choix du drive avec deux produits acceptés (dont l'un avec une contenance) et un refusé.
     */
    public function choice(string $id, string $userId, string $ingredientSlug, string $store = '123456', string $retailer = Retailer::LECLERC->value, ?string $searchQuery = null): string
    {
        $this->sql->insert('drive_choice', ['id' => $id, 'user_id' => $userId, 'retailer' => $retailer, 'store_id' => $store, 'ingredient_slug' => $ingredientSlug, 'kind' => 'PRODUCTS', 'search_query' => $searchQuery, 'updated_at' => '2026-10-18 10:00:00']);
        $this->sql->insert('drive_choice_product', ['choice_id' => $id, 'product_id' => $id . '-p1', 'verdict' => 'ACCEPTED', 'rank' => 1, 'pack_size_value' => '20.000', 'pack_size_unit_slug' => 'unite']);
        $this->sql->insert('drive_choice_product', ['choice_id' => $id, 'product_id' => $id . '-p2', 'verdict' => 'ACCEPTED', 'rank' => 2, 'pack_size_value' => null, 'pack_size_unit_slug' => null]);
        $this->sql->insert('drive_choice_product', ['choice_id' => $id, 'product_id' => $id . '-p3', 'verdict' => 'REJECTED', 'rank' => null, 'pack_size_value' => null, 'pack_size_unit_slug' => null]);

        return $id;
    }

    /**
     * Le volume d'un utilisateur qui fusionne : des recettes (la moitié contient aussi le gardé), des listes (une ligne ou un ajout à la main de
     * l'absorbé, la moitié a aussi le gardé), un choix du drive pour l'absorbé dans trois magasins dont un où le gardé a le sien.
     * Aucun refus : ni liste envoyée, ni unité qui manque.
     */
    public function volume(User $owner, Ingredient $kept, Ingredient $absorbed, int $recipes, int $lists): void
    {
        $a = $kept->getSlug();
        $b = $absorbed->getSlug();
        $prefix = 'volume-' . substr($owner->getId(), 0, 8) . '-';
        for ($i = 0; $i < $recipes; ++$i) {
            $this->recipe("{$prefix}recipe-$i", $owner->getId(), $i % 2 === 0 ? [[$b, 100.0, 'gramme'], [$a, 50.0, 'gramme']] : [[$b, 250.0, 'gramme']]);
        }
        for ($i = 0; $i < $lists; ++$i) {
            $this->shoppingList(
                "{$prefix}list-$i",
                $owner->getId(),
                $i % 4 === 3 ? 'PAUSED' : 'ACTIVE',
                $i % 2 === 0 ? [["{$prefix}b-row-$i", $b, 0.5, 'kilo', true], ["{$prefix}a-row-$i", $a, 1.0, 'kilo', false]] : [["{$prefix}b-row-$i", $b, 0.75, 'kilo', false]],
                [["{$prefix}b-item-$i", $b, 2.0, 'kilo']],
            );
        }
        $this->choice($prefix . 'choice-a', $owner->getId(), $a, '111111');
        $this->choice($prefix . 'choice-b1', $owner->getId(), $b, '111111');
        $this->choice($prefix . 'choice-b2', $owner->getId(), $b, '222222');
        $this->choice($prefix . 'choice-b3', $owner->getId(), $b, '333333', AdoptionDataset::SECOND_RETAILER);
    }

    /**
     * @return list<array{string, string, float, string}> `[identifiant, slug, quantité, unité]`, dans l'ordre des positions
     */
    public function recipeRows(string $recipeId): array
    {
        return array_map(
            static fn(array $row): array => [$row[0], $row[1], (float) $row[2], $row[3]],
            $this->connection->fetchAllNumeric('SELECT id, ingredient, quantity, unit FROM recipe_row WHERE recipe = ? ORDER BY position', [$recipeId]),
        );
    }

    /**
     * @return list<array{string, string, float, string, bool}> `[identifiant, slug, quantité, unité, coché]`
     */
    public function listRows(string $listId): array
    {
        return array_map(
            static fn(array $row): array => [$row[0], $row[1], (float) $row[2], $row[3], (bool) $row[4]],
            $this->connection->fetchAllNumeric('SELECT id, ingredient, quantity, unit, checked FROM shopping_list_row WHERE shopping_list = ? ORDER BY id', [$listId]),
        );
    }

    /**
     * @return list<array{string, string, float, string}> `[identifiant, slug, quantité, unité]`
     */
    public function listItems(string $listId): array
    {
        return array_map(
            static fn(array $row): array => [$row[0], $row[1], (float) $row[2], $row[3]],
            $this->connection->fetchAllNumeric('SELECT id, ingredient, quantity, unit FROM shopping_list_ingredient WHERE shopping_list = ? ORDER BY id', [$listId]),
        );
    }

    /**
     * @return array<string, int> la version de chaque recette
     */
    public function recipeVersions(): array
    {
        /** @var array<string, int> */
        return array_map('intval', $this->connection->fetchAllKeyValue('SELECT id, version FROM recipe ORDER BY id'));
    }

    /**
     * @return array<string, int> la version de chaque liste
     */
    public function listVersions(): array
    {
        /** @var array<string, int> */
        return array_map('intval', $this->connection->fetchAllKeyValue('SELECT id, version FROM shopping_list ORDER BY id'));
    }

    /**
     * @return array<string, string> le slug d'ingrédient de chaque choix du drive, par identifiant de choix
     */
    public function choiceSlugs(): array
    {
        /** @var array<string, string> */
        return $this->connection->fetchAllKeyValue('SELECT id, ingredient_slug FROM drive_choice ORDER BY id');
    }

    public function count(string $table, string $where = 'TRUE', array $params = []): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT count(*) FROM %s WHERE %s', $this->connection->quoteSingleIdentifier($table), $where), $params);
    }

    /**
     * Tout ce qui appartient à un utilisateur, avec les versions, en une somme de contrôle : un compte « intact » garde la même valeur.
     */
    public function hashOfUser(string $userId): string
    {
        return $this->sql->hashOfUser($userId);
    }

    /**
     * Une somme de contrôle par table : prouve qu'une fusion refusée n'a rien écrit nulle part.
     *
     * @return array<string, string>
     */
    public function tableHashes(): array
    {
        return $this->sql->tableHashes();
    }
}
