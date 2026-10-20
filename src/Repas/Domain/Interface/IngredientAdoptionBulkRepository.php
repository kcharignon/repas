<?php

namespace Repas\Repas\Domain\Interface;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\AdoptionRepointed;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Model\CommonIngredientToAdopt;
use Repas\Repas\Domain\Model\UndoableAdoption;

/**
 * Les écritures EN MASSE de la migration d'adoption des communs (ADR 0028, ING-003, `docs/ingredients/migration.md` § 7) : quelques
 * requêtes SQL par utilisateur, quel que soit le nombre de communs ou de lignes, sans hydrater un seul agrégat. Elles produisent les
 * mêmes lignes que `AdoptCommonIngredient` (même `adopted_from`, conversions PROPRES seulement, alias `adoption`), sans événement ni
 * statistique, et ne touchent jamais à un commun.
 *
 * Toutes s'exécutent dans la transaction du bus de commandes : un utilisateur est migré en entier ou pas du tout. `lock()` est la
 * première, elle sérialise deux lancements sur le même utilisateur.
 */
interface IngredientAdoptionBulkRepository
{
    /**
     * Verrou consultatif de la transaction (`pg_advisory_xact_lock`) propre à cet utilisateur : deux lancements ne se croisent pas.
     */
    public function lock(string $userId): void;

    /**
     * L'utilisateur est dans le périmètre de la migration : il existe, il n'est pas administrateur, et il a au moins un ingrédient
     * personnel (`creator`) ou une recette dont il est l'auteur (copies comprises). Relu dans la transaction.
     */
    public function isConcerned(string $userId): bool;

    /**
     * Les communs que l'utilisateur n'a pas (encore) adoptés, triés par slug : ceux qui n'ont pas d'alias d'adoption VALIDE (la copie
     * existe et lui appartient). Un alias orphelin, laissé par le code d'avant ING-001 après un retour arrière, ne compte pas.
     *
     * @return list<CommonIngredientToAdopt>
     */
    public function commonsToAdopt(string $userId): array;

    /**
     * Les slugs d'ingrédients déjà pris qui se terminent par l'identifiant de l'utilisateur (ses ingrédients personnels et ses copies) :
     * de quoi choisir un slug libre pour chaque copie sans interroger la base commun par commun.
     *
     * @return list<string>
     */
    public function takenSlugs(string $userId): array;

    /**
     * Écrit les alias d'adoption `[slug du commun => slug de la copie]` avec la source du lancement. L'alias arbitre une adoption
     * simultanée : un commun dont l'utilisateur a entre-temps un alias valide n'est pas réécrit. Un alias orphelin est remplacé sur place
     * (jamais supprimé). À appeler AVANT `copyIngredients()`.
     *
     * @param array<string, string> $copySlugByCommonSlug
     *
     * @return array<string, string> les adoptions réellement écrites par cet appel, sous-ensemble de celles demandées
     */
    public function writeAliases(string $userId, array $copySlugByCommonSlug, string $source, DateTimeImmutable $now): array;

    /**
     * Crée la copie de chaque commun `[slug du commun => slug de la copie]` : mêmes nom, image, rayon, unités par défaut et unités
     * compatibles, `creator` = l'utilisateur, `adopted_from` = le commun. Une requête.
     *
     * @param array<string, string> $copySlugByCommonSlug
     */
    public function copyIngredients(string $userId, array $copySlugByCommonSlug): int;

    /**
     * Les identifiants des conversions PROPRES de ces communs (celles rattachées à l'ingrédient ; les conversions communes à tous ne se
     * copient pas), avec le slug du commun qui les porte.
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
     * Les adoptions valides de l'utilisateur `[slug du commun => slug de sa copie]` (alias d'adoption dont la copie existe et lui
     * appartient) : la table de correspondance qui sert à repointer les lignes et les choix du drive.
     *
     * @return array<string, string>
     */
    public function adoptions(string $userId): array;

    /**
     * Repointe, pour l'utilisateur, chaque ligne de ses recettes et de ses listes qui référence un commun adopté vers sa copie, et
     * augmente de 1 la version de chaque recette et liste modifiée (aucune autre). Les listes envoyées au drive comprises : l'appelant
     * a vérifié l'acceptation. Deux requêtes, qui doivent aller ensemble : une recette sans ses lignes de liste fausserait la
     * planification.
     */
    public function repointToCopies(string $userId): AdoptionRepointed;

    /**
     * L'empreinte de l'utilisateur. `$watchedSlugs` désigne les ingrédients dont on attend le repointage (leurs recettes et listes
     * doivent voir leur version monter) ; `null` : tous les communs.
     *
     * @param list<string>|null $watchedSlugs
     */
    public function snapshot(string $userId, ?array $watchedSlugs = null): AdoptionSnapshot;

    /**
     * Les communs dont la copie, écrite par ce lancement (`$adoptions`), n'est PAS conforme au commun : copie absente, créateur,
     * `adopted_from`, nom, image, rayon, unités par défaut, unités compatibles, nombre de conversions propres. Attendu : aucun.
     *
     * @param array<string, string> $adoptions `[slug du commun => slug de la copie]`
     *
     * @return list<string> les slugs des communs en défaut
     */
    public function nonConformingCopies(string $userId, array $adoptions): array;

    /**
     * Les adoptions écrites par un lancement (`source`) pour cet utilisateur, avec le signe que la copie a été modifiée depuis.
     *
     * @return list<UndoableAdoption>
     */
    public function adoptionsOfRun(string $userId, string $source): array;

    /**
     * Repointe vers le commun d'origine les lignes de l'utilisateur qui référencent encore ces copies `[slug de la copie => slug du
     * commun]`, mêmes règles de version que `repointToCopies()`. Retour arrière des DONNÉES (`--undo`) : les copies restent.
     *
     * @param array<string, string> $communBySlugOfCopy
     */
    public function repointToCommons(string $userId, array $communBySlugOfCopy): AdoptionRepointed;

    /**
     * Supprime les alias d'adoption de ce lancement pour ces communs (le retour arrière rend le commun visible de nouveau). Les copies et
     * leurs conversions ne sont JAMAIS supprimées par ce chemin.
     *
     * @param list<string> $commonSlugs
     */
    public function deleteAdoptionAliases(string $userId, string $source, array $commonSlugs): int;
}
