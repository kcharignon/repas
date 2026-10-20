# ING-011 — Le marqueur « de base » et le propriétaire du catalogue

- **Dépôt** : back
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (schéma, règle d'accès à un champ, port entre contextes)
- **Dépend de** : la PR de cadrage (cet amendement) fusionnée
- **Statut** : fait (PR #242)
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

## Réalisation (PR back #242)

Livré comme décrit ci-dessus, avec les écarts et ajouts suivants, tous décidés pour tenir l'intention du ticket et de l'amendement du 2026-10-20. **Rien n'a été exécuté en production** ; aucun comportement visible ne change (le marqueur vaut `FALSE` partout, rien ne le lit avant ING-008).

**Forme exacte du schéma** (`migrations/Version20261021160000.php`, additive, rejouée `up`, `schema:validate`, `down`, `up` sur une base vide) : `ALTER TABLE ingredient ADD is_base BOOLEAN DEFAULT FALSE NOT NULL` ; `down` : `ALTER TABLE ingredient DROP is_base` (efface le classement : décision humaine). Entité Doctrine : `#[ORM\Column(name: 'is_base', type: 'boolean', options: ['default' => false])]`. Aucune clé étrangère, aucune donnée posée.

**Contrats** (`docs/api/openapi.json` fait foi, ajouts seulement, clés toujours écrites) :

- Corps de `POST /api/v1/ingredients` et de `PUT /api/v1/ingredients/{slug}` (`IngredientPayload`) : `isBase` booléen **facultatif**, nullable. Absent ou `null` : inchangé (`PUT`) ou `false` (`POST`) ; une valeur qui n'est pas un booléen est un `422`.
- `403` de message exactement **`INGREDIENT_BASE_FORBIDDEN`** (`IngredientException::INGREDIENT_BASE_FORBIDDEN`, `baseMarkerForbidden()`) : `isBase` fourni **et différent de l'actuel** (`POST` : vrai, la valeur actuelle d'une création étant faux) par un compte qui n'est pas le propriétaire du catalogue, ou sur un ingrédient qui n'est ni à lui ni sans créateur, ou quand le propriétaire n'existe pas. Rien n'est écrit (contrôle fait avant toute écriture, dans le handler). Un administrateur qui n'est pas le propriétaire est refusé comme un utilisateur. Le même `isBase` que l'actuel, ou absent, est accepté par tout propriétaire de l'ingrédient, **sans lire le propriétaire du catalogue**.
- `GET /api/v1/ingredients/{slug}` : `isBase` et `canSetBase`, booléens, jamais `null`, ajoutés **à la fin** de `IngredientDTO` (après `listCount`). `GET /api/v1/departments/{slug}/ingredients` : `isBase` ajouté à la fin de chaque ligne (`IngredientRowDTO`). Les deux champs de la fiche et celui de la ligne figurent dans les `required` du schéma de réponse.
- Règle de calcul, dans `IngredientPostgreSQLLoader` : `canSetBase` = le lecteur est le propriétaire du catalogue **et** l'ingrédient est à lui (créateur = lecteur) ; `isBase` = la colonne, **seulement** pour le propriétaire du catalogue, et seulement pour un ingrédient de son catalogue (à lui, ou sans créateur avant ING-014) ; faux pour tout autre lecteur (un administrateur qui n'est pas le propriétaire compris, un marqueur posé ailleurs étant inerte). Le propriétaire n'est lu qu'une fois par requête et seulement si la réponse en dépend (une ligne marquée, ou un ingrédient à soi) : un rayon sans ingrédient marqué ne coûte aucune requête de plus (testé).

