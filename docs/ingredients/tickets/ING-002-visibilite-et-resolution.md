# ING-002 — Visibilité et résolution : masquer les communs adoptés, tolérer les anciens slugs

- **Dépôt** : back
- **Jalon** : I1 Socle
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (règles d'accès, toutes les écritures qui reçoivent un slug d'ingrédient, contexte `Drive`)
- **Dépend de** : ING-001
- **Statut** : à faire
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décisions b, d ; [`analyse.md`](../analyse.md) §§ 3, 4, 5, 11, 12 ; skills `/repas-dev`, `/repas-drive-back` ; ADR 0001 et 0013 règle 8 (un contexte n'importe pas un autre ; ce qui circule passe par `Shared`).

## Contexte

Une fois qu'un utilisateur a adopté un commun, il ne doit plus le voir (sinon chaque ingrédient apparaît deux fois dans les rayons, la recherche et le sélecteur de recette), et une écriture qui reçoit encore l'ancien slug (onglet ouvert, liste en cache, brouillon d'un envoi sur le téléphone, [`analyse.md`](../analyse.md) § 12) doit atterrir sur la copie. Ce ticket pose ces deux règles **partout** où elles comptent. **Tant qu'aucun alias n'existe (avant ING-003), il ne change rien.**

## Portée

### A. Lire : un commun adopté est masqué pour celui qui l'a adopté

Règle unique, écrite **une seule fois** (`IngredientVisibility`, Infrastructure, fragment DQL/DBAL appliqué par les loaders et les repositories) : un ingrédient est visible par `U` si `creator = U`, **ou** (`creator` vide **et** pas d'alias d'adoption `(U, ingrédient)`). C'est aussi **l'unique endroit** où la notion de « commun » est écrite (`creator IS NULL`), pour qu'ING-009 puisse la changer.

À appliquer, avec leur test sur base réelle : `IngredientPostgreSQLLoader::listByDepartment` (`:53`), `::listAvailable` (`:159`), `::search` (`:193`), `IngredientPostgreSQLRepository::findByDepartmentAndOwner` (`:103`), `::findByOwner` (`:116`), `::findPurchaseUnitSlugUsableBy` (`:77` : un commun adopté n'est plus « utilisable » tel quel : le cas d'usage résout d'abord, B). `::get` (`:91`, la fiche) **continue de renvoyer un commun même masqué** (lecture seule, `canEdit` faux) : une page ouverte avant la migration doit pouvoir s'afficher. Les comptes administrateurs n'ont aucun alias : rien n'est masqué pour eux.

### B. Écrire : un slug reçu est résolu

Port de domaine `IngredientAliasRepository::resolve(userId, slugs)` (ING-001) + service d'application `IngredientSlugResolver::resolveForUser(userId, list<string> slugs): array<string,string>` qui, **pour un adoptant** (`isAdopter`), fait en plus adopter à la volée (`AdoptCommonIngredient`, `source = first-use`) tout commun reçu qui n'a pas d'alias ; pour un non-adoptant, il ne fait **que** résoudre les alias qui existent (aucun) : les non-adoptants gardent les communs partagés, comme aujourd'hui. À appeler en tête de :

- `CreateRecipeHandler` (`:46`) et `UpdateRecipeHandler` (`:52`) : les slugs des lignes ;
- `AddIngredientToShoppingListHandler` (`:33`) et `RemoveIngredientToShoppingListHandler` ;
- (pas `PlannedMeal`, `removeMealFromPlan`, `UpdateServingMeal` : ils lisent les slugs de la recette déjà enregistrée).

### C. Le contexte `Drive` tolère un ancien slug

`Drive` n'importe pas `Repas` : nouveau port `Shared\Application\Interface\IngredientAliasResolver` (`resolve(string $userId, array $slugs): array<string,string>`, **ne renvoie que des identifiants**, comme `IngredientDepartmentResolver` et `OwnershipLoader`), implémenté dans `Repas\Infrastructure\Loader` (une requête) ; **ne s'appelle pas `…Loader`** (Deptrac `no-loader-in-commandhandler`, voir l'en-tête de `IngredientDepartmentResolver`). Appelé par `SaveProductChoicesHandler` (slugs des intentions, `:94`, `:277`), `ReplaceIngredientPreferenceHandler` (`:60`) et `ForgetChoiceHandler` (`:37`) : un choix ne se crée jamais sous le slug d'un commun que l'utilisateur a adopté. **Ne résout pas** pour un non-adoptant (aucun alias). Aucun nom d'enseigne dans ce code.

### D. Garde-fou : l'oubli est interdit

Test d'architecture `IngredientSlugWritesAreResolvedTest` : liste explicite (dans le test) des commandes d'écriture qui portent un slug d'ingrédient (`CreateRecipeCommand`/`UpdateRecipeCommand` via leurs lignes, `AddIngredientToShoppingListCommand`, `RemoveIngredientToShoppingListCommand`, `SaveProductChoices`/`ChoiceIntent`, `ReplaceIngredientPreferenceCommand`, `ForgetChoiceCommand`) ; le test parcourt `src/` et **échoue** si une commande a une propriété nommée `ingredientSlug` ou `ingredientSlugs` (ou une sous-commande de ligne) qui n'est pas dans la liste. Ajouter une écriture qui reçoit un slug impose de la déclarer ici, donc de la résoudre. Le skill `repas-dev` (Definition of Done) et `CLAUDE.md` le disent (ING-010).

## Hors portée

Créer des alias en masse (ING-003) ; la copie de recette (ING-004) ; `PlannedMeal` & co (leurs slugs viennent de la recette) ; l'adoption à la première utilisation pour **tous** les utilisateurs (ING-008) ; le front (il envoie les slugs que le back lui a donnés, rien à changer).

## Critères d'acceptation

- Étant donné `U` avec l'alias `(tomate -> tomate<U>)` et `V` sans alias : le rayon `fruit-et-legume` de `U` liste `tomate<U>` et **pas** `tomate` ; la recherche « tomate » de `U` renvoie **un** résultat pour cet ingrédient ; `GET /ingredients` de `U` ne contient pas `tomate` ; celui de `V` contient `tomate` et pas `tomate<U>` ; un administrateur voit `tomate`.
- `GET /ingredients/tomate` par `U` répond `200` avec `canEdit: false`.
- Étant donné `U` adoptant, une création de recette avec `tomate` enregistre une ligne `tomate<U>` ; une mise à jour aussi ; ajouter `tomate` à la liste active ajoute `tomate<U>` ; un commun **jamais adopté** par un adoptant est adopté à la volée (alias `first-use`) et la ligne reçoit la copie.
- Étant donné un non-adoptant, la même écriture avec `tomate` enregistre `tomate` (aucun changement de comportement).
- `SaveProductChoices`, `ReplaceIngredientPreference`, `ForgetChoice` reçoivent `tomate` pour `U` et agissent sur `tomate<U>` ; pour `V`, sur `tomate` ; aucune ligne `drive_choice` n'est créée sous le slug d'un commun adopté.
- `IngredientSlugWritesAreResolvedTest` échoue quand on ajoute une commande avec `ingredientSlug` sans l'inscrire.
- Deptrac : `Drive` ne dépend toujours que de `Shared`.

## Tests exigés

Loaders et repositories sur base réelle pour chaque lecture masquée (`OutputAdapter`) ; handlers en mémoire pour chaque écriture résolue (`IngredientAliasInMemoryRepository`) ; `IngredientSlugResolver` (adoptant / non-adoptant / commun jamais adopté / ingrédient personnel inchangé / slug inconnu inchangé) ; controllers (`MockCommandBus`/`MockQueryBus`) pour les clés JSON ; parcours avec les vrais bus (recette -> liste -> choix) ; `make quality` ; budgets `WriteBudget` (`creer-recette`, `modifier-recette`, `ajouter-ingredient-liste`, `retirer-ingredient-liste`, `enregistrer-choix`… montent d'**une** requête de lecture d'alias au plus ; tous à dire dans la PR, ADR 0010 règle 12).

## Definition of Done

Celle de `repas-dev` § 4 ; ADR 0028 : case « ING-002 » cochée.

## Pièges

- **Le `NOT EXISTS` doit tenir sur de petits volumes** : index `(user_id, alias_slug)` déjà la clé primaire ; ne pas charger tous les alias en mémoire pour filtrer en PHP (recherche : 260 ingrédients aujourd'hui, mais la règle sert aussi les rayons).
- `Recipe::assertIngredientIsAvailable` reste tel quel (le domaine ne connaît que `creatorId`) : la résolution se fait **avant**, dans le handler.
- Une écriture qui résout puis lit le même agrégat doit éviter la double hydratation (ADR 0013 règle 7).
- Ne pas masquer pour les administrateurs : ils administrent les communs ([`analyse.md`](../analyse.md) § 3, `OwnerVoter` `:45`).
- Pendant qu'ING-001 est en production et qu'aucun alias n'existe, toute régression de ce ticket serait visible de **tous** : tester explicitement « aucun alias = comportement strictement identique » sur les parcours existants.
