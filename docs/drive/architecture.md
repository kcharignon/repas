# Architecture technique : envoi d'une liste de courses au drive

Référence pour les agents qui implémentent les tickets (`tickets/DRV-*.md`). Contexte et raisons : [`analyse.md`](analyse.md). Faits sourcés : [`recherche.md`](recherche.md). Décisions : [ADR 0022](../adr/0022-remplissage-du-panier-drive.md) et [ADR 0023](../adr/0023-liste-envoyee-au-drive.md). En cas d'écart entre ce fichier et un ADR, l'ADR gagne.

## 1. Glossaire

- **Drive** : service de courses en ligne d'une enseigne (ici E.Leclerc Drive, `leclercdrive.fr`).
- **Enseigne** (`retailer`) : `leclerc` pour l'instant ; Auchan et Intermarché plus tard.
- **Magasin** (`store`) : point de retrait de l'utilisateur. Identifiant chez l'enseigne (ex. `053701`), hôte (ex. `fd9-courses.leclercdrive.fr`), adresse de base `https://<hôte>/magasin-<id>-<id>`.
- **Agent** : script autonome exécuté dans la page leclercdrive.fr de l'utilisateur, lancé par un favori JavaScript. Ne parle jamais à notre API.
- **Plan** : ce que Repas demande à l'agent de faire pour une liste envoyée (une ligne par ingrédient).
- **Produit** (`Product`) : article vendu par l'enseigne, fait public sans propriétaire.
- **Choix** (`Choice`, ou préférence) : ce qu'un utilisateur veut, pour un magasin et un ingrédient, privé : une liste **ordonnée** de produits acceptés (priorité 1 = préféré, les suivants = produits de secours), une liste de produits **refusés** (jamais proposés ni mis au panier), ou « j'en ai toujours ».
- **Produit de secours** : produit accepté de rang 2 ou plus, mis au panier seulement si ceux qui le précèdent sont indisponibles.
- **Liste envoyée** : `ShoppingList` au statut `SENT`, non modifiable.
- **Passage** (`run`) : un lancement de l'agent pour une liste, identifié par un `runId` (uuid) créé par le front.

## 2. Invariants (non négociables)

1. Aucun identifiant, cookie ni jeton d'enseigne ne transite par notre back, notre front ni nos logs, et rien n'en est stocké.
2. Le back ne contacte jamais une enseigne.
3. L'agent ne contient aucun code distant : tout son code est dans le favori ; une mise à jour = réinstaller le favori.
4. L'agent ne fait que : lire le magasin courant, chercher un produit, fixer la quantité d'un produit au panier, lire ce que l'utilisateur ajoute dans le cadre de choix. Jamais de créneau, de validation de commande, de paiement, ni de lecture de données de compte.
5. Une liste `SENT` n'est plus modifiable (§ 4).
6. Un envoi ne coche jamais de ligne (`checked` = « dans le caddie » ; cocher la dernière ligne termine la liste).
7. Le panier de l'enseigne n'est jamais vidé ni réduit par Repas : l'agent ne fixe que la quantité des produits du plan.

## 3. Vue d'ensemble

| Morceau | Dépôt | Responsabilité |
|---|---|---|
| Contexte `Repas` (existant) | back `kcharignon/repas` | Cycle de vie de la liste dont le verrou `SENT` ; unités avec `dimension` ; lignes de liste avec `ingredientSlug` et `unitSlug`. |
| Contexte `Drive` (nouveau) | back | Réglages du drive, `Product`, `Choice`, calcul des conditionnements, plan d'envoi, purge avec le compte. Isolé et supprimable d'un bloc. |
| `Shared` | back | Ports `ShoppingListForDriveLoader` et `IngredientNameLoader` (+ DTO dans `Shared\Application\Dto`), port `DriveFeature`, énumération `Dimension` (`Shared\Domain\Model`), événement `UserDeletedEvent` (existant). |
| Agent | front `kcharignon/repas-front`, `drive-agent/` | Favori autonome (TypeScript compilé en un fichier). |
| Protocole | front, `drive-agent/protocol/` | Schémas JSON des messages, partagés par l'agent et le front. Source de vérité des formats ; § 7 en donne des exemples. |
| Front Repas | front, `src/features/drive/` et `src/features/shopping-lists/` | Écrans, pont `driveBridge`, brouillon de passage. |

Dépendances back (Deptrac) : `Repas → Shared`, `User → Shared`, `Drive → Shared`. Aucun contexte n'importe un autre. Ce qui circule passe par `Shared`.

### Séquence nominale

```
Utilisateur      Front (PWA)                 Back                    Agent (page Leclerc)
    | Envoyer au drive |                       |                           |
    |----------------->| POST /shopping-lists/{id}/send (If-Match)         |
    |                  |---------------------->| ACTIVE -> SENT, sentAt     |
    |                  | GET /drive/plans/{id} |                           |
    |                  |---------------------->| lignes (Shared) + choix    |
    |                  |<----------------------| + PackCalculator          |
    |   revoit le plan, surcharges par ligne    |                           |
    | Ouvrir Leclerc   | ouvre <baseUrl>#repas=<plan> dans Chrome complet   |
    |------------------------------------------------------------------->  |
    | (connexion Leclerc si besoin) touche le favori                       |
    |                                                                      | valide, confirme
    |                                                                      | phase A : produits connus
    |                                                                      | phase B : choix guidés
    |                  |<-------------------------- /drive/retour#result=<résultat>
    |                  | PUT /drive/choices (nouveaux choix)               |
    |                  |---------------------->|                           |
    | récapitulatif : Finaliser sur Leclerc / Réessayer / Terminer / Rouvrir |
```

