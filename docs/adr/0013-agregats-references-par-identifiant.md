# 0013 — Un agrégat référence les autres agrégats par identifiant

- **Statut** : Accepted
- **Date** : 2026-10-14
- **Liens** : [0001](0001-architecture-hexagonale.md), [0002](0002-modele-de-domaine-riche.md), [0004](0004-repository-vs-loader.md), [0010](0010-strategie-de-tests.md) règle 12, roadmap phase 8

## Contexte

La liste de courses embarquait des objets de domaine complets : chaque ligne portait un `Ingredient` (avec son rayon, ses unités, son créateur), chaque repas une `Recipe` (avec son type, son auteur, ses lignes, leurs ingrédients). Charger une liste hydratait donc tout ce graphe, et `ModelCache` masquait le surcoût. Mesuré avec `tests/Repas/WriteBudget` : renommer une liste coûtait 28 requêtes SQL (58 sans cache), cocher une ligne 28 aussi.

## Décision

1. **Un agrégat référence un autre agrégat par son identifiant** (slug ou id), jamais par l'objet. `ShoppingListRow` porte `ingredientSlug` et `unitSlug`, `ShoppingListIngredient` pareil, `Meal` porte `recipeId`.
2. Les factories et les méthodes de l'agrégat qui ont besoin des données de l'autre agrégat le reçoivent en paramètre et n'en retiennent que l'identifiant (`ShoppingList::addRow(Ingredient, quantité)`, `addMeal(id, Recipe)`).
3. Un use case qui a besoin des données d'un autre agrégat le charge lui-même par son port (`UpdateServingMealHandler` lit la `Recipe` de son repas) : le coût est payé par les use cases qui en ont l'usage, pas par tous.
4. Une question d'existence ou d'état d'un ensemble se pose par une méthode de port qui renvoie un booléen (`ShoppingListRowRepository::allRowsChecked`), pas en hydratant des agrégats.
5. Les nombres de requêtes sont figés par le cliquet `tests/Repas/WriteBudget` ([0010](0010-strategie-de-tests.md) règle 12) : alléger baisse le budget, jamais l'inverse.

6. Pas de cache de modèles. L'identity map de Doctrine sert les `find()` par clé primaire ; une lecture par lot se fait avec `PostgreSQLRepository::preload()` (une requête pour les entités absentes de l'identity map, puis `find()` sans coût). `ModelCache` est retiré.
7. **Un modèle est une copie, un agrégat se charge une seule fois par use case.** Chaque `find` d'un repository construit un modèle neuf depuis l'entité Doctrine : deux chargements du même agrégat donnent deux instances indépendantes, et `save()` recopie tout le modèle sur l'entité, donc le dernier enregistrement écrase l'autre sans erreur (mise à jour perdue ; pour la recette et la liste de courses, [0016](0016-controle-de-version-etag-if-match.md) détecte désormais la modification d'un autre processus par une version). Règles :
   - un use case charge un agrégat une fois et passe l'instance (`creator: $owner`), il ne le recharge pas ;
   - un événement ne porte que des identifiants et son listener recharge l'agrégat depuis la base (donc à jour) ;
   - un handler n'enregistre jamais un modèle chargé avant un événement dont un listener peut modifier le même agrégat (aujourd'hui : les statistiques d'un `User`, écrites par `UpdateUserStatistics`) ;
   - on compare des modèles avec `isEqual()`, jamais avec `===` (déjà la règle, aucune entorse dans `src/`).
   Audit au retrait de `ModelCache` : aucun enregistrement concerné, un seul double chargement inoffensif (`CreateIngredientHandler`, corrigé). Si l'API (phase 9) expose plusieurs clients sur la même ressource, étudier un contrôle de version du modèle à l'enregistrement pour transformer une mise à jour perdue en erreur.
8. **Un agrégat référence un utilisateur par son identifiant** (`Ingredient::creatorId`, puis `Recipe::authorId` et `ShoppingList::ownerId`), jamais par `User` : le contexte `Repas` ne charge pas le modèle du contexte `User` et ses ports prennent des identifiants (`IngredientRepository::findByOwner(string $ownerId)`). Ce qu'un handler doit savoir de l'utilisateur vient du principal authentifié, par la commande : `CreateIngredientCommand::isAdmin` (un administrateur crée des ingrédients communs) est transmis par le controller depuis `AuthenticatedUser::isAdmin()`. Un identifiant inconnu n'est plus détecté au chargement du modèle : l'utilisateur vient du jeton ou de la session, il existe. Ce que le contexte `User` doit apprendre d'un événement `Repas` (fin d'une liste) lui arrive dans l'événement (`Shared\Domain\Event\ShoppingListCompletedEvent`) et il recharge lui-même l'utilisateur ; les événements que les deux contextes se passent sont dans `Shared`.

## Conséquences

- Positives : charger une liste coûte 5 requêtes quelle que soit sa taille (liste, propriétaire, repas, ingrédients, lignes) ; cocher une ligne 3, la décocher 7, renommer, mettre en pause ou supprimer une liste 9. Les use cases de liste ont les mêmes coûts avec ou sans `ModelCache`.
- Coûts : le code qui lisait `row->getIngredient()` ou `meal->getRecipe()` charge désormais explicitement ; les builders et assertions de test comparent des identifiants.
- À appliquer aux prochains agrégats : `Recipe` référence déjà ses lignes et son type par objet ; son allègement relève des slices suivantes de la phase 8.
