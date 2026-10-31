<?php

namespace Repas\Repas\Domain\Interface;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\AdoptionRepointed;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Model\IngredientToSeed;

/**
 * Les écritures EN MASSE de l'amorçage d'un compte (ADR 0028, ING-003 puis ING-008, `docs/ingredients/migration.md` § 7) : quelques
 * requêtes SQL par utilisateur, quel que soit le nombre d'ingrédients ou de lignes, sans hydrater un seul agrégat. Elles copient les
 * ingrédients du catalogue chez le compte (même `adopted_from`, conversions PROPRES seulement, alias `adoption`), sans événement ni
 * statistique, et ne touchent jamais à l'ingrédient d'origine.
 *
 * Toutes s'exécutent dans la transaction du bus de commandes : un compte est amorcé en entier ou pas du tout. `lockSeeding()` est la
 * première, elle sérialise deux lancements sur le même compte.
 */
interface IngredientAdoptionBulkRepository
{
    /**
     * Verrou consultatif de la transaction (`pg_advisory_xact_lock`) propre à cet utilisateur : deux lancements ne se croisent pas.
     */
    public function lock(string $userId): void;

    /**
     * Les slugs d'ingrédients déjà pris qui se terminent par l'identifiant de l'utilisateur (ses ingrédients personnels et ses copies) :
     * de quoi choisir un slug libre pour chaque copie sans interroger la base ingrédient par ingrédient.
     *
     * @return list<string>
     */
    public function takenSlugs(string $userId): array;

    /**
     * Écrit les alias d'adoption `[slug de l'origine => slug de la copie]` avec la source du lancement. L'alias arbitre une adoption
     * simultanée : une origine dont l'utilisateur a entre-temps un alias valide n'est pas réécrite. Un alias orphelin est remplacé sur
     * place (jamais supprimé). À appeler AVANT `copyCatalogIngredients()`.
     *
     * @param array<string, string> $copySlugByCommonSlug
     *
     * @return array<string, string> les adoptions réellement écrites par cet appel, sous-ensemble de celles demandées
     */
    public function writeAliases(string $userId, array $copySlugByCommonSlug, string $source, DateTimeImmutable $now): array;

    /**
     * Les identifiants des conversions PROPRES de ces ingrédients (celles rattachées à l'ingrédient ; les conversions communes à tous ne
     * se copient pas), avec le slug de l'ingrédient qui les porte.
     *
     * @param list<string> $commonSlugs
     *
     * @return list<array{id: string, commonSlug: string}>
     */
    public function ownConversionsOf(array $commonSlugs): array;

    /**
     * Copie des conversions propres vers les copies : chaque entrée donne l'identifiant de la conversion d'origine, le NOUVEL identifiant
     * et le slug de la copie. Unités et coefficient sont recopiés par la base (aucun arrondi). Une requête.
     *
     * @param list<array{sourceId: string, newId: string, copySlug: string}> $copies
     */
    public function copyConversions(array $copies): int;

    /**
     * Les adoptions valides de l'utilisateur `[slug de l'origine => slug de sa copie]` (alias d'adoption dont la copie existe et lui
     * appartient) : la table de correspondance qui sert à repointer les lignes et les choix du drive.
     *
     * @return array<string, string>
     */
    public function adoptions(string $userId): array;

    /**
     * Repointe, pour l'utilisateur, chaque ligne de ses recettes et de ses listes qui référence une origine adoptée vers sa copie, et
     * augmente de 1 la version de chaque recette et liste modifiée (aucune autre). Les listes envoyées au drive comprises : l'appelant
     * a vérifié l'acceptation. Deux requêtes, qui doivent aller ensemble : une recette sans ses lignes de liste fausserait la
     * planification.
     */
    public function repointToCopies(string $userId): AdoptionRepointed;

    /**
     * L'empreinte de l'utilisateur. `$watchedSlugs` désigne les ingrédients dont on attend le repointage (leurs recettes et listes
     * doivent voir leur version monter).
     *
     * @param list<string> $watchedSlugs
     */
    public function snapshot(string $userId, array $watchedSlugs): AdoptionSnapshot;

    /**
     * Les ingrédients dont la copie, écrite par ce lancement (`$adoptions`), n'est PAS conforme à l'origine : copie absente, créateur,
     * `adopted_from`, nom, image, rayon, unités par défaut, unités compatibles, nombre de conversions propres. Attendu : aucun.
     *
     * @param array<string, string> $adoptions `[slug de l'origine => slug de la copie]`
     *
     * @return list<string> les slugs des origines en défaut
     */
    public function nonConformingCopies(string $userId, array $adoptions): array;

    /**
     * Verrou consultatif de la transaction propre à l'AMORÇAGE de cet utilisateur (clé distincte de `lock()`) : deux activations
     * simultanées du même compte ne se croisent pas, la seconde voit un compte déjà amorcé.
     */
    public function lockSeeding(string $userId): void;

    /**
     * Le compte est AMORCÉ : il a au moins un alias d'adoption (`IngredientAliasRepository::isAdopter()`, même définition, comparées par un
     * test). Un compte qui a supprimé toutes ses copies ne l'est plus.
     */
    public function isSeeded(string $userId): bool;

    /**
     * Ce que le compte doit recevoir, trié par slug : les ingrédients du catalogue du propriétaire (créateur `$ownerId`, ou vide tant que
     * la passation n'est pas faite : `sans-commun.md` § 2.2, écrit à un seul endroit, `IngredientVisibility::isCatalogSql()`) qui portent le
     * marqueur « de base », PLUS ceux de ce catalogue que les lignes du compte référencent (recettes dont il est l'auteur, listes dont il est
     * le propriétaire, et `$driveReferencedSlugs`, les ingrédients de ses choix du drive), sauf ceux qu'il a déjà adoptés (alias d'adoption
     * VALIDE : la copie existe et lui appartient). Un marqueur posé sur l'ingrédient d'un autre compte est inerte.
     *
     * @param list<string> $driveReferencedSlugs les slugs que `DriveIngredientReferences::referencedSlugs()` rend pour ce compte
     *
     * @return list<IngredientToSeed>
     */
    public function ingredientsToSeed(string $userId, string $ownerId, array $driveReferencedSlugs): array;

    /**
     * Crée la copie de chaque ingrédient du catalogue `[slug de l'origine => slug de la copie]` : mêmes nom, image, rayon, unités par
     * défaut et unités compatibles, `creator` = l'utilisateur, `adopted_from` = l'origine ; la copie naît avec le marqueur « de base » à
     * FAUX, écrit explicitement. Une requête.
     *
     * @param array<string, string> $copySlugBySourceSlug
     */
    public function copyCatalogIngredients(string $userId, string $ownerId, array $copySlugBySourceSlug): int;
}
