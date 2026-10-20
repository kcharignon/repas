<?php

namespace Repas\Repas\Domain\Model;


/**
 * Ce que la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` §§ 5 et 6) doit savoir de la base pour décider de passer les
 * anciens communs au propriétaire du catalogue : le propriétaire, les ingrédients sans créateur et leur marqueur, les lignes des autres
 * comptes qui les désignent, les comptes actifs qui n'ont pas leurs ingrédients, les références orphelines. Une lecture, rien d'autre : la
 * même valeur sert la commande (relue dans sa transaction) et le rapport à blanc, et `CatalogHandoverPreconditions` la juge.
 *
 * Elle porte des identifiants de comptes (pour savoir lesquels l'amorçage servira) : elle n'est JAMAIS affichée telle quelle, le rapport n'en
 * montre que des nombres.
 */
final readonly class CatalogHandoverState
{
    /** Les tables de lignes qu'un ingrédient peut désigner, dans l'ordre où le rapport les affiche. */
    public const string RECIPES = 'recettes';
    public const string LIST_ROWS = 'lignes de liste';
    public const string LIST_INGREDIENTS = 'ingrédients de liste';
    public const string DRIVE_CHOICES = 'choix du drive';

    /**
     * @param ?string $ownerId le propriétaire du catalogue (`IngredientCatalogOwner`), ou null s'il n'existe pas
     * @param ?string $ownerStatus son statut (`ACTIVE`…), null s'il n'existe pas
     * @param int $administrators le nombre de comptes administrateurs (information : le propriétaire est UN compte, pas « tout administrateur »)
     * @param list<string> $slugsWithoutCreator les ingrédients sans créateur (les anciens communs), triés (ordre binaire)
     * @param array<string, bool> $markersWithoutCreator le marqueur « de base » de chacun, par slug
     * @param int $withoutCreatorWithOrigin ceux qui ont une origine (`adopted_from`), ce qu'un ancien commun n'a jamais
     * @param int $withoutCreatorAccountFormat ceux dont le slug finit par un identifiant de compte (la garantie du retour arrière en dépend)
     * @param int $ownConversionsWithoutCreator les conversions propres qu'ils portent (rattachées par le slug, elles ne changent pas)
     * @param array<string, bool> $fileSlugsAtOwner parmi les slugs du fichier de classement : ceux qui sont DÉJÀ au propriétaire (sans origine), avec leur marqueur
     * @param array<string, array<string, int>> $foreignLinesByAccount les lignes des comptes AUTRES que le propriétaire qui désignent un ingrédient sans
     *        créateur : `[identifiant du compte => [table => nombre]]`
     * @param array<string, int> $ownerLinesOnOthers les lignes du propriétaire qui désignent l'ingrédient PERSONNEL d'un autre compte, par table
     * @param list<string> $activeNotSeeded les comptes actifs, hors propriétaire, sans aucun alias d'adoption
     * @param list<string> $activeWithoutIngredient les comptes actifs, hors propriétaire, sans aucun ingrédient à eux
     * @param array<string, int> $orphans les références à un ingrédient qui n'existe pas, par table (seulement les non nulles)
     */
    public function __construct(
        public ?string $ownerId,
        public ?string $ownerStatus,
        public bool $ownerIsAdministrator,
        public int $administrators,
        public array $slugsWithoutCreator,
        public array $markersWithoutCreator,
        public int $withoutCreatorWithOrigin,
        public int $withoutCreatorAccountFormat,
        public int $ownConversionsWithoutCreator,
        public array $fileSlugsAtOwner,
        public array $foreignLinesByAccount,
        public array $ownerLinesOnOthers,
        public array $activeNotSeeded,
        public array $activeWithoutIngredient,
        public array $orphans,
    ) {
    }

    /**
     * Les lignes des autres comptes sur un ingrédient sans créateur, par table, tous comptes confondus.
     *
     * @return array<string, int>
     */
    public function foreignLines(): array
    {
        $total = [];
        foreach ($this->foreignLinesByAccount as $tables) {
            foreach ($tables as $table => $count) {
                $total[$table] = ($total[$table] ?? 0) + $count;
            }
        }
        ksort($total);

        return $total;
    }

    /**
     * La passation est faite : il ne reste aucun ingrédient sans créateur et tous les ingrédients du fichier sont au propriétaire (sans origine).
     *
     * @param array<string, bool> $classification
     */
    public function isHandedOver(array $classification): bool
    {
        return $this->slugsWithoutCreator === [] && $classification !== [] && count($this->fileSlugsAtOwner) === count($classification);
    }
}
