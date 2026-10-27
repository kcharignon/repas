<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

interface ConversionRepository
{
    public function findById(string $id): ?Conversion;

    /**
     * Les conversions propres de l'ingrédient PUIS les universelles (ordre stable : à longueur égale, le parcours du graphe préfère la
     * conversion de l'ingrédient à l'universelle qui dirait autre chose).
     *
     * @return Tab<Conversion>
     */
    public function findByIngredientOrCommon(Ingredient $ingredient): Tab;

    /**
     * La conversion PROPRE de l'ingrédient pour cette paire d'unités, `null` sans elle. Jamais une conversion universelle (sans ingrédient) :
     * celui qui modifie la conversion d'un ingrédient ne doit pas pouvoir la prendre pour la sienne, ce qui la ferait disparaître pour tous
     * les autres (2026-10-27).
     */
    public function findByIngredientAndStartUnitAndEndUnit(Ingredient $ingredient, Unit $startUnit, Unit $endUnit): ?Conversion;

    /**
     * @return Tab<Conversion>
     */
    public function findAll(): Tab;

    public function save(Conversion $conversion): void;

    /**
     * Retire UNE conversion propre à un ingrédient (jamais une universelle : l'appelant l'a déjà écarté).
     */
    public function delete(Conversion $conversion): void;

    public function deleteByIngredient(Ingredient $ingredient): void;

    /**
     * Supprime les conversions des ingrédients personnalisés d'un créateur (suppression d'un compte, ADR 0021). À appeler
     * avant `IngredientRepository::deleteByCreator()`.
     */
    public function deleteByIngredientCreator(string $creatorId): void;

    /**
     * @return Tab<Conversion>
     */
    public function findByIngredient(Ingredient $ingredient): Tab;
}