## 4. Règle « liste envoyée = non modifiable »

### 4.1 État du code avant le chantier (lu le 2026-10-17)

- `ShoppingListStatus` : `ACTIVE | PAUSED | COMPLETED` (`src/Repas/Domain/Model/ShoppingListStatus.php`). Colonne `status` de type texte (`enumType` Doctrine) : une valeur de plus ne demande pas de migration.
- L'agrégat `ShoppingList` (`src/Repas/Domain/Model/ShoppingList.php`) n'a **aucun invariant** sur le statut : `setStatus`, `completed()`, `activated()`, `pause()` changent le statut sans contrôle.
- `PlannedMealHandler`, `removeMealFromPlanHandler`, `AddIngredientToShoppingListHandler`, `RemoveIngredientToShoppingListHandler` n'agissent que sur `findOneActivateByOwner` : ils ne touchent pas une liste non active.
- Agissent **par identifiant sans regarder le statut** : `UpdateServingMealHandler` (par id de repas), `UpdateShoppingListHandler` (renommer), `TickLineOnShoppingListHandler` et `UncheckLineOnShoppingListHandler` (par id de ligne), `ActivatedShoppingListHandler`, `PausedShoppingListHandler`. `UncheckLine...` repasse une liste `COMPLETED` en `ACTIVE`.
- `StopListEventListener` termine la liste quand la dernière ligne est cochée ; `RemoveShoppingListCompletedEventListener` supprime les listes `COMPLETED` à la création d'une nouvelle liste ; `CreateShoppingListHandler` met en pause la liste `ACTIVE`.

### 4.2 Transitions

| De → vers | Action | Règles |
|---|---|---|
| `ACTIVE` → `SENT` | `POST /api/v1/shopping-lists/{id}/send` | Au moins une ligne, sinon `422 SHOPPING_LIST_EMPTY`. Liste pas `ACTIVE` : `409 SHOPPING_LIST_NOT_SENDABLE`. `If-Match` accepté (`412`). Déjà `SENT` : `204` sans effet. Enregistre `sentAt` (horloge injectée). Aucun événement. |
| `SENT` → `ACTIVE` | `POST /api/v1/shopping-lists/{id}/reopen` | Explicite. Met en pause la liste `ACTIVE` éventuelle, comme `ActivatedShoppingListHandler`. Efface `sentAt`. Pas `SENT` : `409 SHOPPING_LIST_NOT_SENT`. `If-Match` accepté. |
| `SENT` → `COMPLETED` | `POST /api/v1/shopping-lists/{id}/complete` (existant) | Émet `ShoppingListCompletedEvent` comme aujourd'hui. |
| `PAUSED` → `SENT`, `SENT` → `PAUSED` | — | Interdits. Pour envoyer une liste en pause : l'activer d'abord. |

### 4.3 Opérations sur une liste `SENT`

- **Autorisées** : lire, `complete`, `reopen`, supprimer.
- **Bloquées** avec `409` et le message `SHOPPING_LIST_SENT` : ajouter ou retirer une recette, changer les portions d'un repas, `+`/`-` d'un ingrédient, renommer, cocher, décocher, `activate`, `pause`.

### 4.4 Implémentation prescrite

- `ShoppingList::assertEditable(): void` lève `ShoppingListException::sent($id)` (409, message `SHOPPING_LIST_SENT`, constante de classe comme `UserException::PROTECTED_ACCOUNT`). Elle est appelée dans **chaque mutateur de l'agrégat** (`addMeal`, `addRow`, `subtractRow`, `addIngredient`, `removeIngredient`, `removeMeal`, `setName`, `pause`, `activated`) et en tête des handlers qui modifient un repas par identifiant (`UpdateServingMealHandler`, qui change `Meal` sans passer par l'agrégat).
- Nouvelles méthodes de l'agrégat : `send(DateTimeImmutable $now)`, `reopen()`, `isSent()`, `getSentAt()`. `completed()` reste permis depuis `SENT`.
- Cocher et décocher ne chargent qu'une `ShoppingListRow` : ajouter au port `ShoppingListRepository` une question booléenne `isSent(string $shoppingListId): bool` (ADR 0013 : l'état d'un agrégat se demande par un port booléen) et l'appeler dans `TickLineOnShoppingListHandler` et `UncheckLineOnShoppingListHandler`. `UncheckLine...` ne réactive jamais une liste `SENT`.
- `SendShoppingListCommand(shoppingListId, ?int expectedVersion)` et `ReopenShoppingListCommand(shoppingListId, ?int expectedVersion)` : le handler charge la liste, appelle `assertVersion()` juste après (ADR 0016), exécute la transition, enregistre. La version augmente (enregistrement de la liste).
- Entité Doctrine `ShoppingList` : colonne `sent_at` (timestamp nullable) ; `fromModel`, `updateFromModel` et la conversion entité → modèle du dépôt `ShoppingListPostgreSQLRepository` la portent.
- Lecture : `ShoppingListDTO` et `ShoppingListDetailDTO` ajoutent `isSent: bool` et `sentAt: ?string` (ATOM) ; l'énumération `status` de la documentation OpenAPI ajoute `SENT`. `ShoppingListRowDTO` ajoute `ingredientSlug` et `unitSlug` (ticket DRV-104).
- Création d'une liste : `CreateShoppingListHandler` ne touche pas une liste `SENT`. Une liste `SENT` reste jusqu'à `complete` ou suppression.

### 4.5 Déploiement sûr

