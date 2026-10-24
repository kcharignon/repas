<?php

namespace Repas\Tests\Helper;


use Doctrine\ORM\EntityManagerInterface;
use Repas\Tests\Helper\Builder\UserBuilder;

/**
 * Le jeu de données de l'amorçage des ingrédients (ING-008) : celui d'`AdoptionDataset` (douze anciens communs sans créateur, un gros compte,
 * un compte de recettes copiées, un compte à un seul ingrédient, le « compte vide » qui n'a qu'une liste et un choix du drive, un compte
 * désactivé, un autre administrateur), plus le PROPRIÉTAIRE du catalogue (l'adresse de `UserBuilder::CATALOG_OWNER_EMAIL`, avec une recette),
 * un compte actif tout neuf et un compte en attente, et le CLASSEMENT : huit des douze anciens communs sont « de base », quatre sont
 * « spécifiques ». `handOver()` joue la passation d'ING-009 (les anciens communs passent au propriétaire, mêmes slugs).
 *
 * Valeurs factices uniquement : aucun nom réel d'ingrédient personnel, aucune adresse réelle.
 */
final class SeedingDataset
{
    /** Les anciens communs « de base » du jeu ; les autres (`ingredient-008` à `ingredient-011`) sont « spécifiques ». */
    public const array BASE = ['beurre', 'couche-taille-4', 'farine', 'lait', 'oeuf', 'sel', 'sucre', 'tomate'];
    public const array SPECIFIC = ['ingredient-008', 'ingredient-009', 'ingredient-010', 'ingredient-011'];

    public string $ownerId = '00000000-0000-4000-8000-0000000000aa';
    public string $freshId = '70000000-0000-4000-8000-000000000007';
    public string $pendingId = '80000000-0000-4000-8000-000000000008';

    private function __construct(
        public readonly AdoptionDataset $adoption,
        private readonly DataPersister $persister,
    ) {
    }

    public static function create(EntityManagerInterface $entityManager, DataPersister $persister, bool $classified = true): self
    {
        $dataset = new self(AdoptionDataset::create($entityManager, $persister), $persister);
        $dataset->persister->saveUser(new UserBuilder()->withId($dataset->ownerId)->isCatalogOwner()->build());
        $dataset->persister->saveUser(new UserBuilder()->withId($dataset->freshId)->build());
        $dataset->persister->saveUser(new UserBuilder()->withId($dataset->pendingId)->isPending()->build());
        // Le propriétaire utilise ses anciens communs : une recette et une liste
        $dataset->adoption->recipe($dataset->ownerId, 'Recette du proprietaire', ['beurre', 'sel']);
        $dataset->adoption->shoppingList($dataset->ownerId, 'ACTIVE', ['beurre'], ['ingredient-008']);
        if ($classified) {
            $dataset->markBase(self::BASE);
        }

        return $dataset;
    }

    /**
     * Les rayons et les unités que les ingrédients du jeu désignent, pour les tests qui lisent par l'API (les lectures joignent ces tables).
     */
    public function referenceData(): void
    {
        foreach ([['rayon-a', 'Rayon A'], ['rayon-b', 'Rayon B']] as [$slug, $name]) {
            $this->adoption->insert('department', ['slug' => $slug, 'name' => $name, 'image' => '']);
        }
        foreach ([['gramme', 'gramme', 'g'], ['kilo', 'kilo', 'kg'], ['unite', 'unité', '']] as [$slug, $name, $symbol]) {
            $this->adoption->insert('unit', ['slug' => $slug, 'name' => $name, 'symbol' => $symbol]);
        }
    }

    /**
     * @param list<string> $slugs
     */
    public function markBase(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->adoption->connection->executeStatement('UPDATE ingredient SET is_base = TRUE WHERE slug = ?', [$slug]);
        }
    }

    /**
     * La passation (ING-009) : les anciens communs passent au propriétaire, les slugs ne changent pas.
     */
    public function handOver(): void
    {
        $this->adoption->connection->executeStatement('UPDATE ingredient SET creator = ? WHERE creator IS NULL', [$this->ownerId]);
    }

    /**
     * Les comptes du jeu qui seraient servis par un rattrapage : actifs, hors propriétaire, non amorcés.
     *
     * @return list<string>
     */
    public function accountsToCatchUp(): array
    {
        return [$this->adoption->bigId, $this->adoption->copierId, $this->adoption->singleId, $this->adoption->emptyId, $this->adoption->adminId, $this->freshId];
    }
}
