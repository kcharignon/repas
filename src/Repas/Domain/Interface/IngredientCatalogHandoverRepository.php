<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\CatalogHandoverState;

/**
 * Les lectures et écritures de la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` § 6) : en SQL direct, quelques requêtes
 * quel que soit le nombre d'ingrédients, jamais un agrégat hydraté (le modèle d'un ingrédient chargé puis enregistré recopierait son marqueur
 * « de base » : ADR 0013 règle 7). Toutes s'exécutent dans la transaction du bus de commandes : `lock()` est la première.
 *
 * La passation ne crée et ne supprime AUCUN ingrédient. Elle n'écrit que `ingredient.is_base` (`classify`, phase 1) et `ingredient.creator`
 * (`assignToOwner`, phase 4), et seulement pour les ingrédients sans créateur.
 */
interface IngredientCatalogHandoverRepository
{
    /**
     * Verrou consultatif de la transaction (`pg_advisory_xact_lock`) propre à la passation : deux lancements ne se croisent pas.
     */
    public function lock(): void;

    /**
     * Les ingrédients sans créateur, triés par slug (ordre binaire). Une requête.
     *
     * @return list<string>
     */
    public function slugsWithoutCreator(): array;

    /**
     * L'état dont dépendent les préconditions (`CatalogHandoverPreconditions`). `$classification` désigne les slugs du fichier de classement.
     *
     * @param array<string, bool> $classification
     */
    public function state(?string $ownerId, array $classification): CatalogHandoverState;

    /**
     * Pose le marqueur « de base » des ingrédients SANS CRÉATEUR d'après le classement `[slug => de base]` ; ne touche à aucun autre
     * ingrédient, ne réécrit pas un marqueur déjà conforme. Une requête.
     *
     * @param array<string, bool> $classification
     *
     * @return int le nombre d'ingrédients dont le marqueur a changé
     */
    public function classify(array $classification): int;

    /**
     * Passe ces ingrédients sans créateur au propriétaire : `creator = $ownerId`, rien d'autre. Un ingrédient qui a déjà un créateur n'est pas
     * touché. Une requête.
     *
     * @param list<string> $slugs
     *
     * @return int le nombre d'ingrédients passés
     */
    public function assignToOwner(string $ownerId, array $slugs): int;

    /**
     * L'empreinte de ces ingrédients : leur contenu (tout sauf `creator` et `is_base`) et leurs conversions propres, trié par slug. Elle doit
     * être identique avant et après la passation. Une requête.
     *
     * @param list<string> $slugs
     */
    public function fingerprint(array $slugs): string;
}