Le retour arrière ne défait ni le schéma ni les données (ADR 0017 règle 6) et l'ancien code ne connaît pas `SENT` (`ShoppingListStatus::from('SENT')` lèverait une erreur). D'où l'interrupteur `DRIVE_ENABLED` coupé par défaut : tant qu'il est coupé, `send` répond `404 DRIVE_DISABLED` et aucune ligne `SENT` n'existe. L'humain l'active après un déploiement stable. Retour arrière avec des listes `SENT` : `UPDATE shopping_list SET status = 'ACTIVE', sent_at = NULL WHERE status = 'SENT';` (à écrire dans le runbook, DRV-502).

## 5. Contexte `Drive`

### 5.1 Modèle (domaine PHP pur, `src/Drive/Domain/`)

- `Retailer` (enum, `LECLERC`) : porte la règle d'hôte autorisé (`https://<sous-domaine>.leclercdrive.fr/magasin-<chiffres>-<chiffres>`) et la construction de l'URL de recherche.
- `Store` (valeur) : `id`, `label` (nullable), `baseUrl`.
- `DriveSettings` : `userId`, `retailer`, `store`.
- `Dimension` (enum `Repas\Shared\Domain\Model\Dimension`, partagée avec `Repas` pour `Unit`) : `WEIGHT`, `VOLUME`, `COUNT`, `PACKAGE`. `ContentUnit` (enum de `Drive`) : `G` (WEIGHT, 1), `KG` (WEIGHT, 1000), `ML` (VOLUME, 1), `CL` (VOLUME, 10), `L` (VOLUME, 1000), `PIECE` (COUNT, 1).
- `ProductContent` (valeur) : `value` (float > 0), `unit` (`ContentUnit`).
- `Product` : `id` (uuid), `retailer`, `storeId`, `retailerProductId`, `ean` (nullable), `label`, `content` (nullable), `lastSeenAt`. **Prix (décision D22)** : `priceCents` (entier, prix unitaire TTC en centimes, nullable), `pricePerMeasure` (`{cents, unit}` avec `unit` ∈ `kg|l|piece`, nullable) et `priceSeenAt` ; mis à jour à chaque vue par l'agent (le message de résultat porte `price` : `{cents, perMeasure?: {cents, unit}}` quand l'agent l'a lu) ; colonnes ajoutées à `drive_product` ; destinés à une estimation datée du coût d'une liste (backlog DRV-611), jamais présentés comme un prix garanti. Clé naturelle `(retailer, storeId, retailerProductId)`. `storeId` fait partie de la clé tant que le spike DRV-002 n'a pas montré que les identifiants sont communs à tous les magasins.
- `Choice` (agrégat, privé) : `id`, `userId`, `retailer`, `storeId`, `ingredientSlug`, `kind` (`PRODUCTS` | `HAVE_AT_HOME`), `accepted` (liste de `AcceptedProduct{productId, rank}`, rangs 1..n contigus, **5 au plus**), `rejected` (liste de `productId`, **20 au plus**), `updatedAt`. Unique `(userId, retailer, storeId, ingredientSlug)`. Invariants dans le domaine : un produit n'est jamais à la fois accepté et refusé, un produit n'apparaît qu'une fois, les rangs restent contigus, `HAVE_AT_HOME` n'a ni produit accepté ni refusé. Comportements : `addPrimary(productId)` (rang 1, les autres descendent d'un rang ; déjà présent : remonté), `addFallback(productId)` (à la fin ; déjà présent : inchangé), `reject(productId)` (retiré des acceptés, ajouté aux refusés), `unreject`, `remove`, `reorder(list<productId>)`, `markHaveAtHome()`, `resumeBuying()`. Quand le dernier produit accepté est retiré, le choix reste (ses refusés comptent) et la ligne redevient `NEW` dans le plan.
- `PackCalculator` : § 6.

Conventions du dépôt : classes `final`, `create()` / `load()`, ids et dates injectés (`UuidGenerator`, `Clock`), `ModelInterface` + `ModelTrait`, collections `Tab`, exceptions `DriveException` avec factories statiques.

### 5.2 Tables (PostgreSQL, aucune clé étrangère)

```
drive_settings(user_id VARCHAR(36), retailer VARCHAR(20), store_id VARCHAR(20), store_label VARCHAR(255) NULL,
               base_url VARCHAR(255), updated_at TIMESTAMP)                 -- PK (user_id, retailer)
drive_product(id VARCHAR(36) PK, retailer VARCHAR(20), store_id VARCHAR(20), retailer_product_id VARCHAR(40),
              ean VARCHAR(14) NULL, label VARCHAR(255), content_value NUMERIC(10,3) NULL, content_unit VARCHAR(8) NULL,
              last_seen_at TIMESTAMP)                                       -- UNIQUE (retailer, store_id, retailer_product_id), INDEX (ean)
drive_choice(id VARCHAR(36) PK, user_id VARCHAR(36), retailer VARCHAR(20), store_id VARCHAR(20),
             ingredient_slug VARCHAR(255), kind VARCHAR(16), updated_at TIMESTAMP)
                                                                           -- UNIQUE (user_id, retailer, store_id, ingredient_slug)
drive_choice_product(choice_id VARCHAR(36), product_id VARCHAR(36), verdict VARCHAR(8), rank SMALLINT NULL)
                                                                           -- PK (choice_id, product_id) ; verdict ACCEPTED (rank 1..5) ou REJECTED (rank NULL)
                                                                           -- UNIQUE (choice_id, rank) ; INDEX (product_id)
```

Les champs Doctrine `userId` correspondent à l'expression de `UserOwnedDataCoverageTest` (`/^(owner|author|creator|user)(Id)?$/i`) : `DriveSettings` et `Choice` doivent y être listés et purgés avec le compte. `drive_choice_product` n'a pas de colonne utilisateur (le test ne la voit pas) mais **doit être purgée aussi**, par les identifiants des choix de l'utilisateur, sinon des lignes orphelines restent (aucune clé étrangère) : un test de base vérifie qu'il n'en reste aucune. `drive_product` n'a pas de propriétaire : rien à purger. Les migrations sont **compatibles avec la version précédente du code** (nouvelles tables, colonnes nullables).

### 5.3 Cas d'usage

Écritures (`Application/Command/{UseCase}/`, `#[AsMessageHandler]`) : `SaveDriveSettings`, `SaveProductChoices` (lot ≤ 200 d'**intentions** issues du résultat de l'agent, fusionnées dans les préférences existantes : voir le tableau des actions ci-dessous ; upsert des `Product` puis des `Choice` dans la transaction du `command.bus`), `ReplaceIngredientPreference` (remplace d'un coup la préférence d'un ingrédient depuis l'écran de gestion : produits acceptés dans l'ordre, refusés, « j'en ai toujours »), `ForgetChoice` (oublie toute la préférence d'un ingrédient), `PurgeDriveData` (appelé par l'écouteur de `UserDeletedEvent`, comme `PurgeUserDataOnUserDeletedListener` côté `Repas`).

Actions d'un lot `SaveProductChoices` (par ingrédient, appliquées dans l'ordre reçu) :

| `action` | Données | Effet sur la préférence |
|---|---|---|
| `ADD_PRODUCT` | `product`, `placement` ∈ `PRIMARY` \| `FALLBACK` | `PRIMARY` : le produit passe en rang 1 (les autres descendent) ; `FALLBACK` : ajouté à la fin (si la limite de 5 est atteinte : `422`). Un produit refusé qu'on ajoute est retiré des refusés. |
| `REJECT_PRODUCT` | `product` | Retiré des acceptés (rangs recompactés), ajouté aux refusés. |
| `HAVE_AT_HOME` | — | `kind = HAVE_AT_HOME`, produits effacés. |

Un passage « seulement cette fois » (`ONCE`) n'envoie rien : le produit reste hors des préférences.

Lectures (`Application/Query/{UseCase}/`, `#[AsMessageHandler(bus: 'query.bus')]`) : `GetDriveSettings`, `ListChoices`, `GetDrivePlan`.

Ports : `DriveSettingsRepository`, `ProductRepository`, `ChoiceRepository` (écriture) ; `DriveSettingsLoader`, `ChoiceLoader` (lecture) ; côté `Shared` : `ShoppingListForDriveLoader::get(string $shoppingListId): ShoppingListForDrive` où `ShoppingListForDrive` = `{id, ownerId, status, version, lines: list<ShoppingListLineForDrive>}` et `ShoppingListLineForDrive` = `{ingredientSlug, ingredientName, quantity: float, unitSlug, unitSymbol, unitDimension: ?string, unitBaseFactor: ?float}`. Implémentation : `Repas\Infrastructure\Loader\ShoppingListForDrivePostgreSQLLoader` (précédent : `OwnershipLoader` / `OwnershipPostgreSQLLoader`). Une liste inconnue lève `ShoppingListException::shoppingListNotFound` (404). Second port `Shared\Application\Interface\IngredientNameLoader::namesBySlug(array $slugs): array` (slug → nom) pour l'écran des produits mémorisés.

Interrupteur : port `Shared\Application\Interface\DriveFeature::isEnabled(): bool`, implémenté dans `Shared\Infrastructure` (`Shared\Infrastructure\Feature\EnvDriveFeature`, variable `DRIVE_ENABLED`, booléen). Les contrôleurs `send`, `reopen` et tous ceux de `Drive` portent l'attribut `#[Repas\Shared\Infrastructure\Http\RequiresDrive]` : `DriveEnabledListener` (`kernel.controller`) leur fait répondre `404 DRIVE_DISABLED` (`DriveFeatureException::disabled()`) quand il est coupé, après la sécurité (401 d'abord) et avant la résolution des arguments (pas de 422). Sauf `GET /drive/settings` (renvoie `enabled: false`, sans l'attribut). Dans les tests, `DRIVE_ENABLED=true` (`.env.test` et `phpunit.dist.xml`) et `Repas\Tests\Helper\ControlledDriveFeature` remplace le service pour essayer le cas coupé.

## 6. Calcul des conditionnements (`PackCalculator`)

Entrées : une ligne de liste (quantité dans l'unité d'achat, dimension et facteur de base de cette unité) et le contenu éventuel du produit. Sortie : `packs` (entier 1..99 ou `null`) et `packsStatus` (`COMPUTED` | `TO_CHECK`).

1. Unité de la ligne `PACKAGE` : `packs = ceil(quantité)`, `COMPUTED` (la liste compte déjà des produits).
2. Unité `COUNT` : contenu en pièces → `ceil(quantité / valeur)`, `COMPUTED` ; contenu absent → `ceil(quantité)`, `TO_CHECK`.
3. Unité `WEIGHT` ou `VOLUME` et contenu de même dimension → `ceil(quantité × facteurLigne / (valeur × facteurContenu))`, `COMPUTED`.
4. Tout autre cas (dimensions différentes, unité sans dimension, contenu inconnu) → `packs = null`, `TO_CHECK` : l'agent demande la quantité à l'utilisateur, 1 par défaut.
5. Arrondi supérieur avec tolérance de 1e-9, minimum 1, plafond 99.

`Unit` gagne `dimension` et `baseFactor` (nullables), amorcés par la migration : `gramme` WEIGHT 1 · `kilo` WEIGHT 1000 · `millilitre` VOLUME 1 · `centilitre` VOLUME 10 · `litre` VOLUME 1000 · `unite` COUNT 1 · `paquet`, `boite`, `sachet`, `bouteille`, `conserve`, `brique`, `pot`, `flacon`, `plaquette`, `tablette`, `buche` PACKAGE 1 · les autres (`cuillere-a-soupe`, `cuillere-a-cafe`, `tasse-a-cafe`, `verre`, `bol`, `tranche`, `cube`, `gousse`) restent `null`. Source des unités : `src/Repas/Infrastructure/DataFixture/Data/unit.json`.

Le contenu d'un produit est extrait par l'agent (adaptateur Leclerc) du libellé de seconde ligne ou de `prix unitaire ÷ prix par unité de mesure`, normalisé en `g`, `ml` ou `pièce`, `null` si incertain.

## 7. API `/api/v1`

Conventions : ADR 0007 (JWT, `{member,totalItems}` pour une liste, `204` ou `201 + Location` en écriture, erreurs `{error:{message}}`, `#[OA\…]` obligatoire), ADR 0016 (`ETag` / `If-Match`). Pour les cas métier le message d'erreur est un code en majuscules.

| Route | Contexte | Réponses |
|---|---|---|
| `POST /shopping-lists/{id}/send` | Repas | `204` ; `404 DRIVE_DISABLED` ; `409 SHOPPING_LIST_NOT_SENDABLE` ; `412` ; `422 SHOPPING_LIST_EMPTY` |
| `POST /shopping-lists/{id}/reopen` | Repas | `204` ; `404 DRIVE_DISABLED` ; `409 SHOPPING_LIST_NOT_SENT` ; `412` |
| écritures existantes sur une liste `SENT` | Repas | `409 SHOPPING_LIST_SENT` |
| lectures de liste (modifiées) | Repas | `status` peut valoir `SENT` ; `isSent`, `sentAt` ; lignes avec `ingredientSlug` et `unitSlug` |
| `POST /units`, `PUT /units/{slug}` (modifiés) | Repas | corps avec `dimension` (nullable) et `baseFactor` (nullable), ensemble ou pas du tout (`422` sinon, ou si le facteur n'est pas > 0) ; au `PUT`, un corps qui ne mentionne ni l'un ni l'autre garde les valeurs de l'unité, un corps qui en mentionne un remplace la paire (absent = `null`), `null`/`null` l'efface ; `GET /units` et `GET /units/{slug}` renvoient les deux champs |
| `GET /drive/settings` | Drive | `{enabled, retailer, store: {id,label,baseUrl} \| null}` |
| `PUT /drive/settings` | Drive | `204` ; `404 DRIVE_DISABLED` ; `422 DRIVE_STORE_INVALID` |
| `GET /drive/plans/{shoppingListId}` | Drive | plan (ci-dessous) ; `403` autre utilisateur ; `404` ; `409 DRIVE_NOT_CONFIGURED` ; `409 SHOPPING_LIST_NOT_SENT` |
| `GET /drive/choices?retailer=` | Drive | `{member:[{ingredientSlug, ingredientName, haveAtHome, accepted:[{rank, product}], rejected:[product], updatedAt}], totalItems}` pour le magasin courant |
| `PUT /drive/choices` | Drive | `204` ; lot d'intentions issues de l'agent (ci-dessous) ; `409 DRIVE_NOT_CONFIGURED` ou `DRIVE_STORE_MISMATCH` (le `storeId` n'est pas le magasin courant) ; `422 DRIVE_CHOICE_INVALID` (dont plus de 5 produits acceptés ou 20 refusés) |
| `PUT /drive/choices/{ingredientSlug}` | Drive | `204` ; remplace la préférence d'un ingrédient depuis l'écran de gestion : `{retailer, storeId, haveAtHome, accepted:[productId…] dans l'ordre, rejected:[productId…]}` ; `productId` = identifiant Repas du produit (celui renvoyé par `GET /drive/choices`) ; `422 DRIVE_CHOICE_INVALID` (produit inconnu, doublon, produit à la fois accepté et refusé, limites) ; mêmes `409` |
| `DELETE /drive/choices/{ingredientSlug}?retailer=&storeId=` | Drive | `204` ; oublie toute la préférence ; `404 DRIVE_CHOICE_NOT_FOUND` |

**Lot `PUT /drive/choices`** : `{retailer, storeId, choices:[{ingredientSlug, action, placement?, product?}]}` avec `action` ∈ `ADD_PRODUCT` (`product` et `placement` ∈ `PRIMARY`|`FALLBACK` requis), `REJECT_PRODUCT` (`product` requis), `HAVE_AT_HOME` (rien d'autre). `product` = `{retailerProductId, ean?, label, content?: {value, unit}}` (`unit` ∈ `g|kg|ml|cl|l|piece`). Effets : § 5.3. Dans les réponses de lecture, un produit est `{productId, retailerProductId, ean, label, content}`.

**Plan** (`DrivePlanDTO`, `final readonly`) :

```json
{
  "shoppingListId": "0b0a8c4e-6b0e-4e0a-9d52-2c3f7a1d9e10",
  "listVersion": 5,
  "retailer": "leclerc",
  "store": {"id": "053701", "label": null, "baseUrl": "https://fd9-courses.leclercdrive.fr/magasin-053701-053701"},
  "generatedAt": "2026-10-17T10:00:00+02:00",
  "counts": {"known": 12, "new": 3, "haveAtHome": 2},
  "lines": [
    {"ingredientSlug": "farine", "ingredientName": "Farine",
     "needed": {"quantity": 300, "unitSymbol": "g"},
     "status": "KNOWN",
     "products": [
       {"rank": 1, "retailerProductId": "123456", "ean": null, "label": "Farine de blé T55 Marque Repère 1 kg",
        "content": {"value": 1000, "unit": "g"}, "packs": 1, "packsStatus": "COMPUTED"},
       {"rank": 2, "retailerProductId": "222333", "ean": null, "label": "Farine de blé T45 1 kg",
        "content": {"value": 1000, "unit": "g"}, "packs": 1, "packsStatus": "COMPUTED"}
     ],
     "rejected": [{"retailerProductId": "999000", "label": "Farine complète bio 1 kg"}],
     "searchQuery": "farine"},
    {"ingredientSlug": "poivre", "ingredientName": "Poivre", "needed": {"quantity": 1, "unitSymbol": "paquet"},
     "status": "NEW", "products": [], "rejected": [], "searchQuery": "poivre"}
  ]
}
```

Statuts de ligne : `KNOWN` (au moins un produit accepté pour ce magasin ; `products` est classé par rang croissant, 5 au plus, chaque produit avec **ses propres** `packs` et `packsStatus` car les contenus diffèrent), `NEW` (aucun produit accepté ; `rejected` peut quand même contenir des produits refusés), `HAVE_AT_HOME` (ligne ignorée par l'agent). `rejected` : 20 au plus.

## 8. Protocole de l'agent (v1)

Les schémas JSON font foi (`drive-agent/protocol/`, ticket DRV-302). Exemples et règles.

**Transport** : fragment d'URL. Aller : `<baseUrl>#repas=<base64url(JSON)>` (sans bourrage `=`). Retour : `<origine de Repas>/drive/retour#result=<base64url(JSON)>`. Un fragment ne part jamais vers un serveur (ni journal Caddy, qui enregistre l'URI complète, ni CORS). Taille visée < 16 Ko. Presse-papiers en secours si les spikes l'imposent.

**Message de plan** (front → agent) :

```json
{
  "v": 1, "type": "plan", "runId": "8f14e45f-ceea-467a-9575-8e6b8c0a1b2c",
  "retailer": "leclerc", "store": {"id": "053701", "host": "fd9-courses.leclercdrive.fr"},
  "returnPath": "/drive/retour",
  "lines": [
    {"ingredientSlug": "farine", "name": "Farine", "status": "KNOWN",
     "products": [
       {"id": "123456", "label": "Farine de blé T55 Marque Repère 1 kg", "content": {"value": 1000, "unit": "g"},
        "packs": 1, "packsStatus": "COMPUTED"},
       {"id": "222333", "label": "Farine de blé T45 1 kg", "content": {"value": 1000, "unit": "g"},
        "packs": 1, "packsStatus": "COMPUTED"}
     ],
     "rejected": [{"id": "999000", "label": "Farine complète bio 1 kg"}], "searchQuery": "farine"},
    {"ingredientSlug": "poivre", "name": "Poivre", "status": "NEW", "products": [], "rejected": [], "searchQuery": "poivre"},
    {"ingredientSlug": "sel", "name": "Sel", "status": "HAVE_AT_HOME"},
    {"ingredientSlug": "huile", "name": "Huile", "status": "SKIP"},
    {"ingredientSlug": "lait", "name": "Lait", "status": "NEW", "products": [], "rejected": [],
     "searchQuery": "lait demi-écrémé", "replaces": {"id": "98765", "label": "Lait UHT 1 L"}}
  ]
}
```

`products` est classé par priorité (le premier est préféré ; 5 au plus), `rejected` liste les produits que l'utilisateur ne veut jamais (20 au plus). Le front traduit `retailerProductId` du plan du back en `id`.

`type: "setup"` : mêmes champs sans `lines` ; l'agent lit le magasin courant et le renvoie dans un message `setup-result` : `{"v":1,"type":"setup-result","runId":"…","agentVersion":"1.0.0","store":{"id":"053701","host":"fd9-courses.leclercdrive.fr","baseUrl":"https://fd9-courses.leclercdrive.fr/magasin-053701-053701","label":null}}`. `SKIP` = « passer cette fois » choisi par l'utilisateur sur l'écran du plan (non mémorisé). Un `KNOWN` que l'utilisateur veut changer devient `NEW` avec `replaces`.

**Validation par l'agent avant toute action** : `v === 1` ; `runId` uuid ; `retailer` autorisé ; hôte de la page ∈ liste autorisée **et** magasin du message = magasin de la page (sinon `STORE_MISMATCH`) ; ≤ 100 lignes ; `ingredientSlug` `^[a-z0-9-]{1,100}$` ; identifiants de produit `^[0-9]{1,12}$` ; `products` ≤ 5 sans doublon, `KNOWN` en exige au moins 1 et `NEW` n'en a aucun ; `rejected` ≤ 20 ; aucun produit à la fois dans `products` et `rejected` ; `packs` entier 1..99 ou `null` ; chaînes bornées (libellés ≤ 255) ; tout affichage par `textContent` (jamais de HTML) ; `returnPath` ∈ `["/drive/retour"]` ; l'origine de retour est **écrite en dur** dans le favori, jamais lue dans le message. Le gabarit de l'agent contient un marqueur unique `__REPAS_ORIGIN__` que le front remplace par `location.origin` quand l'utilisateur copie le code du favori (DRV-301, DRV-403) : l'image de production du front ne contient ainsi aucun nom de domaine.

**Message de résultat** (agent → front) :

```json
{
  "v": 1, "type": "result", "runId": "8f14e45f-ceea-467a-9575-8e6b8c0a1b2c", "agentVersion": "1.0.0",
  "outcome": "completed", "store": {"id": "053701"},
  "lines": [
    {"ingredientSlug": "farine", "outcome": "ADDED", "productId": "222333", "usedRank": 2, "quantity": 1,
     "unavailableIds": ["123456"]},
    {"ingredientSlug": "poivre", "outcome": "CHOSEN", "quantity": 1, "placement": "PRIMARY",
     "product": {"id": "555111", "ean": null, "label": "Poivre noir moulu 50 g", "content": {"value": 50, "unit": "g"}}},
    {"ingredientSlug": "lait", "outcome": "CHOSEN", "quantity": 1, "placement": "FALLBACK",
     "product": {"id": "444222", "ean": null, "label": "Lait demi-écrémé UHT 1 L", "content": {"value": 1, "unit": "l"}},
     "unavailableIds": ["98765"],
     "rejected": [{"id": "98765", "label": "Lait UHT 1 L"}]},
    {"ingredientSlug": "sel", "outcome": "HAVE_AT_HOME"},
    {"ingredientSlug": "huile", "outcome": "SKIPPED"},
    {"ingredientSlug": "riz", "outcome": "UNAVAILABLE", "unavailableIds": ["31415"]},
    {"ingredientSlug": "beurre", "outcome": "FAILED", "error": "BLOCKED"}
  ]
}
```

`outcome` global : `completed` | `partial` | `aborted` | `error` (avec `error: {code, message}`). Codes : `NOT_LOGGED_IN`, `STORE_MISMATCH`, `BLOCKED`, `CONTRACT_CHANGED`, `NETWORK`, `CANCELLED`, `INVALID_MESSAGE`, `UNSUPPORTED_PAGE`. Issues de ligne :
- `ADDED` : un produit déjà connu a été mis au panier ; `usedRank` (1 = le préféré, 2 ou plus = produit de secours) et `unavailableIds` (ceux qui le précèdent et étaient indisponibles).
- `CHOSEN` : l'utilisateur a choisi un produit sur Leclerc, ajouté au panier ; `placement` ∈ `PRIMARY` (nouveau produit préféré), `FALLBACK` (produit de secours), `ONCE` (cette fois seulement, rien à mémoriser).
- `HAVE_AT_HOME` : « j'en ai toujours » (à mémoriser). `SKIPPED` : passé cette fois. `UNAVAILABLE` : aucun produit disponible et l'utilisateur n'en a pas choisi d'autre. `FAILED` : erreur (`BLOCKED`, `NETWORK`…).
- Toute ligne peut porter `rejected` : les produits que l'utilisateur a choisi de ne plus jamais prendre (avec libellé et contenu pour pouvoir les mémoriser). `unavailableIds` ne sert qu'à l'affichage du récapitulatif, jamais à la mémorisation.

**Comportement de l'agent**
1. Au lancement, lit `location.hash` (`repas=`). Absent : écran « ouvre d'abord Repas » + accès au diagnostic.
2. Valide le message, affiche un récapitulatif et demande confirmation (rien n'est envoyé avant).
3. **Phase A** : pour chaque ligne `KNOWN`, essaie les produits **dans l'ordre de priorité** : le premier **disponible** est mis au panier avec sa quantité absolue (`packs` de ce produit ; `packsStatus` `TO_CHECK` ou `packs` nul : demande la quantité). Si le préféré est indisponible, l'agent passe au suivant sans rien demander (produit de secours) et le note (`usedRank`, `unavailableIds`) ; il n'ajoute **jamais** un produit refusé ni un produit absent de la liste. La disponibilité se lit par la méthode retenue au spike (DRV-002) : stock de la recherche par libellé (`iQteDisponible`) ou réponse du panier. **Si aucun produit de la liste n'est disponible**, la ligne passe en phase B avec « Ces produits ne sont plus disponibles : … ».
4. **Phase B** : pour chaque ligne `NEW` (jamais de produit, tous indisponibles, ou « autre produit » demandé), l'utilisateur choisit un produit (P1 ou P2 ci-dessous) ou « Passer cette fois » ou « J'en ai toujours » ; après un choix, l'agent calcule le nombre de produits (contenu du produit choisi, quantité demandée), le montre (« 800 g demandés, 500 g par produit : 2 ») et fixe la quantité au panier. **Rôle du produit choisi** : s'il n'y avait aucun produit pour cet ingrédient, il devient le préféré sans question (`PRIMARY`) ; sinon l'agent demande « Que fais-tu de <produit> ? » — « Mon nouveau produit préféré » (`PRIMARY`, les autres restent derrière), « Produit de secours » (`FALLBACK`, après les autres), « Seulement cette fois » (`ONCE`). Pour chaque produit devenu indisponible, une case « Ne plus jamais prendre <produit> » (le produit part dans `rejected`). Un produit refusé figure grisé et non sélectionnable dans la liste P2 ; en P1, si l'utilisateur ajoute un produit refusé, l'agent prévient et propose de le retirer du panier ou de le garder cette fois.
5. Construit le résultat et navigue vers l'origine de Repas.
- **Priorités et substitution** : l'agent ne choisit jamais seul un produit « équivalent » absent de la liste de l'utilisateur ; une substitution automatique (suggestion) est au backlog (DRV-601) et exclura toujours les produits refusés.
- **P1 (recommandée, à confirmer à G1)** : un `iframe` plein écran vers `recherche.aspx?TexteRecherche=<searchQuery>` du même hôte ; l'agent reste dans la page parente et repère le produit que l'utilisateur ajoute. **P2 (repli)** : l'agent interroge `recherche.aspx`, lit le JSON embarqué et affiche lui-même les candidats.
- **Cadence** : ≥ 800 ms entre requêtes avec gigue jusqu'à +700 ms, jamais en parallèle ; arrêt après 2 réponses 403/429 consécutives (`partial` + `BLOCKED`) ; une seule reprise sur erreur réseau.
- **Écran allumé** (Wake Lock) pendant le passage ; état de reprise dans `sessionStorage` (`repas:run:<runId>`).
- **Interface** : Shadow DOM, cibles ≥ 44 px, `aria-live` pour la progression, textes en français, jamais de HTML injecté.
- **Mode diagnostic** : auto-test OK/KO copiable, sans donnée personnelle.

## 9. Front

- Routes : `/drive/reglages` (magasin, favori, produits mémorisés avec leur ordre de priorité et leurs produits refusés, « j'en ai toujours »), `/drive/envoi/:listId` (plan avec, pour chaque ligne, le produit préféré et ses produits de secours ; ouverture de Leclerc ; attente), `/drive/retour` (récapitulatif, avec les produits de secours utilisés).
- Une liste `SENT` s'affiche en lecture seule : bandeau « Envoyée au drive le … », actions « Reprendre l'envoi », « Terminer », « Rouvrir » (confirmation). `/liste` ne crée pas de liste tout seul quand il n'y a que des listes `SENT`.
- **Brouillon de passage** : `{runId, listId, startedAt}` dans `localStorage` (24 h), car l'utilisateur quitte Repas pour Leclerc et que le service worker de production recharge la page dès qu'il détecte une nouvelle version. Au retour, un `runId` différent du brouillon est ignoré avec un message.
- **Ouverture dans Chrome complet** : lien d'intention Android (`intent://<hôte>/<chemin>#Intent;scheme=https;package=com.android.chrome;end`) quand l'application est installée (`display-mode: standalone`), sinon lien normal ; à valider au spike DRV-002. Le favori ne s'exécute pas dans l'onglet intégré d'une PWA.
- Contraintes du front existant : pas de logique métier dans les composants (les conditionnements viennent du plan), jeton d'accès en mémoire, textes dans `src/i18n/fr.ts`, couleurs par jetons, CSP de production inchangée (aucune image externe : pas de vignettes Leclerc dans Repas en v1), tests Vitest + e2e Pixel 7, seuil de couverture 80 %.

## 10. Sécurité et vie privée

- Invariants du § 2. Garde-fous testables : aucun champ « mot de passe / cookie / jeton d'enseigne » dans `docs/api/openapi.json` ; aucune donnée du plan ou du résultat dans les logs ; l'API n'a ni plan ni choix en query string (Caddy journalise l'URI complète).
- L'agent s'exécute avec les droits de l'utilisateur sur leclercdrive.fr : pas de code distant, message validé et plafonné, confirmation visible avant d'agir, origine de retour en dur. Un lien piégé peut au pire remplir un panier de produits inutiles, sans commande ni paiement.
- `Product` est alimenté par les agents des utilisateurs : validation côté serveur (identifiants numériques, libellé borné, contenu plausible), mise à jour par clé, aucune donnée personnelle. `Choice` peut révéler régime ou santé : minimisation, purge à la suppression du compte, pas de statistiques tierces.
- DataDome peut signaler le compte ou l'IP de l'utilisateur : requêtes en série, pauses, pas de moissonnage, information avant usage, interrupteur.
- Rupture du site : détection `CONTRACT_CHANGED`, message clair, repli en mode assisté.

## 11. Tests et qualité

- Back : définition de fini du skill `repas-dev` (`make test`, `make deptrac`, `make phpstan`, `make api-doc-validate api-doc-coverage openapi-check`, scénarios `WriteBudget` pour `SendShoppingList`, `ReopenShoppingList`, `SaveDriveSettings`, `SaveProductChoices`, `ForgetChoice`, budget de `supprimer-compte` revu). Handlers en unitaire avec doubles en mémoire ; loaders sur base réelle ; contrôleurs avec `MockCommandBus` / `MockQueryBus` ; un parcours de bout en bout par ressource ; `PackCalculator` en tests à table.
- Agent : tests unitaires sur fixtures **synthétiques** (jamais de HTML Leclerc brut dans un dépôt public) ; faux site Leclerc (DRV-309) et tests e2e Playwright.
- Front : Vitest, e2e Pixel 7 avec un agent simulé, accessibilité, test visuel Chromium (captures lues).
- Recette sur le téléphone réel (humain, DRV-505) : le faux site ne prouve pas DataDome.

## 12. Déploiement et exploitation

- Ordre : (1) back avec `DRIVE_ENABLED=false` ; (2) front ; (3) l'humain active `DRIVE_ENABLED=true` dans `/opt/repas/.env` (variable listée avec la valeur par défaut `false` : `${DRIVE_ENABLED:-false}`, dans `deploy/compose.yaml`, puis dans `deploy/.env.example` et `docs/production.md`) après un déploiement stable. L'approbation du déploiement reste à l'humain (ADR 0017).
- Retour arrière : § 4.5 ; le schéma reste.
- Panne ou changement de Leclerc : interrupteur, runbook (DRV-503), nouvelle version du favori à réinstaller.
- Sauvegardes : les nouvelles tables sont dans les `pg_dump` ; aucun secret d'enseigne n'existe.

### Ajout du 2026-10-18 : champ `needed` d'une ligne du plan

Une ligne du message `plan` peut porter `needed : {quantity, unitSymbol, dimension?, baseFactor?}` (la quantité demandée par la liste, avec la dimension et le facteur de base de l'unité). Sans lui, l'agent ne peut pas calculer le nombre de produits en phase B et demande la quantité. Le back le fournit dans le plan (DRV-206, le `PackCalculator` les connaît) et le front le recopie (DRV-404). Les schémas JSON de `drive-agent/protocol/` font foi. Une ligne `ADDED` du résultat peut aussi porter `price` (D22).