**Comment le propriétaire du catalogue est résolu** : port `Repas\Shared\Application\Interface\IngredientCatalogOwner::ownerId(): ?string` ; implémentation `Repas\User\Infrastructure\Catalog\IngredientCatalogOwnerPostgreSQL` (une requête DQL sur l'entité `User`, par adresse normalisée) : le compte de `User::PROTECTED_EMAIL`, **ou** celui de `INGREDIENT_CATALOG_OWNER_EMAIL` quand elle est renseignée (`%env(default::…)%`, donc tolérée absente ou vide ; **si elle désigne un compte absent, le résultat est `null`, jamais un repli sur le compte protégé**), ou `null`. Résultat mémorisé le temps de la requête (`ResetInterface`, tag `kernel.reset` vérifié : en mode worker le même objet sert plusieurs requêtes). La variable est vide dans `.env`, documentée, et **n'est pas dans `deploy/`** ; les tests la posent à une adresse de test dans `.env.test` (`UserBuilder::CATALOG_OWNER_EMAIL`, `UserBuilder::isCatalogOwner()`). Double de test `tests/Helper/IngredientCatalogOwnerInMemory` (qui compte ses appels).

**Écarts avec le ticket** :

1. **La règle « qui pose le marqueur » est dans le domaine** : `Ingredient::assertBaseMarkerSettableBy($userId, ?$catalogOwnerId)` (pure, sans port) ; les handlers lisent le port seulement quand le marqueur change, puis l'appellent. Évite d'écrire la règle deux fois (création et modification).
2. **`UpdateIngredientCommand` gagne `userId`** (le handler doit savoir qui demande) puis `isBase` (`?bool`, défaut `null`) ; `CreateIngredientCommand` gagne `isBase` (`bool`, défaut `false`), à la fin des signatures. Les constructeurs des deux handlers gagnent `IngredientCatalogOwner` en dernier argument.
3. **`canSetBase` est plus strict que le handler** : le handler accepte du propriétaire du catalogue un ancien commun (sans créateur, jusqu'à ING-014), `canSetBase` ne le propose que sur un ingrédient **à lui**. C'est voulu : le réglage n'apparaît dans l'écran (ING-015) qu'après la passation, et le classement des 261 est posé par le fichier (ING-009), pas à la main.
4. **`isBase` est montré au seul propriétaire, aussi sur la fiche** (le ticket ne le disait que pour la ligne du rayon) : même règle des deux côtés, un lecteur ordinaire ne voit jamais un marqueur.
5. **Scénarios de budget ajoutés** : `creer-ingredient-base` (11) et `modifier-ingredient-base` (8), une lecture de plus que leurs jumeaux, celle du propriétaire. `creer-ingredient` (10) et `modifier-ingredient` (7) ne bougent pas.
6. **`tests/Helper/AdoptionDataset::insert` convertit les booléens PHP** : une ligne lue par `SELECT *` rend `is_base` en `false`, que DBAL envoyait comme une chaîne vide.

**Compatibilité avec le code précédent (ADR 0017 règle 6)**, vérifiée en lançant le code de `origin/main` d'avant ce ticket (sans la colonne) sur une base migrée : `doctrine:migrations:migrate` répond « Already at the latest version » (la migration est « exécutée et indisponible », sans effet) ; `POST /ingredients` `201` (la valeur par défaut de la base donne `is_base` à faux), `GET` de la fiche et du rayon `200` (sans les nouveaux champs), `PUT` `204` (un `is_base` vrai posé en base reste vrai : l'`UPDATE` de l'ancienne entité ne touche pas la colonne), `DELETE` `204`. Seule dégradation : `doctrine:schema:validate` lancé avec l'ancien code signale la colonne, ce qui ne concerne que la CI. Réciproquement, le nouveau code lit sans erreur une ligne insérée sans la colonne (testé). Le retour arrière du code ne défait pas le schéma.

**Points d'attention pour la suite** :

- **ING-012** (`CreateIngredientHandler`, `IngredientPayload`, les mêmes fichiers) : la règle du marqueur est après `Ingredient::create` et avant `exists()` ; en retirant `isAdmin`, l'ingrédient d'un administrateur devient à lui, donc `canSetBase` est vrai pour le propriétaire qui vient de le créer. `CreateIngredientApiController` passe `isBase: $payload->isBase ?? false`. Le test `IngredientBaseMarkerApiFlowTest::testOnlyTheCatalogOwnerCreatesABaseIngredient` ne regarde que la colonne (pas le créateur) : il reste juste avant et après ING-012.
- **ING-008** (amorçage) : lire `is_base` des ingrédients du propriétaire (`IngredientCatalogOwner`) **ou** sans créateur ; la copie en masse (`copyIngredients`) pose déjà `is_base` à `FALSE` explicitement, et `COPY_DIFFERS_FROM_COMMON` ne compare pas le marqueur (une copie conforme a un marqueur différent de sa source). `Ingredient::copyFromOriginal` et `adopt` naissent à faux. Un nouveau chemin qui insère un ingrédient en SQL doit nommer `is_base`.
- **ING-009** (passation) : la phase 1 écrit `is_base` par SQL ; le dépôt (`IngredientPostgreSQLRepository`) recopie le marqueur du modèle à chaque `save()` (dernier enregistrement gagnant, ADR 0013 règle 7) : ne pas enregistrer de modèle d'ingrédient chargé avant la phase 1 si l'on veut éviter de la défaire. Pour le propriétaire, les 151 marqués sont visibles en `isBase` dès que la colonne est posée, avant la passation (ses anciens communs sont sans créateur).
- **ING-015** (front) : se fier à `canSetBase` (pas à `isAdmin`) pour montrer l'interrupteur, et n'envoyer `isBase` dans le `PUT` que lorsque `canSetBase` est vrai : un compte ordinaire qui enverrait une valeur différente de l'actuelle recevrait `403` `INGREDIENT_BASE_FORBIDDEN` (la même valeur, ou aucune, est acceptée) ; tolérer les champs de réponse absents (ancien back) ; `422` si la valeur n'est pas un booléen ; `isBase` de la ligne du rayon n'est vrai que chez le propriétaire.

**Non vérifié** : `make deploy-smoke` (non requis par le ticket : rien ne change dans `deploy/`, le `Dockerfile` ni l'entrypoint) ; l'image précédente n'a pas été reconstruite, c'est son code source qui a été rejoué sur le schéma migré (ci-dessus) ; le comportement du service de mémorisation en mode worker FrankenPHP réel (la remise à zéro est prouvée par le tag `kernel.reset` et un test unitaire de `reset()`).
