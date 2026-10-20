<?php

namespace Repas\Repas\Domain\Model;


/**
 * L'empreinte d'un utilisateur côté `Repas` avant et après la migration d'adoption (ING-003, `docs/ingredients/migration.md` § 8) :
 * comptages et sommes de quantités par table, somme de contrôle du contenu de chaque recette et de chaque liste, versions, statistiques.
 * Le contenu est vu par le NOM et le rayon des ingrédients, jamais par leur slug : repointer une ligne d'un commun vers sa copie (même
 * nom, même rayon) ne change donc aucune de ces valeurs, et c'est ce que la migration prouve.
 *
 * Elle dit aussi combien de lignes référencent les ingrédients « surveillés » (ceux qu'on va repointer : les communs pour l'adoption, les
 * copies d'un lancement pour son retour arrière) : c'est la taille du repointage avant, et attendu 0 après.
 *
 * Aucun e-mail, aucun nom : des identifiants d'agrégats (jamais affichés), des nombres, des sommes de contrôle.
 */
final readonly class AdoptionSnapshot
{
    public const string RECIPE_ROW = 'recipe_row';
    public const string SHOPPING_LIST_ROW = 'shopping_list_row';
    public const string SHOPPING_LIST_INGREDIENT = 'shopping_list_ingredient';

    /**
     * @param array<string, int> $rowCounts nombre de lignes par table (`RECIPE_ROW`, `SHOPPING_LIST_ROW`, `SHOPPING_LIST_INGREDIENT`)
     * @param array<string, string> $quantitySums somme exacte des quantités par table, en texte décimal
     * @param array<string, int> $watchedRowCounts nombre de lignes par table qui référencent un ingrédient surveillé
     * @param array<string, AggregateSnapshot> $recipes par identifiant de recette de l'utilisateur
     * @param array<string, AggregateSnapshot> $shoppingLists par identifiant de liste de l'utilisateur
     * @param string $statistics `user.statistics` tel qu'écrit (une migration d'adoption n'est pas une création : il ne bouge pas)
     */
    public function __construct(
        public array $rowCounts,
        public array $quantitySums,
        public array $watchedRowCounts,
        public array $recipes,
        public array $shoppingLists,
        public string $statistics,
    ) {
    }

    /**
     * Les listes ENVOYÉES au drive qui ont au moins une ligne sur un ingrédient surveillé : celles qu'un repointage modifierait malgré
     * leur immutabilité (ADR 0023), donc celles qui exigent `--accept-sent`.
     */
    public function sentShoppingListsToRepoint(): int
    {
        return count(array_filter($this->shoppingLists, static fn(AggregateSnapshot $list): bool => $list->sent && $list->referencesWatched));
    }

    public function recipesToRepoint(): int
    {
        return count(array_filter($this->recipes, static fn(AggregateSnapshot $recipe): bool => $recipe->referencesWatched));
    }

    public function shoppingListsToRepoint(): int
    {
        return count(array_filter($this->shoppingLists, static fn(AggregateSnapshot $list): bool => $list->referencesWatched));
    }

    /**
     * Les lignes de toutes les tables qui référencent un ingrédient surveillé.
     */
    public function watchedRows(): int
    {
        return array_sum($this->watchedRowCounts);
    }

    /**
     * Une somme de contrôle courte (12 caractères) de ce qui doit rester IDENTIQUE avant et après : comptages, sommes, contenu et
     * situation de chaque recette et liste. Ni les versions (elles montent), ni les identifiants ne comptent : deux lancements du
     * mode à blanc, ou le mode à blanc et le `--check` d'après, donnent la même valeur si l'utilisateur n'a rien modifié entre-temps.
     */
    public function contentFingerprint(): string
    {
        $recipeHashes = array_map(static fn(AggregateSnapshot $recipe): string => $recipe->contentHash, $this->recipes);
        $listHashes = array_map(static fn(AggregateSnapshot $list): string => $list->contentHash . '/' . $list->stateHash, $this->shoppingLists);
        sort($recipeHashes);
        sort($listHashes);

        return substr(md5(json_encode([$this->rowCounts, $this->quantitySums, $recipeHashes, $listHashes], JSON_THROW_ON_ERROR)), 0, 12);
    }
}
