# ING-011 — Le marqueur « de base » et le propriétaire du catalogue

- **Dépôt** : back
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (schéma, règle d'accès à un champ, port entre contextes)
- **Dépend de** : la PR de cadrage (cet amendement) fusionnée
- **Statut** : à faire
- **Sortie** : **R1** (avec ING-012, ING-008, ING-009)
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-20 (décisions 3 et 4, D1 et D2) ; [`sans-commun.md`](../sans-commun.md) §§ 1, 2.2, 9 ; ADR 0001 et 0013 règle 8 (un contexte n'importe pas un autre ; ce qui circule passe par `Shared`) ; ADR 0007 §§ 11 à 13 (codes) ; skills `/repas-dev`.

## Contexte

L'amorçage des nouveaux comptes (ING-008) ne copie pas tous les ingrédients de l'administrateur, mais ceux qu'il marque **« de base »** : ce qui est très personnel (une marque, un plat spécial) ne doit pas partir chez ses futurs proches. Il faut un marqueur sur l'ingrédient, la notion de **propriétaire du catalogue** (le compte protégé, un seul) accessible depuis `Repas` sans importer `User`, et l'API pour poser le marqueur. **Aucun comportement visible ne change** : le marqueur vaut `FALSE` partout jusqu'à la passation (ING-009), et rien ne le lit avant ING-008.

## Portée

1. **Migration Doctrine additive** (numérotée après la dernière de `main`, ADR 0017 règle 6) : `ALTER TABLE ingredient ADD is_base BOOLEAN DEFAULT FALSE NOT NULL` ; `down` documenté (« sur décision humaine, efface le classement »). Compatible avec la version précédente du code : l'ancienne entité ne mappe pas la colonne, la valeur par défaut s'applique à ses `INSERT`. Entité Doctrine `Ingredient` : `isBase`.
2. **Domaine** : `Ingredient::isBase(): bool`, `Ingredient::changeBase(bool): self` (fluent) ; `load` lit `is_base` (absent = faux) ; `create`, `adopt`, `copyFromOriginal` et toute copie naissent avec `isBase = false` ; `update()` ne touche pas au marqueur. `IngredientAdoptionBulkPostgreSQLRepository::copyIngredients` (ING-003) pose **explicitement** `FALSE` (jamais la valeur de la source).
3. **Port `Shared\Application\Interface\IngredientCatalogOwner`** : `ownerId(): ?string` (l'identifiant du compte propriétaire du catalogue, ou `null` s'il n'existe pas). Implémenté par `User` en lisant la table `"user"` en SQL (comme `UserLoader`) : le compte de `User::PROTECTED_EMAIL`, ou celui de la variable d'environnement facultative `INGREDIENT_CATALOG_OWNER_EMAIL` quand elle est renseignée (développement : le compte de démonstration ; **aucune valeur à poser en production**, aucune ligne dans `deploy/`). Résultat mémorisé le temps de la requête. Double de test `IngredientCatalogOwnerInMemory` (`tests/Helper`). Le nom de la classe n'est pas `…Loader` (un `CommandHandler` l'appelle, Deptrac).
4. **API (ajouts seulement, rien de renommé ni de retiré)** :
   - `IngredientPayload` (corps de `POST /api/v1/ingredients` et de `PUT /api/v1/ingredients/{slug}`) : champ facultatif `isBase` (booléen). Absent ou `null` : inchangé (`PUT`) ou `false` (`POST`) ; **jamais obligatoire**. Un ancien client qui ne l'envoie pas ne remet donc jamais le marqueur à zéro.
   - `IngredientDTO` (`GET /api/v1/ingredients/{slug}`) : `isBase` (booléen, toujours écrit) et `canSetBase` (booléen, toujours écrit : vrai seulement si le lecteur est le propriétaire du catalogue et que l'ingrédient est à lui).
   - `IngredientRowDTO` (`GET /api/v1/departments/{slug}/ingredients`) : `isBase` (booléen, toujours écrit ; faux pour tout autre lecteur que le propriétaire).
   - `make openapi`, diff relu : champs ajoutés, aucun existant modifié.
5. **Qui peut poser le marqueur** (D2) : `CreateIngredientHandler` et `UpdateIngredientHandler`, quand `isBase` est fourni **et diffère** de la valeur actuelle : le demandeur doit être `IngredientCatalogOwner::ownerId()` **et** l'ingrédient doit être à lui (ou, jusqu'à ING-014, sans créateur) ; sinon `IngredientException::baseMarkerForbidden()` → `403` `INGREDIENT_BASE_FORBIDDEN` (message = code). Un `isBase` identique à l'existant, ou absent, est accepté par tout propriétaire de l'ingrédient (pas de lecture du propriétaire du catalogue : zéro requête de plus). Le contrôle est **métier** (dans le handler, pas seulement dans le controller). Défense en profondeur : l'amorçage ne lit que le créateur propriétaire ou vide (ING-008), donc un marqueur posé ailleurs serait inerte.
6. **Fusion** : l'ingrédient gardé conserve son marqueur ; rien à faire (le gardé n'est pas modifié).
7. **Documentation** : `CLAUDE.md` (ligne du chantier, une phrase sur le marqueur), `docs/api/openapi.json`.

## Hors portée

L'amorçage (ING-008) ; l'écran (ING-015) ; poser le marqueur sur les 261 anciens communs (ING-009, par le fichier) ; tout changement de visibilité.

## Critères d'acceptation

- Étant donné un propriétaire du catalogue et son ingrédient, quand il envoie `PUT` avec `isBase: true`, alors l'ingrédient est de base (`GET` : `isBase: true`, `canSetBase: true`, ligne du rayon : `isBase: true`).
- Étant donné un utilisateur ordinaire, quand il envoie `PUT` sur son ingrédient avec `isBase: true`, alors `403` `INGREDIENT_BASE_FORBIDDEN` et rien n'est écrit ; avec `isBase: false` alors que c'est déjà faux, `204`.
- Étant donné un administrateur qui n'est **pas** le propriétaire du catalogue, alors `403` aussi (un marqueur n'a de sens que chez le propriétaire).
- Étant donné un `PUT` sans le champ `isBase`, alors le marqueur ne change pas (jamais de remise à zéro par un ancien client).
- Étant donné un `POST` du propriétaire avec `isBase: true`, alors l'ingrédient est créé de base ; d'un autre : `403`, rien n'est créé.
- Étant donné une copie (amorçage, adoption, copie de recette, copie en masse d'ING-003), alors `isBase = false` quelle que soit la source.
- Étant donné `GET` de l'ingrédient d'un autre : `404` comme avant ; `canSetBase` est faux pour tout lecteur autre que le propriétaire.
- La migration est **additive** : sur le schéma précédent `doctrine:migrations:migrate` puis `doctrine:schema:validate` passent ; l'image précédente (`deploy-smoke`) démarre sur le nouveau schéma et lit/écrit un ingrédient.
- `IngredientCatalogOwner` rend le compte de `User::PROTECTED_EMAIL`, ou celui de la variable quand elle est renseignée, ou `null` s'il n'existe pas.

## Tests exigés

Handlers en mémoire (`CreateIngredientHandlerTest`, `UpdateIngredientHandlerTest` : tous les cas ci-dessus, aucun événement de plus) ; `IngredientRepositoryTest` base réelle (aller-retour du marqueur, copie en masse à `FALSE`) ; implémentation du port (`User`, base réelle : compte protégé, variable, absent) ; controllers (`MockCommandBus`/`MockQueryBus`, clés JSON, `403`) ; parcours avec les vrais bus et un JWT (propriétaire, utilisateur, autre administrateur) ; `UserOwnedDataCoverageTest` inchangé ; budgets `WriteBudget` : `creer-ingredient` et `modifier-ingredient` **ne montent pas** (le propriétaire n'est lu que si le marqueur change) ; `make test quality openapi-check`, `make deploy-smoke` non requis (aucune variable dans `deploy/`).

## Definition of Done

`repas-dev` § 4 ; `docs/ingredients/roadmap.md` coché ; PR « À relire » : le contrat ajouté (champs et `403`), l'absence de requête de plus.

## Pièges

- **Tout ajout au JSON doit rester toléré par le site** : les champs de réponse sont **ajoutés**, jamais renommés ni retirés ; `isBase` et `canSetBase` sont toujours écrits (jamais `null`) ; le champ de requête est facultatif.
- Ne pas confondre « administrateur » et « propriétaire du catalogue » : seul le second pose le marqueur ; ne pas écrire `isAdmin` dans ce contrôle.
- `ModelTrait::toArray()` n'est jamais une sortie HTTP (le marqueur y entrerait par réflexion).
- Le propriétaire du catalogue est résolu par un port `Shared` : le contexte `Repas` n'importe ni `User` ni sa constante. Ne jamais recopier l'adresse du compte protégé dans le dépôt, les tests ou la documentation (le test utilise le double et une adresse de test).
- `IngredientAdoptionBulkPostgreSQLRepository::copyIngredients` copie colonne par colonne : y ajouter `is_base` à `FALSE`, sans `SELECT *`.
