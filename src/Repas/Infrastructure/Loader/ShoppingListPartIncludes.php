<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowIncludeDTO;
use Repas\Repas\Domain\Model\PartShare;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Unit;

/**
 * Ce que chaque ligne de parent d'une liste contient en parties (ADR 0038 décision 15) : le registre caché de la liste (`shopping_list.part_shares`,
 * lu avec la liste) et, en UNE lecture, le nom et le symbole d'unité d'achat de ses parties. Aucune lecture quand le registre est vide, c'est-à-dire
 * pour toute liste d'un compte qui n'a pas découpé d'ingrédient. Le registre est du JSON gardé en base : il se lit tolérant (`PartShare::registryFrom`),
 * et une partie dont l'ingrédient n'existe plus n'est tout simplement pas dite.
 */
final readonly class ShoppingListPartIncludes
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param mixed $storedShares le registre tel que la liste le garde (`ShoppingList::$partShares` de l'entité)
     *
     * @return array<string, list<ShoppingListRowIncludeDTO>> par slug de parent, chaque liste triée par nom de partie
     */
    public function byParent(mixed $storedShares): array
    {
        $shares = PartShare::registryFrom($storedShares);
        if ($shares === []) {
            return [];
        }

        $parts = $this->entityManager->createQueryBuilder()
            ->select('i.slug AS slug', 'i.name AS name', 'u.symbol AS unitSymbol')
            ->from(Ingredient::class, 'i')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = i.defaultPurchaseUnitSlug')
            ->where('i.slug IN (:slugs)')
            ->setParameter('slugs', array_keys($shares))
            ->getQuery()
            ->getArrayResult();

        $byParent = [];
        foreach ($parts as $part) {
            $share = $shares[$part['slug']];
            $byParent[$share->parentSlug][] = [$part['slug'], new ShoppingListRowIncludeDTO($part['name'], $share->quantity, $part['unitSymbol'])];
        }

        // Triées par nom, puis par identifiant pour un ordre qui ne dépend de rien d'autre
        return array_map(static function (array $included): array {
            usort($included, static fn(array $a, array $b): int => [mb_strtolower($a[1]->name), $a[0]] <=> [mb_strtolower($b[1]->name), $b[0]]);

            return array_map(static fn(array $entry): ShoppingListRowIncludeDTO => $entry[1], $included);
        }, $byParent);
    }
}
