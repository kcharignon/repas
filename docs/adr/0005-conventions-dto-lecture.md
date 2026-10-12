# 0005 — Conventions des DTO de lecture

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0003](0003-cqrs-lecture-ecriture.md), [0004](0004-repository-vs-loader.md), [0007](0007-api-rest-v1.md)

## Contexte

Aujourd'hui les templates Twig font du travail métier : `shoppingList.hasRecipe(recipe)` (4 appels par ligne, chacun itère les meals), `shoppingList.getIngredientQuantity(ingredient)`, `current_user.getUser().isEqual(ingredient.creator) or (…isAdmin() and ingredient.creator is null)`, le nom par défaut d'une liste (`name` ou `"Liste du " ~ createdAt|format_datetime`), 8 tests de statut par ligne de liste, un regroupement O(rayons × lignes) dans `_ingredients_column`. Le front consommera la même donnée via l'API : cette logique doit sortir des templates et être calculée une fois côté serveur.

`ModelTrait::toArray()` n'est utilisé par aucun code HTTP. L'utiliser exposerait tous les champs privés (`User` : mot de passe, rôles, statistiques) et renvoie les enums brutes.

## Décision

1. Un DTO de lecture est une classe `final readonly` aux propriétés publiques promues dans le constructeur, sans getter, sans factory, sans logique, sans dépendance au Domain ni à Doctrine. Suffixe `DTO`. Il vit à côté de sa query : `Application/Query/{UseCase}/{X}DTO.php`.
2. Les listes sont des `array` typés en docblock (`@var list<ItemDTO>`) ; les DTO imbriqués sont des DTO.
3. Types de sortie : `string`, `int`, `float`, `bool`, `?T`, `array`. Dates sérialisées en `string` au format `DATE_ATOM`. Enums du domaine exposées par leur `->value` (`string`).
4. **Tout calcul d'affichage est précalculé par le Loader** et exposé comme donnée : `canEdit`, `quantityInActiveList`, `isInActiveList`, `displayName` (nom par défaut inclus), `isActive`/`isPaused`/`isCompleted`, regroupements par rayon ou par type de recette. Les templates ne portent aucune règle.
5. Les templates Twig reçoivent des DTO (jamais de modèle du domaine, jamais le service `current_user`). Les helpers purement de présentation (`format_datetime`, `asset()`) restent dans Twig.
6. Les mêmes DTO servent le controller Twig et le controller API : un seul contrat de lecture.
7. Pour l'API, on les sérialise avec `JsonResponse` / le serializer Symfony ; les attributs `#[OA\Property]` sont posés sur les propriétés du constructeur pour documenter la sortie. Le nom JSON = le nom de la propriété (`camelCase`).
8. `ModelTrait::toArray()` est interdit comme sortie HTTP.
9. Un DTO ne contient aucune donnée sensible qu'un client n'a pas à voir (mot de passe, token, rôles internes).

## Conséquences

- Positives : templates sans logique, contrat explicite, tests simples (comparaison de DTO), pas de lazy loading caché.
- Coûts : duplication apparente entre Domain\Model et DTO ; les DTO doivent évoluer avec les besoins de l'écran.
- Garde-fou : revue ; PHPStan ; test de contrat JSON par DTO exposé en API ([0010](0010-strategie-de-tests.md)).
