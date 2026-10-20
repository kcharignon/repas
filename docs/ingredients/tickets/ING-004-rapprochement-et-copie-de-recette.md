# ING-004 — Rapprochement des ingrédients à la copie d'une recette

- **Dépôt** : back
- **Jalon** : I3 Copie
- **Taille** : L
- **Effort recommandé pour l'agent** : **high** (fonctionnalité ; le domaine est pur et testé par une vérité terrain fournie)
- **Dépend de** : ING-001 (alias, adoption) ; la partie `IngredientNameMatcher` peut démarrer **dès maintenant** (aucune dépendance)
- **Statut** : à faire
- **Lire d'abord** : **[`rapprochement.md`](../rapprochement.md) en entier** (algorithme, seuils, mesures) ; [`analyse.md`](../analyse.md) § 6 (comportement actuel et ses défauts) ; [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision f ; ADR 0019 (Jaro-Winkler) ; skill `/repas-dev` ; `experiments/ground-truth.json`.

## Contexte

Copier une recette copie aujourd'hui chaque ingrédient personnel de l'auteur chez le copieur sans regarder ce qu'il possède, et peut **écraser** un ingrédient existant du copieur ([`analyse.md`](../analyse.md) § 6). Il faut se brancher sur l'ingrédient du copieur quand le nom correspond **avec certitude**, dans le même rayon, sinon créer la copie ; et se brancher sur l'alias quand l'ingrédient source est l'adoption d'un commun.

## Portée

