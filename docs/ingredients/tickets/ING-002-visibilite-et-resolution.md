# ING-002 — Visibilité et résolution : masquer les communs adoptés, tolérer les anciens slugs

- **Dépôt** : back
- **Jalon** : I1 Socle
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (règles d'accès, toutes les écritures qui reçoivent un slug d'ingrédient, contexte `Drive`)
- **Dépend de** : ING-001
- **Statut** : fait (PR back #234)
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

`Drive` n'importe pas `Repas` : nouveau port `Shared\Application\Interface\IngredientAliasResolver` (`resolve(string $userId, array $slugs): array<string,string>`, **ne renvoie que des identifiants**, comme `IngredientDepartmentResolver`, `UnitSlugResolver` (DRV-652) et `OwnershipLoader`), implémenté dans `Repas\Infrastructure\Loader` (une requête) ; **ne s'appelle pas `…Loader`** (Deptrac `no-loader-in-commandhandler`, voir l'en-tête de `IngredientDepartmentResolver`). Appelé par `SaveProductChoicesHandler` (slugs des intentions, `:111`, `:329`, `:344`), `ReplaceIngredientPreferenceHandler` (`:60`) et `ForgetChoiceHandler` (`:37`) : un choix ne se crée jamais sous le slug d'un commun que l'utilisateur a adopté. **Ne résout pas** pour un non-adoptant (aucun alias). Aucun nom d'enseigne dans ce code.

### D. Garde-fou : l'oubli est interdit

Test d'architecture `IngredientSlugWritesAreResolvedTest` : liste explicite (dans le test) des commandes d'écriture qui portent un slug d'ingrédient (`CreateRecipeCommand`/`UpdateRecipeCommand` via leurs lignes, `AddIngredientToShoppingListCommand`, `RemoveIngredientToShoppingListCommand`, `SaveProductChoices`/`ChoiceIntent`, `ReplaceIngredientPreferenceCommand`, `ForgetChoiceCommand`), plus une liste d'**exemptions motivées** (`CreateConversionCommand`, `UpdateConversionCommand` : réservées aux administrateurs, qui n'ont jamais d'alias) ; le test parcourt `src/` et **échoue** si une commande a une propriété nommée `ingredientSlug` ou `ingredientSlugs` (ou une sous-commande de ligne) qui n'est pas dans la liste. Ajouter une écriture qui reçoit un slug impose de la déclarer ici, donc de la résoudre. Le skill `repas-dev` (Definition of Done) et `CLAUDE.md` le disent (ING-010).

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

## Réalisation (PR back #234)

Livré comme décrit ci-dessus, avec les écarts et ajouts suivants, tous décidés pour tenir l'intention du ticket. **Aucune migration de schéma** : tout est du code (le schéma est celui d'ING-001), donc aucun risque pour le retour arrière.

**Contrats** :

- `Repas\Repas\Infrastructure\Visibility\IngredientVisibility` : fragments DQL `isCommon($i)` (« `creator` vide », **le seul endroit** des requêtes où le commun s'écrit) et `visibleBy($i, $paramètre)`. Appliquée par `IngredientPostgreSQLLoader::listByDepartment`, `::listAvailable`, `::search` et par `IngredientPostgreSQLRepository::findByDepartmentAndOwner`, `::findByOwner`, `::findPurchaseUnitSlugUsableBy` (une seule requête, comme `find()` avant elle). `::get` (la fiche) n'est **pas** filtré : un commun adopté s'y lit encore, `canEdit` faux.
- **Un alias n'est valide que si l'ingrédient qu'il désigne existe et appartient à l'utilisateur.** `IngredientAliasRepository::resolve()` (ING-001) ne rend plus que des alias valides ; `lookup(userId, slugs): AliasLookup{resolved, adopter}` rend la même chose **et** le drapeau « adoptant » dans la même requête. La visibilité masque un commun **seulement** si l'alias est valide (un alias orphelin, laissé par l'ancien code après un retour arrière, ne fait donc jamais disparaître un commun).
- `IngredientRepository::findCommonSlugs(list<string>): list<string>` (nouveau, une requête) : ceux des slugs reçus qui désignent un commun.
- `Repas\Repas\Application\Service\IngredientSlugResolver` : `resolveForUser(userId, slugs)` (alias valides ; **adoptant** : adopte à la volée, source `first-use`, les communs reçus sans alias) et `resolveExisting(userId, slugs)` (alias valides, jamais d'adoption). Rend `[slug reçu => slug à utiliser]` pour les seuls slugs qui changent. Appelé par `CreateRecipeHandler` (pour l'auteur), `UpdateRecipeHandler` (pour **l'auteur de la recette**, un administrateur pouvant la modifier), `AddIngredientToShoppingListHandler` (`resolveForUser`) et `RemoveIngredientToShoppingListHandler` (`resolveExisting`).
- `Repas\Shared\Application\Interface\IngredientAliasResolver::resolve(string $userId, list<string> $slugs): array<string,string>` ; implémenté par `Repas\Repas\Infrastructure\Loader\IngredientAliasPostgreSQLResolver` (délègue à `IngredientAliasRepository::resolve`, donc une requête et la même définition de l'alias valide). Appelé par `SaveProductChoicesHandler` (après la validation du lot et le contrôle du magasin, sur toutes les intentions d'un coup : le lot est réécrit avec les slugs des copies), `ReplaceIngredientPreferenceHandler` et `ForgetChoiceHandler` (après le contrôle du magasin). Il ne fait que résoudre, jamais adopter.
- `AdoptCommonIngredient` : un alias **orphelin** (la copie a disparu) est effacé et l'adoption recommence, au lieu de rendre le slug d'une copie absente. Rejouée avec une copie vivante : inchangé (une lecture de plus, pour vérifier que la copie existe).
- `tests/Repas/Application/IngredientSlugWritesAreResolvedTest` : le garde-fou. Voir les écarts.

**Écarts avec le ticket** :

1. **`Remove…ToShoppingList` n'adopte pas** (`resolveExisting`). Le ticket demande d'appeler le résolveur adoptant ; adopter un commun (16 requêtes) pour le retirer aussitôt d'une liste qui ne peut pas contenir sa copie n'a pas de sens. Un ancien slug d'un commun déjà adopté est bien traduit.
2. **Une seule requête de lecture des alias**, y compris pour qui n'a aucun alias : `lookup()` (une jointure d'alias valides `UNION ALL` une ligne qui dit « adoptant »). Le ticket prévoyait `resolve` puis `isAdopter` (deux requêtes pour un non-adoptant). Un adoptant paie une requête de plus (`findCommonSlugs`) seulement s'il reste des slugs sans alias.
3. **La règle de visibilité est un `slug NOT IN (sous-requête non corrélée)`, pas un `NOT EXISTS` corrélé.** PostgreSQL exécute les deux pareil (une lecture des alias de l'utilisateur, une table de hachage) mais estime le `NOT EXISTS` corrélé ligne à ligne : sur 8 000 ingrédients et 8 000 alias (260 communs, 30 utilisateurs qui ont tout adopté) le coût estimé dépassait `jit_above_cost` et la compilation JIT portait la requête de 1 ms à **150 ms** ; le `NOT IN` coûte 1,7 ms, sans JIT (plans mesurés, `EXPLAIN ANALYZE`). `alias_slug` n'est jamais nul : le piège des `NULL` de `NOT IN` ne s'applique pas. Le coût estimé du `NOT EXISTS` croît avec le nombre d'ingrédients (environ 16 par ligne : de l'ordre de 40 000 pour les 2 500 ingrédients attendus après ING-003, seuil à 100 000) : la forme retenue évite d'y penser.
4. **Le garde-fou ne se contente pas d'une liste** : il vérifie en plus que chaque handler déclaré injecte un résolveur (`IngredientSlugResolver` ou `IngredientAliasResolver`) **et s'en sert** (`$this->…->resolve…(`), que les exemptions (`CreateConversion`, `UpdateConversion`) sont toujours réservées aux administrateurs (`#[IsGranted('ROLE_ADMIN')]` dans leur controller), qu'aucune déclaration ne survit à la disparition de la classe qu'elle cite, et qu'une classe `…Command` inconnue qui porte `ingredientSlug(s)` est bien repérée (répertoire d'essai). Il parcourt le code d'application **hors lectures** (`Query`, `Loader`, `Dto`, qui portent des slugs en sortie).
5. **Port `Shared` : le nom `…Resolver`, délégué au dépôt** (pas de SQL dupliqué). **`IngredientAliasInMemoryResolver`** (double idiot, `tests/Helper/InMemoryLoader/`) et `IngredientSlugResolverFactory` (le vrai résolveur et le vrai cas d'usage d'adoption sur des doubles en mémoire) sont les aides de test ; `IngredientAliasInMemoryRepository` sait modéliser l'alias orphelin sur demande (`$targetExists`).
6. Les **résumés OpenAPI** de onze routes disent ce que devient un commun adopté (`make openapi` : onze lignes de texte, aucune forme de contrat ne change, aucune clé JSON ne change).

**Budgets SQL** (`tests/Repas/WriteBudget`, tous pour un utilisateur sans alias, le cas de la production aujourd'hui) : onze scénarios montent d'**une** requête (la lecture des alias) : `creer-recette` 15 -> **16**, `modifier-recette` 16 -> **17**, `ajouter-ingredient-liste` 13 -> **14**, `retirer-ingredient-liste` 9 -> **10**, `choix-drive` 10 -> **11**, `choix-drive-contenance` 11 -> **12**, `choix-drive-lot` 11 -> **12**, `choix-drive-recherche` 6 -> **7**, `creer-preference-drive` 7 -> **8**, `remplacer-preference-drive` 6 -> **7**, `oublier-choix-drive` 4 -> **5**. `findPurchaseUnitSlugUsableBy` garde sa seule requête (la visibilité est dans la condition). Nouveau scénario **`ajouter-ingredient-liste-adoptant`** = **25** : un adoptant pose un commun qu'il n'a jamais utilisé (reconnaissance du commun + adoption à la volée, sans conversion propre ; une conversion propre de plus ajoute une requête) ; c'est le prix de la **première** utilisation d'un commun, les suivantes ne paient que la lecture des alias.

**Preuve « aucun changement visible sans copie »** : les tests qui ne créent aucun alias (`IngredientVisibilityTest::testWithoutAnyAliasEveryReadGivesExactlyWhatTheOldRuleGave`, qui compare chaque lecture à la règle d'avant écrite en SQL brut, et `IngredientVisibilityApiFlowTest::testWithoutAnyAliasNothingChangesForTheUserNorTheAdministrator`, parcours recette, liste, drive avec les vrais bus pour un utilisateur et un administrateur) **passent à l'identique sur le code d'ING-001** (`src/` de `origin/main` à la place du nouveau, mêmes assertions), et les 3 019 tests d'avant passent sans que leurs assertions changent (seuls les constructeurs de handlers, les budgets et la fabrique d'alias des tests de dépôt ont bougé). Un handler testé sans alias garde son comportement (`testWithoutAnyAlias…` dans chaque test d'écriture).

**Compatibilité avec le code précédent (ADR 0017 règle 6)** : aucun changement de schéma ni de données ; l'ancien code ignore les alias et ne les lit pas ; rien d'écrit par ce ticket n'est incompatible avec lui (une adoption à la volée écrit exactement ce qu'écrit `AdoptCommonIngredient`, ING-001).

**Ce qui n'est pas couvert (volontairement)** :

- `CopyRecipeHandler` ne traduit rien (ING-004) : après ING-003, un **adoptant** qui copie la recette d'un autre dépose les communs de cette recette sur des lignes **masquées pour lui**, et copier la recette d'un utilisateur migré par un autre utilisateur migré **écrase** l'ingrédient de même slug du copieur (`tomate<U>`, défaut décrit dans [`analyse.md`](../analyse.md) § 6, qui devient courant une fois tous les ingrédients personnels). **ING-004 doit être en production avant ING-003**, ou au moins sa partie « jamais d'écrasement ».
- `PlannedMeal`, `removeMealFromPlan`, `UpdateServingMeal` lisent les slugs de la recette déjà enregistrée (comme prévu).
- `UpdateIngredient` / `RemoveIngredient` par un ancien slug de commun : refusés comme avant (`403`, ce n'est pas l'ingrédient de l'utilisateur) ; la fiche reste lisible.

**Points d'attention pour ING-003 (migration, lancée par l'assistant)** :

- Le repointage doit laisser **zéro** ligne sur un commun aliasé : la visibilité et la résolution de ce ticket supposent que les recettes, les listes (y compris `SENT`) et les choix du drive d'un adoptant portent ses copies ; un commun qui reste dans une recette d'un adoptant est masqué dans les listes mais lisible, et `PlannedMeal` le déposerait dans sa liste.
- Les alias écrits doivent être **valides** (copie existante, `creator = U`) : sans copie, la visibilité ne masque rien et le résolveur adopte de nouveau.
- Un utilisateur devient **adoptant** dès qu'il a un alias `adoption` : un seul alias écrit par erreur suffit à faire adopter à la volée tout commun qu'il utilisera ensuite. Un administrateur ne doit jamais en avoir.
- Après `--undo`, supprimer les alias d'un lancement les rend non adoptants ; les lignes qui pointent encore sur leurs copies restent lisibles (les copies sont conservées).

**Points d'attention pour ING-004** : le port de candidats doit utiliser les mêmes règles de visibilité (`IngredientVisibility::visibleBy`, ou sa définition SQL de l'alias valide) ; `IngredientSlugResolver::resolveForUser` fait déjà « adoption à la volée si le copieur est adoptant, sinon le commun » pour les communs (étape 0 de [`rapprochement.md`](../rapprochement.md)) : l'appeler plutôt que de réécrire l'arbre ; `CopyRecipeCommand` ne porte aucun slug, donc le garde-fou ne l'attrape pas : le déclarer dans le test des écritures si la commande en gagne un.

**Points d'attention pour ING-005 / ING-008** : une fusion écrit un alias `merge` : `resolveForUser` le résout pour **tout** utilisateur (adoptant ou non), la visibilité aussi ne masque que les communs ; ING-008 (adoption pour tous) doit **repointer** les lignes existantes d'un utilisateur qui devient adoptant (ses listes et recettes portent encore les communs), faute de quoi la première adoption à la volée laisse des lignes sur des communs masqués.

**Pour le front** : rien à changer pour ce ticket. Le plafond de 200 caractères du nom d'ingrédient (ING-001) reste à reporter dans le champ du formulaire (un nom de 201 à 255 caractères répond `422`).
