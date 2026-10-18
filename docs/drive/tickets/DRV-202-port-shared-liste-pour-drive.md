# DRV-202 — Ports `Shared` : lecture d'une liste pour `Drive` et noms d'ingrédients

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : S
- **Dépend de** : DRV-201, DRV-104, DRV-105
- **Statut** : fait (PR [#133](https://github.com/kcharignon/repas/pull/133), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 5.3 ; ADR 0001, 0004, 0013 ; précédent : `Shared\Application\Interface\OwnershipLoader` et `Repas\Infrastructure\Loader\OwnershipPostgreSQLLoader`.

## Contexte

Le plan d'envoi (DRV-206) a besoin des lignes d'une liste (contexte `Repas`) et l'écran de gestion des choix (DRV-204) du nom des ingrédients. `Drive` ne peut importer ni `Repas` ni `User` : ces lectures passent par des ports définis dans `Shared` et implémentés dans `Repas`.

## Portée

1. `Repas\Shared\Application\Dto\ShoppingListForDrive` (`final readonly`) : `string $id`, `string $ownerId`, `string $status`, `int $version`, `list<ShoppingListLineForDrive> $lines`.
2. `Repas\Shared\Application\Dto\ShoppingListLineForDrive` (`final readonly`) : `string $ingredientSlug`, `string $ingredientName`, `float $quantity`, `string $unitSlug`, `string $unitSymbol`, `?string $unitDimension` (valeur de `Shared\Domain\Model\Dimension` ou null), `?float $unitBaseFactor`.
3. Interface `Repas\Shared\Application\Interface\ShoppingListForDriveLoader` : `get(string $shoppingListId): ShoppingListForDrive` (liste inconnue → `ShoppingListException::shoppingListNotFound`, 404, documenté par `@throws DomainException`).
4. Interface `Repas\Shared\Application\Interface\IngredientNameLoader` : `namesBySlug(array $slugs): array` (clé = slug, valeur = nom ; les slugs inconnus sont absents du résultat).
5. Implémentations dans `Repas\Repas\Infrastructure\Loader\` : `ShoppingListForDrivePostgreSQLLoader` (deux requêtes au plus : la liste, puis ses lignes jointes à l'ingrédient et à l'unité, triées par slug d'ingrédient) et `IngredientNamePostgreSQLLoader` (une requête `IN`). Autowiring seul si une seule implémentation.
6. Double en mémoire pour les tests de `Drive` : `tests/Helper/InMemoryLoader/ShoppingListForDriveInMemoryLoader` et `IngredientNameInMemoryLoader` (doubles idiots : renvoient des DTO programmés, enregistrent leurs appels).

## Hors portée

Tout cas d'usage `Drive`. Vérification des droits (faite par le voter dans le contrôleur).

## Critères d'acceptation

- `get()` renvoie, pour une liste réelle, ses lignes avec quantité, slug et symbole d'unité, dimension et facteur de l'unité (nuls si absents), triées par slug d'ingrédient.
- `get()` d'une liste inconnue lève l'exception de domaine 404.
- `namesBySlug()` ignore les slugs inconnus et accepte une liste vide.
- Deptrac reste vert (`Shared` n'importe ni `Repas` ni `Drive`).

## Tests exigés

Loaders sur base réelle dans `tests/Repas/OutputAdapter/` (liste avec plusieurs lignes, unité avec et sans dimension, liste vide, liste inconnue).

## Definition of Done

Celle du skill `repas-dev` § 4.

## Pièges connus

- Les DTO `Shared` ne portent jamais de modèle de domaine.
- Une ligne dont l'ingrédient ou l'unité a disparu ne doit pas faire échouer la lecture : l'ignorer ou la signaler selon le comportement du loader existant (`ShoppingListPostgreSQLLoader::loadDepartments` fait des jointures internes ; garder la même sémantique).

## Réalisation (2026-10-18, PR repas#133)

Ports `Repas\Shared\Application\Interface\ShoppingListForDriveLoader::get()` et `IngredientNameLoader::namesBySlug()`, DTO `Repas\Shared\Application\Dto\ShoppingListForDrive` et `ShoppingListLineForDrive` (champs du ticket, `unitDimension` = valeur de `Dimension` ou `null`). Implémentations `Repas\Repas\Infrastructure\Loader\ShoppingListForDrivePostgreSQLLoader` (2 requêtes : liste, puis lignes jointes ingrédient et unité, jointures internes, tri PHP par slug d'ingrédient) et `IngredientNamePostgreSQLLoader` (1 requête `IN`, aucune pour une liste vide). Doubles : `ShoppingListForDriveInMemoryLoader` (liste programmée, 404 si aucune) et `IngredientNameInMemoryLoader` (noms programmés), avec leurs appels enregistrés. Tests de base dans `tests/Repas/OutputAdapter/`, coût en requêtes inclus.

- `config/services.yaml`, section `when@test` : les deux ports sont des alias publics, car aucun cas d'usage ne les injecte encore et le conteneur les retire. À supprimer quand DRV-204 ou DRV-206 les injecte.
- DRV-204 et DRV-206 : injecter les interfaces `Shared`, jamais les classes de `Repas`. `get()` ne vérifie pas les droits (le voter du contrôleur s'en charge) ; `status` vaut `ACTIVE | PAUSED | SENT | COMPLETED`.