1. **`Repas\Domain\Service\IngredientNameMatcher`** (pur, sans base) : normalisation `N2`, garde-fou `G6`, `Shared\Domain\Tool\JaroWinkler`, niveaux exact / faute de frappe, marge d'ambiguïté, départage déterministe ([`rapprochement.md`](../rapprochement.md) § 3). Constantes nommées : `AUTO_THRESHOLD = 0.97`, `SUGGESTION_THRESHOLD = 0.90`, `AMBIGUITY_MARGIN = 0.02`, liste des mots vides. Ne **pas** utiliser `SearchRelevance`. API : `match(string $name, string $departmentSlug, array $candidates): ?IngredientMatch` pour le niveau automatique ; `suggest(…)` / `similar(string $a, string $b): MatchLevel` pour ING-006. Valeur `IngredientCandidate` (slug, nom normalisé, slug du rayon, unité d'achat, unités compatibles, `adoptedFrom`).
2. **Port de lecture légère** `Domain/Interface/IngredientMatchCandidates::visibleTo(string $userId): list<IngredientCandidate>` (une requête SQL, mêmes règles de visibilité qu'ING-002, **sans** hydrater de modèles) -> implémentation `IngredientMatchCandidatesPostgreSQLRepository` (le port est lu par un `CommandHandler` : ce n'est donc pas un `…Loader`, voir l'en-tête de `IngredientDepartmentResolver`).
3. **`CopyRecipeHandler` réécrit** ([`rapprochement.md`](../rapprochement.md) § 3 à 5) : pour chaque ligne de la recette d'origine, dans cet ordre : (0) provenance (alias du copieur de l'ingrédient source ou de son `adopted_from` ; adoption à la volée si le copieur est adoptant ; sinon le commun lui-même) ; (1-4) `IngredientNameMatcher::match` sur les candidats du copieur du **même rayon** ; (5) contrôle `ConversionService::assertConvertibleToPurchaseUnit` de l'unité de la ligne sur l'ingrédient retenu, sinon pas de lien ; sinon **copie** : `Ingredient::copyFromOriginal` avec `IngredientSlugPolicy` (jamais d'écrasement), conversions propres copiées, `adoptedFrom` conservé. La recette copiée référence les ingrédients retenus ; **le même ingrédient source n'est traité qu'une fois** même s'il apparaît sur plusieurs lignes.
4. **Statistiques** : `RecipesOrIngredientsCreatedEvent` ne porte que les slugs réellement **créés** par la copie.
5. **Correction** de `Recipe::copyFromOriginal` / `RecipeRow::copyFromOriginal` : ils ne recréent plus d'ingrédient (le handler leur fournit les ingrédients résolus) ; la copie de sa propre recette ne duplique plus rien.
6. **Jeu de vérité terrain** : copier `docs/ingredients/experiments/ground-truth.json` dans `tests/Repas/Domain/Service/IngredientNameMatching/ground-truth.json` (le déplacer ; le script d'expérience garde sa copie documentaire) et l'utiliser dans `IngredientNameMatcherTest` avec les constantes de production.

## Hors portée

L'écran de doublons et le chargeur de suggestions (ING-006, qui réutilise `similar`) ; la fusion (ING-005) ; l'adoption pour tous (ING-008). Aucune modification du front : la copie reste un `POST` sans corps.

## Critères d'acceptation

- **Vérité terrain.** Rejouée avec les constantes de production, **zéro faux lien** : aucune ligne `expected: null` n'est branchée à aucun candidat du même rayon ; **100 %** des catégories `casse`, `ponctuation`, `accent`, `pluriel` sont branchées sur le bon candidat ; au moins **50 %** de `faute`.
- **Provenance.** Étant donné un auteur dont la recette contient `tomate<A>` (adoption du commun `tomate`) et un copieur avec l'alias `tomate -> tomate<C>`, alors la recette copiée référence `tomate<C>` et **rien n'est créé**. Copieur adoptant sans alias : adoption à la volée. Copieur non adoptant : la ligne référence `tomate`.
- **Nom exact, même rayon, unité compatible** : lien ; **autre rayon** : copie ; **unité incompatible** : copie.
- **Pas d'écrasement.** Étant donné que le copieur possède `creme<C>` (rayon fromage) et que l'auteur a `creme` (rayon sauce), alors une copie `creme-2<C>` est créée et `creme<C>` est **intact** (rayon, unités, conversions).
- **Copier sa propre recette** : aucune création, aucune conversion dupliquée, mêmes ingrédients.
- **Ambiguïté** (deux candidats à moins de 0,02) : pas de lien. **Deux candidats exacts** : le premier par slug.
- **Statistiques** : `RecipesOrIngredientsCreatedEvent` ne contient que les créations ; un lien ou une adoption ne change pas `user.statistics.ingredients`.
- **Rien de plus à l'écran** : la réponse reste `201` + `Location`.
- `copier-recette` : budget SQL **annoncé** dans la PR (candidats et alias lus chacun en une requête ; ne dépend pas du nombre de lignes de la recette).

## Tests exigés

`IngredientNameMatcherTest` (table + vérité terrain, [`rapprochement.md`](../rapprochement.md) § 9) ; `CopyRecipeHandlerTest` en mémoire (tous les critères) ; `IngredientMatchCandidatesRepositoryTest` sur base réelle (rayon, communs adoptés masqués, ingrédients d'un autre jamais candidats) ; `CopyRecipeApiControllerTest` ; parcours avec les vrais bus (copier, planifier, liste, drive : le plan de la copie lit les produits mémorisés du copieur) ; `WriteBudget`. `make test quality`.

## Definition of Done

`repas-dev` § 4 ; PR « À relire » : valeurs des constantes, écarts à la maquette de décision, tout cas de la vérité terrain qui échoue (aucun attendu).

## Pièges

- **Un faux lien est invisible.** En cas de doute dans le code, ne pas brancher. Toute évolution des constantes se justifie par une relecture de `experiments/resultats.md`.
- Les candidats d'un **copieur adoptant** excluent les communs adoptés ; ceux d'un copieur non adoptant incluent les communs (il les voit).
- Ne pas confondre la normalisation de **comparaison** (`N2`, sans effet sur ce qui est stocké) avec `sluggedName` (colonne, `slugify` seul) : on ne réécrit jamais le nom d'un ingrédient.
- `IngredientPostgreSQLRepository::save` écrase par slug : toute création passe par `exists()` et `IngredientSlugPolicy`.
- Garder `JaroWinkler` inchangé (`JaroWinklerTest` de référence) : seul le matcher est nouveau.
