# Plusieurs enseignes de drive : audit du couplage à Leclerc et conception cible

> **Lancé le 2026-10-30** : la pause du 2026-10-20 est levée, le propriétaire démarre Auchan (« on va commencer à gérer Auchan »). Les spikes sont faits ([`spikes/DRV-712-rapport.md`](spikes/DRV-712-rapport.md), [713](spikes/DRV-713-rapport.md), [714](spikes/DRV-714-rapport.md)) et la décision est prise : **adaptateur complet** ([`spikes/DRV-715-decision-auchan.md`](spikes/DRV-715-decision-auchan.md)). Faits mesurés : [`hypotheses-site-reel-auchan.md`](hypotheses-site-reel-auchan.md). Les § 11 à 14 ci-dessous gardent le raisonnement d'origine (2026-10-19) ; leur § 11.4 et la décision priment. Règle active : ne jamais dépendre de l'implémentation de Leclerc dans le nouveau code ; Auchan reste **éteint** tant que l'humain ne l'allume pas.

Cadrage du 2026-10-19 (jalon M7 de [`roadmap.md`](roadmap.md)), rédigé par l'assistant de cadrage ; **aucun code de produit n'est écrit ici**. Décision d'architecture : [ADR 0027](../adr/0027-plusieurs-enseignes-de-drive.md) (`Accepted` le 2026-10-30, amendée le même jour). Tickets : [`DRV-701` à `DRV-717`](tickets/). État lu : back `main` au commit `556e63f`, front `main` au commit `5b8779f`.

## 1. Le besoin

Demande de l'utilisateur, mot pour mot : « on fera le drive Auchan (dans le futur, quand le drive Leclerc sera fini) mais du coup pense déjà à découpler et à ne pas être trop proche du drive Leclerc : on devra activer des drives différents, Leclerc, Auchan, et on devra choisir quel drive utiliser selon ce qu'on a déjà configuré dans nos paramètres. »

Ce que cela veut dire, en quatre phrases :

1. **Plusieurs drives peuvent coexister.** Une personne peut avoir configuré Leclerc, Auchan, ou les deux.
2. **Repas choisit le drive à utiliser** d'après ce que la personne a déjà configuré dans « Moi », sans lui faire répéter ce qu'il sait ([`principes-produit.md`](principes-produit.md) règles 3, 5, 6).
3. **Le code actuel ne doit pas durcir** son lien avec Leclerc : tout ce qui nomme Leclerc doit passer par un point unique, pour que la deuxième enseigne soit une entrée de plus et non une réécriture.
4. **Tant qu'il n'y a qu'une enseigne, rien ne change pour l'utilisateur** : le découplage est un chantier d'ingénierie, pas de produit.

**Ce qui est livré par ce cadrage** : l'audit (§ 3), la conception cible (§ 4 à § 8), le plan de découplage en tickets (§ 10), les faits connus sur Auchan et leurs inconnues (§ 11), les spikes à faire sur un téléphone (§ 12). **Ce qui ne l'est pas** : l'adaptateur Auchan lui-même (il dépend des spikes, DRV-717 n'en pose que le squelette), et toute décision sur l'ordre de lancement par rapport à la fin de la recette de Leclerc (voir § 13).

## 2. Principes

1. **Une enseigne est une donnée, pas une branche de code.** Identifiant, nom, règle d'adresse du magasin, hôtes autorisés, particularités : un registre. Ajouter une enseigne = ajouter une entrée de registre + un adaptateur d'agent, jamais un `if ($retailer === …)` dans un handler (OCP, [`CLAUDE.md`](../../CLAUDE.md) § SOLID).
2. **Les invariants du chantier ne changent pas** ([`architecture.md`](architecture.md) § 2) : aucun identifiant, cookie ni jeton d'enseigne chez nous ; le back ne contacte jamais une enseigne ; pas de code distant ; l'agent ne valide ni ne paie jamais ; le panier d'une enseigne n'est jamais vidé ni réduit. Ils valent **pour chaque enseigne séparément**.
3. **Compatible avec ce qui est installé.** Le site web se met à jour tout seul, l'application Android non ([DRV-640](tickets/DRV-640-version-minimale-pour-le-drive.md)) : tout ajout au protocole v1 est **facultatif** et une application qui ne connaît pas une enseigne ne reçoit jamais de plan pour elle (§ 8).
4. **Un seul point par couche.** Si un nom d'enseigne apparaît ailleurs que dans le registre de sa couche, c'est un défaut (un test le garde : § 10, DRV-701, DRV-704, DRV-706, DRV-708).
5. **Ne rien présumer d'Auchan.** Les faits sur Auchan sont des hypothèses sourcées (§ 11) tant qu'un spike sur téléphone ne les a pas confirmés.

## 3. Audit du couplage actuel à Leclerc

Lu le 2026-10-19 dans les deux dépôts. « Nomme Leclerc » = le mot, un hôte, un motif d'adresse ou une forme propre au site de Leclerc. Les chemins sont ceux de `main`.

### 3.1 Bonne nouvelle : le modèle de données connaît déjà l'enseigne (vérifié)

| Table (back) | Clé | Verdict |
|---|---|---|
| `drive_settings` | PK `(user_id, retailer)` | Un réglage **par utilisateur et par enseigne** : plusieurs enseignes coexistent déjà en base. `src/Drive/Infrastructure/Entity/DriveSettings.php`, `DriveSettingsRepository::findOneByUser($userId, $retailer)`. |
| `drive_product` | UNIQUE `(retailer, store_id, retailer_product_id)` | Un produit d'une enseigne ne peut pas heurter celui d'une autre. Les prix et le contenu restent des faits publics **par enseigne et magasin**. |
| `drive_choice` | UNIQUE `(user_id, retailer, store_id, ingredient_slug)` | Les choix mémorisés (produits habituels, remplacements, produits refusés, « j'en ai toujours ») sont **par enseigne et par magasin** : rien n'est mélangé. La recherche mémorisée de DRV-650 (`search_query`, le texte qui a fait trouver le produit choisi) vit sur cette même ligne : elle est donc propre à l'enseigne, jamais reprise d'une enseigne à l'autre. `ChoicePostgreSQLRepository::findByUserStoreAndSlugs` filtre déjà sur `retailer`. |
| `drive_choice_product` | PK `(choice_id, product_id)` | Dépend du choix, donc de l'enseigne. La contenance mémorisée de DRV-652 (`pack_size_value`, `pack_size_unit_slug` : « 20 tranches » dans ce produit) vit sur cette ligne : privée à l'utilisateur, propre à l'enseigne par le choix qui la porte, et exprimée en **slug d'unité de Repas** (rien de propre à une enseigne). |

Les colonnes `retailer` sont des `VARCHAR(20)` (assez pour `auchan`, `intermarche`). **Aucune migration de schéma n'est nécessaire pour les clés.** Les routes d'écriture portent déjà `retailer` dans le corps (`PUT /drive/settings`, `PUT /drive/choices`, `PUT /drive/choices/{slug}`) ou dans la requête (`GET` et `DELETE /drive/choices`). La purge du compte supprime par utilisateur, sans condition d'enseigne (`ChoicePostgreSQLRepository::deleteByUser`, `DriveSettingsRepository::deleteByUser`). Le verrou `SENT` d'une liste est **indépendant de l'enseigne** (`ShoppingList` n'a pas de champ enseigne ; `send` ne prend aucun paramètre d'enseigne).

### 3.2 Back (`kcharignon/repas`)

| Où | Couplage | À généraliser |
|---|---|---|
| `src/Drive/Domain/Model/Retailer.php` | Enum à un seul cas `LECLERC`. `LECLERC_STORE_URL` (`#^https://[a-z0-9-]+\.leclercdrive\.fr/magasin-(\d+)-(\d+)$#D`) et un `match` à un seul bras dans `storeIdOf()`. | Le registre : nom affiché, règle d'adresse du magasin, motif d'identifiant produit, drapeaux. L'enum reste la liste des identifiants. **DRV-701**. |
| `src/Drive/Infrastructure/Loader/DriveSettingsPostgreSQLLoader.php` l. 16, `DrivePlanPostgreSQLLoader.php` l. 41, `DriveEstimatePostgreSQLLoader.php` l. 38 | `private const Retailer RETAILER = Retailer::LECLERC` (« une seule enseigne pour l'instant : la lecture ne porte pas encore de paramètre `retailer` »). **Les trois seules lectures qui figent Leclerc.** | L'enseigne devient un paramètre de la requête (`GetDrivePlanQuery`, `GetDriveEstimateQuery`, `GetDriveSettingsQuery`). **DRV-701**. |
| `GET /drive/settings` (`DriveSettingsDTO`) | Une seule enseigne et un seul magasin : `{enabled, retailer, store}`. | Ajouter, sans retirer, la liste des enseignes et la sélection (§ 5.4). **DRV-701**, **DRV-703**. |
| `GET /drive/plans/{id}`, `GET /drive/estimates/{id}` | Pas de paramètre d'enseigne. | `?retailer=` facultatif (enseigne courante par défaut). **DRV-701**. |
| `src/Drive/Domain/Model/Store.php` | `baseUrl` obligatoire, validée par la règle d'adresse de l'enseigne ; `id` ≤ 20 caractères. Vrai pour Leclerc (un magasin = une adresse). | Auchan : identifiant de magasin **explicite** lu par `GET /journey` (UUID, adresse d'enseigne constante) : décision DRV-715, **DRV-720**. |
| `src/Drive/Domain/Model/Product.php` | ~~`RETAILER_PRODUCT_ID_PATTERN = ^[0-9]{1,12}# Plusieurs enseignes de drive : audit du couplage à Leclerc et conception cible

> **Lancé le 2026-10-30** : la pause du 2026-10-20 est levée, le propriétaire démarre Auchan (« on va commencer à gérer Auchan »). Les spikes sont faits ([`spikes/DRV-712-rapport.md`](spikes/DRV-712-rapport.md), [713](spikes/DRV-713-rapport.md), [714](spikes/DRV-714-rapport.md)) et la décision est prise : **adaptateur complet** ([`spikes/DRV-715-decision-auchan.md`](spikes/DRV-715-decision-auchan.md)). Faits mesurés : [`hypotheses-site-reel-auchan.md`](hypotheses-site-reel-auchan.md). Les § 11 à 14 ci-dessous gardent le raisonnement d'origine (2026-10-19) ; leur § 11.4 et la décision priment. Règle active : ne jamais dépendre de l'implémentation de Leclerc dans le nouveau code ; Auchan reste **éteint** tant que l'humain ne l'allume pas.

Cadrage du 2026-10-19 (jalon M7 de [`roadmap.md`](roadmap.md)), rédigé par l'assistant de cadrage ; **aucun code de produit n'est écrit ici**. Décision d'architecture : [ADR 0027](../adr/0027-plusieurs-enseignes-de-drive.md) (`Accepted` le 2026-10-30, amendée le même jour). Tickets : [`DRV-701` à `DRV-717`](tickets/). État lu : back `main` au commit `556e63f`, front `main` au commit `5b8779f`.

## 1. Le besoin

Demande de l'utilisateur, mot pour mot : « on fera le drive Auchan (dans le futur, quand le drive Leclerc sera fini) mais du coup pense déjà à découpler et à ne pas être trop proche du drive Leclerc : on devra activer des drives différents, Leclerc, Auchan, et on devra choisir quel drive utiliser selon ce qu'on a déjà configuré dans nos paramètres. »

Ce que cela veut dire, en quatre phrases :

1. **Plusieurs drives peuvent coexister.** Une personne peut avoir configuré Leclerc, Auchan, ou les deux.
2. **Repas choisit le drive à utiliser** d'après ce que la personne a déjà configuré dans « Moi », sans lui faire répéter ce qu'il sait ([`principes-produit.md`](principes-produit.md) règles 3, 5, 6).
3. **Le code actuel ne doit pas durcir** son lien avec Leclerc : tout ce qui nomme Leclerc doit passer par un point unique, pour que la deuxième enseigne soit une entrée de plus et non une réécriture.
4. **Tant qu'il n'y a qu'une enseigne, rien ne change pour l'utilisateur** : le découplage est un chantier d'ingénierie, pas de produit.

**Ce qui est livré par ce cadrage** : l'audit (§ 3), la conception cible (§ 4 à § 8), le plan de découplage en tickets (§ 10), les faits connus sur Auchan et leurs inconnues (§ 11), les spikes à faire sur un téléphone (§ 12). **Ce qui ne l'est pas** : l'adaptateur Auchan lui-même (il dépend des spikes, DRV-717 n'en pose que le squelette), et toute décision sur l'ordre de lancement par rapport à la fin de la recette de Leclerc (voir § 13).

## 2. Principes

1. **Une enseigne est une donnée, pas une branche de code.** Identifiant, nom, règle d'adresse du magasin, hôtes autorisés, particularités : un registre. Ajouter une enseigne = ajouter une entrée de registre + un adaptateur d'agent, jamais un `if ($retailer === …)` dans un handler (OCP, [`CLAUDE.md`](../../CLAUDE.md) § SOLID).
2. **Les invariants du chantier ne changent pas** ([`architecture.md`](architecture.md) § 2) : aucun identifiant, cookie ni jeton d'enseigne chez nous ; le back ne contacte jamais une enseigne ; pas de code distant ; l'agent ne valide ni ne paie jamais ; le panier d'une enseigne n'est jamais vidé ni réduit. Ils valent **pour chaque enseigne séparément**.
3. **Compatible avec ce qui est installé.** Le site web se met à jour tout seul, l'application Android non ([DRV-640](tickets/DRV-640-version-minimale-pour-le-drive.md)) : tout ajout au protocole v1 est **facultatif** et une application qui ne connaît pas une enseigne ne reçoit jamais de plan pour elle (§ 8).
4. **Un seul point par couche.** Si un nom d'enseigne apparaît ailleurs que dans le registre de sa couche, c'est un défaut (un test le garde : § 10, DRV-701, DRV-704, DRV-706, DRV-708).
5. **Ne rien présumer d'Auchan.** Les faits sur Auchan sont des hypothèses sourcées (§ 11) tant qu'un spike sur téléphone ne les a pas confirmés.

## 3. Audit du couplage actuel à Leclerc

Lu le 2026-10-19 dans les deux dépôts. « Nomme Leclerc » = le mot, un hôte, un motif d'adresse ou une forme propre au site de Leclerc. Les chemins sont ceux de `main`.

### 3.1 Bonne nouvelle : le modèle de données connaît déjà l'enseigne (vérifié)

| Table (back) | Clé | Verdict |
|---|---|---|
| `drive_settings` | PK `(user_id, retailer)` | Un réglage **par utilisateur et par enseigne** : plusieurs enseignes coexistent déjà en base. `src/Drive/Infrastructure/Entity/DriveSettings.php`, `DriveSettingsRepository::findOneByUser($userId, $retailer)`. |
| `drive_product` | UNIQUE `(retailer, store_id, retailer_product_id)` | Un produit d'une enseigne ne peut pas heurter celui d'une autre. Les prix et le contenu restent des faits publics **par enseigne et magasin**. |
| `drive_choice` | UNIQUE `(user_id, retailer, store_id, ingredient_slug)` | Les choix mémorisés (produits habituels, remplacements, produits refusés, « j'en ai toujours ») sont **par enseigne et par magasin** : rien n'est mélangé. La recherche mémorisée de DRV-650 (`search_query`, le texte qui a fait trouver le produit choisi) vit sur cette même ligne : elle est donc propre à l'enseigne, jamais reprise d'une enseigne à l'autre. `ChoicePostgreSQLRepository::findByUserStoreAndSlugs` filtre déjà sur `retailer`. |
| `drive_choice_product` | PK `(choice_id, product_id)` | Dépend du choix, donc de l'enseigne. La contenance mémorisée de DRV-652 (`pack_size_value`, `pack_size_unit_slug` : « 20 tranches » dans ce produit) vit sur cette ligne : privée à l'utilisateur, propre à l'enseigne par le choix qui la porte, et exprimée en **slug d'unité de Repas** (rien de propre à une enseigne). |

Les colonnes `retailer` sont des `VARCHAR(20)` (assez pour `auchan`, `intermarche`). **Aucune migration de schéma n'est nécessaire pour les clés.** Les routes d'écriture portent déjà `retailer` dans le corps (`PUT /drive/settings`, `PUT /drive/choices`, `PUT /drive/choices/{slug}`) ou dans la requête (`GET` et `DELETE /drive/choices`). La purge du compte supprime par utilisateur, sans condition d'enseigne (`ChoicePostgreSQLRepository::deleteByUser`, `DriveSettingsRepository::deleteByUser`). Le verrou `SENT` d'une liste est **indépendant de l'enseigne** (`ShoppingList` n'a pas de champ enseigne ; `send` ne prend aucun paramètre d'enseigne).

### 3.2 Back (`kcharignon/repas`)

| Où | Couplage | À généraliser |
|---|---|---|
| `src/Drive/Domain/Model/Retailer.php` | Enum à un seul cas `LECLERC`. `LECLERC_STORE_URL` (`#^https://[a-z0-9-]+\.leclercdrive\.fr/magasin-(\d+)-(\d+)$#D`) et un `match` à un seul bras dans `storeIdOf()`. | Le registre : nom affiché, règle d'adresse du magasin, motif d'identifiant produit, drapeaux. L'enum reste la liste des identifiants. **DRV-701**. |
| `src/Drive/Infrastructure/Loader/DriveSettingsPostgreSQLLoader.php` l. 16, `DrivePlanPostgreSQLLoader.php` l. 41, `DriveEstimatePostgreSQLLoader.php` l. 38 | `private const Retailer RETAILER = Retailer::LECLERC` (« une seule enseigne pour l'instant : la lecture ne porte pas encore de paramètre `retailer` »). **Les trois seules lectures qui figent Leclerc.** | L'enseigne devient un paramètre de la requête (`GetDrivePlanQuery`, `GetDriveEstimateQuery`, `GetDriveSettingsQuery`). **DRV-701**. |
| `GET /drive/settings` (`DriveSettingsDTO`) | Une seule enseigne et un seul magasin : `{enabled, retailer, store}`. | Ajouter, sans retirer, la liste des enseignes et la sélection (§ 5.4). **DRV-701**, **DRV-703**. |
| `GET /drive/plans/{id}`, `GET /drive/estimates/{id}` | Pas de paramètre d'enseigne. | `?retailer=` facultatif (enseigne courante par défaut). **DRV-701**. |
| `src/Drive/Domain/Model/Store.php` | `baseUrl` obligatoire, validée par la règle d'adresse de l'enseigne ; `id` ≤ 20 caractères. Vrai pour Leclerc (un magasin = une adresse). | Auchan : identifiant de magasin **explicite** lu par `GET /journey` (UUID, adresse d'enseigne constante) : décision DRV-715, **DRV-720**. |
~~ : **fait par DRV-701** : la forme d'un identifiant de produit est `productIdPattern` du profil de l'enseigne (`Retailer::isValidProductId()`), aussi dans `ProductInput` / `ProductPayload` ; la validation de l'agent la lit dans son registre (DRV-704). `EAN_PATTERN` est neutre. | Fait (DRV-701, 704). |
| `src/Drive/Domain/Model/DriveSettings.php` | Le compteur du jour des écritures de produits (`productWritesDay/Count`, plafond 5 000 : DRV-613) vit **dans la ligne de réglage**, donc **par utilisateur et par enseigne**. Avec deux enseignes, le plafond quotidien devient 5 000 **par enseigne**. | Décision D-M5 (§ 14) : l'accepter (borne = 5 000 × nombre d'enseignes activées, ce qui reste très petit) et l'écrire dans le test et le skill `repas-drive-back`. **DRV-701**. |
| OpenAPI : `enum: ['leclerc']` dans `DriveSettingsDTO`, `DrivePlanDTO`, `DriveEstimateDTO`, `DriveSettingsPayload`, `ReplaceIngredientPreferencePayload`, `SaveProductChoicesPayload`, et les deux `OA\Parameter` de `ListChoicesApiController` et `ForgetChoiceApiController` ; exemple d'adresse `…leclercdrive.fr…` dans `DriveStorePayload` et `DriveStoreDTO` ; 14 mentions dans `docs/api/openapi.json`. | Le contrat fige `leclerc`. | L'énumération se déduit du registre (un test garde la concordance), l'exemple devient neutre. **DRV-701**. |
| `DriveFeature` / `DRIVE_ENABLED` (`src/Shared/…`, `EnvDriveFeature`, `DriveEnabledListener`, `#[RequiresDrive]`) | Un interrupteur **global** : coupe toutes les enseignes à la fois. Bien pour une urgence générale, trop gros pour couper **une** enseigne qui a changé son site. | Interrupteur par enseigne, en plus (§ 7). **DRV-702**. |
| `tests/…` (176 occurrences de `Retailer::LECLERC`, `tests/Helper/Builder/*`, `docs/drive/samples/*.sample.json`, `tests/Drive/Domain/StoreTest.php`) | Les doubles et les exemples supposent Leclerc comme défaut. | Les builders prennent l'enseigne en paramètre (défaut inchangé) ; un exemple de contrat des enseignes (`retailers.sample.json`). **DRV-701**. |

### 3.3 Front web (`kcharignon/repas-front`, `src/`)

| Où | Couplage | À généraliser |
|---|---|---|
| `src/i18n/fr.ts` (44 lignes qui disent « Leclerc », ex. l. 446, 464-473, 513-516, 577, 601-634, 649-691, 722-731, 755, 824-891) | Le nom de l'enseigne **codé en dur** dans les erreurs, le récapitulatif, l'envoi, les réglages, l'aide, **les conditions d'utilisation** (l. 824-891 : compte E.Leclerc, Tickets E.Leclerc). | Le nom devient un paramètre (`{retailer}`), y compris sa forme élidée (« de Leclerc » / « d’Auchan »). Les conditions d'utilisation gardent une section par enseigne. **DRV-708**. |
| `src/features/drive/leclerc.ts` | `DRIVE_HOME_URL = 'https://www.leclercdrive.fr/'` et `finalizeLink()` (forme `/magasin-N-N`). | `retailers.ts` : accueil et lien « Finaliser » selon l'enseigne. **DRV-708**. |
| `src/features/drive/storeAddress.ts` | `parseStoreAddress` : `STORE_HOST` (`@drive-protocol`, motif `*.leclercdrive.fr`) et chemin `/magasin-N-N`. | La règle vient du registre de la couche. **DRV-708**. |
| `src/features/drive/queries.ts` l. 65 et 82, `choicesQueries.ts` l. 19 et 56, `choiceDraft.ts` l. 84 | `retailer: 'leclerc'` **écrit cinq fois** dans les appels d'API. | L'enseigne vient de la sélection, jamais d'un littéral. **DRV-708**. |
| `src/features/drive/DriveSettingsView.vue` | Un seul magasin, une seule enseigne ; « Lire mon magasin » lance `drive.setup` sans enseigne. | « Mes drives » : une ligne par enseigne activée. **DRV-709**. |
| `src/features/drive/bridge/draft.ts`, `useDriveReturn.ts` | Le brouillon de passage `{runId, listId, startedAt}` ne dit pas pour quelle enseigne ; le message `result` non plus (pas de champ `retailer`). | Le brouillon porte l'enseigne ; le retour enregistre les choix pour **elle** (et rejette un retour d'une autre). **DRV-710**. |
| `src/features/drive/bridge/native.ts` | `AppInfo = {version, versionCode, drive}` : l'application n'annonce pas ses enseignes. | Champ **facultatif** `retailers` (absent = `['leclerc']`). **DRV-707**, lu par **DRV-710**. |
| `src/features/drive/appVersion.ts` (`MIN_DRIVE_APP_VERSION`) | Une seule version minimale pour « le drive ». | Reste vrai pour le noyau ; la prise en charge d'une enseigne se lit sur l'annonce de l'application, pas sur un numéro (§ 8). |
| `src/features/drive/appInstall.ts` | Une constante (`APP_RELEASES_URL`), **sans lien avec une enseigne** : rien à faire. | — |
| `src/i18n/__tests__/voice-and-glossary.spec.ts` (22 mentions), 25 mentions dans `DriveReturnView.spec.ts`, etc. | Les tests attendent le mot « Leclerc ». | Ils passent à l'enseigne par paramètre ; un test échoue si « Leclerc » ou « Auchan » apparaît dans un texte hors du registre. **DRV-708**. |

### 3.4 Agent (`drive-agent/`)

| Où | Couplage | À généraliser |
|---|---|---|
| `drive-agent/src/leclerc/` (16 fichiers : `adapter.ts`, `cart.ts`, `search-page.ts`, `search-request.ts`, `store.ts`, `session.ts`, `entry.ts`, `pacer.ts`, `causes.ts`, `errors.ts`…) | **Bien rangé** : tout ce qui est propre au site est dans ce dossier, derrière l'interface `RetailerAdapter` (`adapter.ts`). C'est le bon patron. | Garder `leclerc/` tel quel ; poser le registre à côté. |
| Fuite des types dans `flow/`, `diagnostic/`, `selftest/` | `flow/*.ts` importent `LeclercError`, `LeclercCause`, `RequestStats`, `Candidate`, `Content`, `CartResult` depuis `'../leclerc'` (14 fichiers) ; l'interface s'appelle `RetailerAdapter` mais ses erreurs et causes portent le nom `Leclerc`. | Types neutres (`RetailerError`, `RetailerCause`) exportés par une couche commune ; `leclerc/` les implémente. **DRV-704**. |
| `drive-agent/src/agent.ts` | `new LeclercAdapter(browserDeps(win))` : l'adaptateur est choisi en dur. | Le registre choisit l'adaptateur d'après l'hôte de la page **et** l'enseigne du plan. **DRV-704**. |
| `drive-agent/protocol/check.ts` l. 17 (`HOST = /^[a-z0-9-]+\.leclercdrive\.fr$/`), `plan.ts` l. 111 (`exactly(message, 'retailer', 'leclerc')`), `plan.schema.json` (`"retailer": {"const": "leclerc"}`, motif d'hôte), `types.ts` l. 136 (`retailer: 'leclerc'`) | Le **protocole** fige l'enseigne et l'hôte. | `retailer` devient une énumération issue du registre, l'hôte se valide **par enseigne** (le plan d'une enseigne n'accepte que ses hôtes). Valeurs actuelles inchangées. **DRV-704**. |
| `drive-agent/src/ui/strings.ts` (26 lignes), `src/diagnostic/*` (`checks.ts` 14, `probes.ts` 7) | Textes de l'agent qui disent « Leclerc » ; sondes de diagnostic propres au site. | Nom en paramètre pour les textes (**DRV-705**) ; les sondes restent dans l'adaptateur. |
| `drive-agent/fake-leclerc/` (faux site), `fixtures/leclerc/`, `Makefile` (`agent-e2e`, `agent-fake`, `android-e2e`), `compose.yaml` (hôtes `*.leclercdrive.fr` redirigés vers le faux site), `drive-agent/e2e/*` | Tout le banc de test suppose un faux Leclerc. | Le faux site d'Auchan est un second dossier de même forme (**DRV-717**) ; ne pas le généraliser avant de connaître le vrai site. |

### 3.5 Application Android (`android/`)

| Où | Couplage | À généraliser |
|---|---|---|
| `core/…/NavigationPolicy.kt` | `START_URL = "https://www.leclercdrive.fr/"`, `HOME_HOST`, `ALLOWED_DOMAIN = "leclercdrive.fr"`, `STORE_PATH` (`/magasin-N-N…`), `LOGIN_PATH`, `MOBILE_LABEL` (site mobile `m-…`). **Le cœur de la sécurité de navigation** : seules les pages `https` de ce domaine restent dans l'application. | `RetailerSite` : une table (domaine, accueil, forme de page de magasin, connexion, site mobile) ; la décision de navigation prend l'enseigne en compte. **DRV-706 (xhigh)**. |
| `core/…/BridgeMessages.kt` | `ORIGIN_RULES = setOf("https://*.leclercdrive.fr")`, `DOMAIN_SUFFIX`, `isAllowedOrigin` : **les seules origines où l'objet `repasHost` existe et où l'agent est injecté**. | Les règles d'origine de **toutes** les enseignes activées, et la livraison d'un plan limitée aux pages **de son enseigne**. **DRV-706 (xhigh)**. |
| `core/…/DrivePlan.kt` l. 56, 122, 129-136 | `plan.opt("retailer") != "leclerc"` refuse tout autre plan (`INVALID_PLAN`) ; `STORE_HOST` ; `startUrl` = `NavigationPolicy.START_URL`. | L'enseigne du plan ∈ registre, l'hôte du magasin appartient à cette enseigne, l'adresse de départ est celle de l'enseigne. **DRV-706**. |
| `core/…/DriveEntry.kt`, `StorePathMemory.kt`, `FinalizeOutcome.kt`, `DebugPlan.kt` | « Finaliser » et la mémoire du chemin du magasin (avec ville) sont des notions de Leclerc ; la mémoire est **unique**. | Une mémoire par enseigne (clé = identifiant d'enseigne). **DRV-706**. |
| `app/…/DriveActivity.kt` (16 mentions), `AgentHost.kt` (injection dans `*.leclercdrive.fr`), `app/src/main/assets/injection.js`, `DesktopMode.kt` / `UserAgent.kt` (mode ordinateur permanent, parce que Leclerc sert un site mobile aux routes différentes) | L'écran du drive, l'injection et le mode ordinateur supposent un seul site. | L'écran s'ouvre **sur une enseigne** (extra d'intention) ; mode ordinateur selon l'enseigne ; injection selon l'hôte. **DRV-707**. |
| `app/…/DriveProfile.kt` | Un profil de WebView `drive` (cookies de Leclerc) ; « Se déconnecter de Leclerc » l'efface. | Décision D-M6 (§ 14) : un profil par enseigne, pour que se déconnecter d'une enseigne n'efface pas l'autre. **DRV-707**. |
| `app/src/main/res/values/strings.xml` (9 lignes : `menu_open_drive`, `menu_logout_drive`, `clear_message`, `plan_banner*`, `about_*`) | « Ouvrir le drive Leclerc », « Se déconnecter de Leclerc ». | Noms en paramètre `%1$s`, entrées de menu par enseigne configurée. **DRV-707**. |
| `app.info` (`NativeBridge.appInfo(version, versionCode, drive)`) | N'annonce pas les enseignes. | Ajoute `retailers` (liste d'identifiants). **DRV-707**. |
| `android/emulator/` (faux site en HTTPS), `__tests__/*.spec.ts`, `PROCEDURE.md`, `README.md` (56 mentions), `PUBLICATION.md` | Documentation et scénarios de bout en bout écrits pour Leclerc. | Mettre à jour la documentation (**DRV-711**) ; le scénario Auchan est celui de DRV-717. |

### 3.6 Documentation, runbook, ADR

`architecture.md` (§ 3, 5, 8, 9), `README.md`, `runbook.md` (« Leclerc a changé »), `hypotheses-site-reel.md`, `recette.md`, `note-vie-privee.md`, les skills `repas-agent-dev`, `repas-android-dev`, `repas-drive-back`, `repas-ops` (`references/drive.md`) et l'ADR front 0008 décrivent **un** drive. Rien de faux aujourd'hui ; ils ne doivent simplement pas être recopiés tels quels pour Auchan. **DRV-711** les met à jour (une section « par enseigne » dans chacun).

### 3.7 Synthèse

| Couche | Niveau de couplage | Effort de découplage |
|---|---|---|
| Base de données et clés | Aucun | Nul |
| Back (domaine, handlers) | Faible : une enum, 3 constantes de lecture, une validation numérique | Petit |
| API | Moyen : 3 lectures sans paramètre, énumérations `leclerc` dans OpenAPI | Petit (additif) |
| Front web | Moyen : textes, 5 littéraux, une vue de réglages à un magasin | Moyen |
| Agent | Faible : déjà derrière `RetailerAdapter`, mais ses types portent le nom Leclerc et le protocole fige l'enseigne | Petit à moyen |
| Application Android | **Fort** : sécurité de navigation, origines du pont, injection, profil, textes : tout est écrit pour un domaine | Moyen, **sensible** (xhigh) |
| Banc de test et documentation | Fort mais sans risque | Moyen |

## 4. Conception cible : le registre d'enseignes

### 4.1 Les champs

Chaque couche a **son** registre (les données utiles ne sont pas les mêmes, et les langages diffèrent) ; un **contrat commun** les rapproche (§ 4.2).

| Champ | Sens | Leclerc aujourd'hui | Utilisé par |
|---|---|---|---|
| `id` | Identifiant stable, minuscules, ≤ 20 caractères (colonne `retailer`) | `leclerc` | partout |
| `name` | Nom que les proches disent à voix haute | « Leclerc » | textes |
| `nameDe` | Forme avec « de » (élision) | « de Leclerc » (« d’Auchan » plus tard) | textes (« Se déconnecter de … ») |
| `storeAddressRule` | Règle d'adresse d'un magasin et extraction de l'identifiant | `https://<sous-domaine>.leclercdrive.fr/magasin-<id>-<id>` | back (`Store::create`), web (`parseStoreAddress`), agent, Kotlin |
| `allowedDomains` | Hôtes où l'application reste, où le pont existe et où l'agent est injecté | `leclercdrive.fr` et ses sous-domaines | Kotlin (navigation, origines), agent (plan) |
| `startUrl` | Page où le drive s'ouvre | `https://www.leclercdrive.fr/` | Kotlin, web (repli de « Finaliser ») |
| `siteMode` | `DESKTOP` (mode ordinateur imposé, site mobile refusé) · `MOBILE` · `AS_IS` | `DESKTOP`, hôte mobile `m-…` refusé | Kotlin, agent |
| `productIdRule` | Forme de l'identifiant de produit | `^[0-9]{1,12}$` | back, agent, web |
| `storeIdSource` | D'où vient l'identifiant du magasin : `URL` (déduit de l'adresse) ou `EXPLICIT` (lu par l'agent, adresse d'enseigne constante) | `URL` | back (`Store::create`), contrat (DRV-720, fait) |
| `storeIdPattern` | Forme de l'identifiant du magasin | `^[0-9]{1,20}# Plusieurs enseignes de drive : audit du couplage à Leclerc et conception cible

> **Lancé le 2026-10-30** : la pause du 2026-10-20 est levée, le propriétaire démarre Auchan (« on va commencer à gérer Auchan »). Les spikes sont faits ([`spikes/DRV-712-rapport.md`](spikes/DRV-712-rapport.md), [713](spikes/DRV-713-rapport.md), [714](spikes/DRV-714-rapport.md)) et la décision est prise : **adaptateur complet** ([`spikes/DRV-715-decision-auchan.md`](spikes/DRV-715-decision-auchan.md)). Faits mesurés : [`hypotheses-site-reel-auchan.md`](hypotheses-site-reel-auchan.md). Les § 11 à 14 ci-dessous gardent le raisonnement d'origine (2026-10-19) ; leur § 11.4 et la décision priment. Règle active : ne jamais dépendre de l'implémentation de Leclerc dans le nouveau code ; Auchan reste **éteint** tant que l'humain ne l'allume pas.

Cadrage du 2026-10-19 (jalon M7 de [`roadmap.md`](roadmap.md)), rédigé par l'assistant de cadrage ; **aucun code de produit n'est écrit ici**. Décision d'architecture : [ADR 0027](../adr/0027-plusieurs-enseignes-de-drive.md) (`Accepted` le 2026-10-30, amendée le même jour). Tickets : [`DRV-701` à `DRV-717`](tickets/). État lu : back `main` au commit `556e63f`, front `main` au commit `5b8779f`.

## 1. Le besoin

Demande de l'utilisateur, mot pour mot : « on fera le drive Auchan (dans le futur, quand le drive Leclerc sera fini) mais du coup pense déjà à découpler et à ne pas être trop proche du drive Leclerc : on devra activer des drives différents, Leclerc, Auchan, et on devra choisir quel drive utiliser selon ce qu'on a déjà configuré dans nos paramètres. »

Ce que cela veut dire, en quatre phrases :

1. **Plusieurs drives peuvent coexister.** Une personne peut avoir configuré Leclerc, Auchan, ou les deux.
2. **Repas choisit le drive à utiliser** d'après ce que la personne a déjà configuré dans « Moi », sans lui faire répéter ce qu'il sait ([`principes-produit.md`](principes-produit.md) règles 3, 5, 6).
3. **Le code actuel ne doit pas durcir** son lien avec Leclerc : tout ce qui nomme Leclerc doit passer par un point unique, pour que la deuxième enseigne soit une entrée de plus et non une réécriture.
4. **Tant qu'il n'y a qu'une enseigne, rien ne change pour l'utilisateur** : le découplage est un chantier d'ingénierie, pas de produit.

**Ce qui est livré par ce cadrage** : l'audit (§ 3), la conception cible (§ 4 à § 8), le plan de découplage en tickets (§ 10), les faits connus sur Auchan et leurs inconnues (§ 11), les spikes à faire sur un téléphone (§ 12). **Ce qui ne l'est pas** : l'adaptateur Auchan lui-même (il dépend des spikes, DRV-717 n'en pose que le squelette), et toute décision sur l'ordre de lancement par rapport à la fin de la recette de Leclerc (voir § 13).

## 2. Principes

1. **Une enseigne est une donnée, pas une branche de code.** Identifiant, nom, règle d'adresse du magasin, hôtes autorisés, particularités : un registre. Ajouter une enseigne = ajouter une entrée de registre + un adaptateur d'agent, jamais un `if ($retailer === …)` dans un handler (OCP, [`CLAUDE.md`](../../CLAUDE.md) § SOLID).
2. **Les invariants du chantier ne changent pas** ([`architecture.md`](architecture.md) § 2) : aucun identifiant, cookie ni jeton d'enseigne chez nous ; le back ne contacte jamais une enseigne ; pas de code distant ; l'agent ne valide ni ne paie jamais ; le panier d'une enseigne n'est jamais vidé ni réduit. Ils valent **pour chaque enseigne séparément**.
3. **Compatible avec ce qui est installé.** Le site web se met à jour tout seul, l'application Android non ([DRV-640](tickets/DRV-640-version-minimale-pour-le-drive.md)) : tout ajout au protocole v1 est **facultatif** et une application qui ne connaît pas une enseigne ne reçoit jamais de plan pour elle (§ 8).
4. **Un seul point par couche.** Si un nom d'enseigne apparaît ailleurs que dans le registre de sa couche, c'est un défaut (un test le garde : § 10, DRV-701, DRV-704, DRV-706, DRV-708).
5. **Ne rien présumer d'Auchan.** Les faits sur Auchan sont des hypothèses sourcées (§ 11) tant qu'un spike sur téléphone ne les a pas confirmés.

## 3. Audit du couplage actuel à Leclerc

Lu le 2026-10-19 dans les deux dépôts. « Nomme Leclerc » = le mot, un hôte, un motif d'adresse ou une forme propre au site de Leclerc. Les chemins sont ceux de `main`.

### 3.1 Bonne nouvelle : le modèle de données connaît déjà l'enseigne (vérifié)

| Table (back) | Clé | Verdict |
|---|---|---|
| `drive_settings` | PK `(user_id, retailer)` | Un réglage **par utilisateur et par enseigne** : plusieurs enseignes coexistent déjà en base. `src/Drive/Infrastructure/Entity/DriveSettings.php`, `DriveSettingsRepository::findOneByUser($userId, $retailer)`. |
| `drive_product` | UNIQUE `(retailer, store_id, retailer_product_id)` | Un produit d'une enseigne ne peut pas heurter celui d'une autre. Les prix et le contenu restent des faits publics **par enseigne et magasin**. |
| `drive_choice` | UNIQUE `(user_id, retailer, store_id, ingredient_slug)` | Les choix mémorisés (produits habituels, remplacements, produits refusés, « j'en ai toujours ») sont **par enseigne et par magasin** : rien n'est mélangé. La recherche mémorisée de DRV-650 (`search_query`, le texte qui a fait trouver le produit choisi) vit sur cette même ligne : elle est donc propre à l'enseigne, jamais reprise d'une enseigne à l'autre. `ChoicePostgreSQLRepository::findByUserStoreAndSlugs` filtre déjà sur `retailer`. |
| `drive_choice_product` | PK `(choice_id, product_id)` | Dépend du choix, donc de l'enseigne. La contenance mémorisée de DRV-652 (`pack_size_value`, `pack_size_unit_slug` : « 20 tranches » dans ce produit) vit sur cette ligne : privée à l'utilisateur, propre à l'enseigne par le choix qui la porte, et exprimée en **slug d'unité de Repas** (rien de propre à une enseigne). |

Les colonnes `retailer` sont des `VARCHAR(20)` (assez pour `auchan`, `intermarche`). **Aucune migration de schéma n'est nécessaire pour les clés.** Les routes d'écriture portent déjà `retailer` dans le corps (`PUT /drive/settings`, `PUT /drive/choices`, `PUT /drive/choices/{slug}`) ou dans la requête (`GET` et `DELETE /drive/choices`). La purge du compte supprime par utilisateur, sans condition d'enseigne (`ChoicePostgreSQLRepository::deleteByUser`, `DriveSettingsRepository::deleteByUser`). Le verrou `SENT` d'une liste est **indépendant de l'enseigne** (`ShoppingList` n'a pas de champ enseigne ; `send` ne prend aucun paramètre d'enseigne).

### 3.2 Back (`kcharignon/repas`)

| Où | Couplage | À généraliser |
|---|---|---|
| `src/Drive/Domain/Model/Retailer.php` | Enum à un seul cas `LECLERC`. `LECLERC_STORE_URL` (`#^https://[a-z0-9-]+\.leclercdrive\.fr/magasin-(\d+)-(\d+)$#D`) et un `match` à un seul bras dans `storeIdOf()`. | Le registre : nom affiché, règle d'adresse du magasin, motif d'identifiant produit, drapeaux. L'enum reste la liste des identifiants. **DRV-701**. |
| `src/Drive/Infrastructure/Loader/DriveSettingsPostgreSQLLoader.php` l. 16, `DrivePlanPostgreSQLLoader.php` l. 41, `DriveEstimatePostgreSQLLoader.php` l. 38 | `private const Retailer RETAILER = Retailer::LECLERC` (« une seule enseigne pour l'instant : la lecture ne porte pas encore de paramètre `retailer` »). **Les trois seules lectures qui figent Leclerc.** | L'enseigne devient un paramètre de la requête (`GetDrivePlanQuery`, `GetDriveEstimateQuery`, `GetDriveSettingsQuery`). **DRV-701**. |
| `GET /drive/settings` (`DriveSettingsDTO`) | Une seule enseigne et un seul magasin : `{enabled, retailer, store}`. | Ajouter, sans retirer, la liste des enseignes et la sélection (§ 5.4). **DRV-701**, **DRV-703**. |
| `GET /drive/plans/{id}`, `GET /drive/estimates/{id}` | Pas de paramètre d'enseigne. | `?retailer=` facultatif (enseigne courante par défaut). **DRV-701**. |
| `src/Drive/Domain/Model/Store.php` | `baseUrl` obligatoire, validée par la règle d'adresse de l'enseigne ; `id` ≤ 20 caractères. Vrai pour Leclerc (un magasin = une adresse). | Auchan : identifiant de magasin **explicite** lu par `GET /journey` (UUID, adresse d'enseigne constante) : décision DRV-715, **DRV-720**. |
| `src/Drive/Domain/Model/Product.php` | ~~`RETAILER_PRODUCT_ID_PATTERN = ^[0-9]{1,12}# Plusieurs enseignes de drive : audit du couplage à Leclerc et conception cible

> **Lancé le 2026-10-30** : la pause du 2026-10-20 est levée, le propriétaire démarre Auchan (« on va commencer à gérer Auchan »). Les spikes sont faits ([`spikes/DRV-712-rapport.md`](spikes/DRV-712-rapport.md), [713](spikes/DRV-713-rapport.md), [714](spikes/DRV-714-rapport.md)) et la décision est prise : **adaptateur complet** ([`spikes/DRV-715-decision-auchan.md`](spikes/DRV-715-decision-auchan.md)). Faits mesurés : [`hypotheses-site-reel-auchan.md`](hypotheses-site-reel-auchan.md). Les § 11 à 14 ci-dessous gardent le raisonnement d'origine (2026-10-19) ; leur § 11.4 et la décision priment. Règle active : ne jamais dépendre de l'implémentation de Leclerc dans le nouveau code ; Auchan reste **éteint** tant que l'humain ne l'allume pas.

Cadrage du 2026-10-19 (jalon M7 de [`roadmap.md`](roadmap.md)), rédigé par l'assistant de cadrage ; **aucun code de produit n'est écrit ici**. Décision d'architecture : [ADR 0027](../adr/0027-plusieurs-enseignes-de-drive.md) (`Accepted` le 2026-10-30, amendée le même jour). Tickets : [`DRV-701` à `DRV-717`](tickets/). État lu : back `main` au commit `556e63f`, front `main` au commit `5b8779f`.

## 1. Le besoin

Demande de l'utilisateur, mot pour mot : « on fera le drive Auchan (dans le futur, quand le drive Leclerc sera fini) mais du coup pense déjà à découpler et à ne pas être trop proche du drive Leclerc : on devra activer des drives différents, Leclerc, Auchan, et on devra choisir quel drive utiliser selon ce qu'on a déjà configuré dans nos paramètres. »

Ce que cela veut dire, en quatre phrases :

1. **Plusieurs drives peuvent coexister.** Une personne peut avoir configuré Leclerc, Auchan, ou les deux.
2. **Repas choisit le drive à utiliser** d'après ce que la personne a déjà configuré dans « Moi », sans lui faire répéter ce qu'il sait ([`principes-produit.md`](principes-produit.md) règles 3, 5, 6).
3. **Le code actuel ne doit pas durcir** son lien avec Leclerc : tout ce qui nomme Leclerc doit passer par un point unique, pour que la deuxième enseigne soit une entrée de plus et non une réécriture.
4. **Tant qu'il n'y a qu'une enseigne, rien ne change pour l'utilisateur** : le découplage est un chantier d'ingénierie, pas de produit.

**Ce qui est livré par ce cadrage** : l'audit (§ 3), la conception cible (§ 4 à § 8), le plan de découplage en tickets (§ 10), les faits connus sur Auchan et leurs inconnues (§ 11), les spikes à faire sur un téléphone (§ 12). **Ce qui ne l'est pas** : l'adaptateur Auchan lui-même (il dépend des spikes, DRV-717 n'en pose que le squelette), et toute décision sur l'ordre de lancement par rapport à la fin de la recette de Leclerc (voir § 13).

## 2. Principes

1. **Une enseigne est une donnée, pas une branche de code.** Identifiant, nom, règle d'adresse du magasin, hôtes autorisés, particularités : un registre. Ajouter une enseigne = ajouter une entrée de registre + un adaptateur d'agent, jamais un `if ($retailer === …)` dans un handler (OCP, [`CLAUDE.md`](../../CLAUDE.md) § SOLID).
2. **Les invariants du chantier ne changent pas** ([`architecture.md`](architecture.md) § 2) : aucun identifiant, cookie ni jeton d'enseigne chez nous ; le back ne contacte jamais une enseigne ; pas de code distant ; l'agent ne valide ni ne paie jamais ; le panier d'une enseigne n'est jamais vidé ni réduit. Ils valent **pour chaque enseigne séparément**.
3. **Compatible avec ce qui est installé.** Le site web se met à jour tout seul, l'application Android non ([DRV-640](tickets/DRV-640-version-minimale-pour-le-drive.md)) : tout ajout au protocole v1 est **facultatif** et une application qui ne connaît pas une enseigne ne reçoit jamais de plan pour elle (§ 8).
4. **Un seul point par couche.** Si un nom d'enseigne apparaît ailleurs que dans le registre de sa couche, c'est un défaut (un test le garde : § 10, DRV-701, DRV-704, DRV-706, DRV-708).
5. **Ne rien présumer d'Auchan.** Les faits sur Auchan sont des hypothèses sourcées (§ 11) tant qu'un spike sur téléphone ne les a pas confirmés.

## 3. Audit du couplage actuel à Leclerc

Lu le 2026-10-19 dans les deux dépôts. « Nomme Leclerc » = le mot, un hôte, un motif d'adresse ou une forme propre au site de Leclerc. Les chemins sont ceux de `main`.

### 3.1 Bonne nouvelle : le modèle de données connaît déjà l'enseigne (vérifié)

| Table (back) | Clé | Verdict |
|---|---|---|
| `drive_settings` | PK `(user_id, retailer)` | Un réglage **par utilisateur et par enseigne** : plusieurs enseignes coexistent déjà en base. `src/Drive/Infrastructure/Entity/DriveSettings.php`, `DriveSettingsRepository::findOneByUser($userId, $retailer)`. |
| `drive_product` | UNIQUE `(retailer, store_id, retailer_product_id)` | Un produit d'une enseigne ne peut pas heurter celui d'une autre. Les prix et le contenu restent des faits publics **par enseigne et magasin**. |
| `drive_choice` | UNIQUE `(user_id, retailer, store_id, ingredient_slug)` | Les choix mémorisés (produits habituels, remplacements, produits refusés, « j'en ai toujours ») sont **par enseigne et par magasin** : rien n'est mélangé. La recherche mémorisée de DRV-650 (`search_query`, le texte qui a fait trouver le produit choisi) vit sur cette même ligne : elle est donc propre à l'enseigne, jamais reprise d'une enseigne à l'autre. `ChoicePostgreSQLRepository::findByUserStoreAndSlugs` filtre déjà sur `retailer`. |
| `drive_choice_product` | PK `(choice_id, product_id)` | Dépend du choix, donc de l'enseigne. La contenance mémorisée de DRV-652 (`pack_size_value`, `pack_size_unit_slug` : « 20 tranches » dans ce produit) vit sur cette ligne : privée à l'utilisateur, propre à l'enseigne par le choix qui la porte, et exprimée en **slug d'unité de Repas** (rien de propre à une enseigne). |

Les colonnes `retailer` sont des `VARCHAR(20)` (assez pour `auchan`, `intermarche`). **Aucune migration de schéma n'est nécessaire pour les clés.** Les routes d'écriture portent déjà `retailer` dans le corps (`PUT /drive/settings`, `PUT /drive/choices`, `PUT /drive/choices/{slug}`) ou dans la requête (`GET` et `DELETE /drive/choices`). La purge du compte supprime par utilisateur, sans condition d'enseigne (`ChoicePostgreSQLRepository::deleteByUser`, `DriveSettingsRepository::deleteByUser`). Le verrou `SENT` d'une liste est **indépendant de l'enseigne** (`ShoppingList` n'a pas de champ enseigne ; `send` ne prend aucun paramètre d'enseigne).

### 3.2 Back (`kcharignon/repas`)

| Où | Couplage | À généraliser |
|---|---|---|
| `src/Drive/Domain/Model/Retailer.php` | Enum à un seul cas `LECLERC`. `LECLERC_STORE_URL` (`#^https://[a-z0-9-]+\.leclercdrive\.fr/magasin-(\d+)-(\d+)$#D`) et un `match` à un seul bras dans `storeIdOf()`. | Le registre : nom affiché, règle d'adresse du magasin, motif d'identifiant produit, drapeaux. L'enum reste la liste des identifiants. **DRV-701**. |
| `src/Drive/Infrastructure/Loader/DriveSettingsPostgreSQLLoader.php` l. 16, `DrivePlanPostgreSQLLoader.php` l. 41, `DriveEstimatePostgreSQLLoader.php` l. 38 | `private const Retailer RETAILER = Retailer::LECLERC` (« une seule enseigne pour l'instant : la lecture ne porte pas encore de paramètre `retailer` »). **Les trois seules lectures qui figent Leclerc.** | L'enseigne devient un paramètre de la requête (`GetDrivePlanQuery`, `GetDriveEstimateQuery`, `GetDriveSettingsQuery`). **DRV-701**. |
| `GET /drive/settings` (`DriveSettingsDTO`) | Une seule enseigne et un seul magasin : `{enabled, retailer, store}`. | Ajouter, sans retirer, la liste des enseignes et la sélection (§ 5.4). **DRV-701**, **DRV-703**. |
| `GET /drive/plans/{id}`, `GET /drive/estimates/{id}` | Pas de paramètre d'enseigne. | `?retailer=` facultatif (enseigne courante par défaut). **DRV-701**. |
| `src/Drive/Domain/Model/Store.php` | `baseUrl` obligatoire, validée par la règle d'adresse de l'enseigne ; `id` ≤ 20 caractères. Vrai pour Leclerc (un magasin = une adresse). | Auchan : identifiant de magasin **explicite** lu par `GET /journey` (UUID, adresse d'enseigne constante) : décision DRV-715, **DRV-720**. |
~~ : **fait par DRV-701** : la forme d'un identifiant de produit est `productIdPattern` du profil de l'enseigne (`Retailer::isValidProductId()`), aussi dans `ProductInput` / `ProductPayload` ; la validation de l'agent la lit dans son registre (DRV-704). `EAN_PATTERN` est neutre. | Fait (DRV-701, 704). |
| `src/Drive/Domain/Model/DriveSettings.php` | Le compteur du jour des écritures de produits (`productWritesDay/Count`, plafond 5 000 : DRV-613) vit **dans la ligne de réglage**, donc **par utilisateur et par enseigne**. Avec deux enseignes, le plafond quotidien devient 5 000 **par enseigne**. | Décision D-M5 (§ 14) : l'accepter (borne = 5 000 × nombre d'enseignes activées, ce qui reste très petit) et l'écrire dans le test et le skill `repas-drive-back`. **DRV-701**. |
| OpenAPI : `enum: ['leclerc']` dans `DriveSettingsDTO`, `DrivePlanDTO`, `DriveEstimateDTO`, `DriveSettingsPayload`, `ReplaceIngredientPreferencePayload`, `SaveProductChoicesPayload`, et les deux `OA\Parameter` de `ListChoicesApiController` et `ForgetChoiceApiController` ; exemple d'adresse `…leclercdrive.fr…` dans `DriveStorePayload` et `DriveStoreDTO` ; 14 mentions dans `docs/api/openapi.json`. | Le contrat fige `leclerc`. | L'énumération se déduit du registre (un test garde la concordance), l'exemple devient neutre. **DRV-701**. |
| `DriveFeature` / `DRIVE_ENABLED` (`src/Shared/…`, `EnvDriveFeature`, `DriveEnabledListener`, `#[RequiresDrive]`) | Un interrupteur **global** : coupe toutes les enseignes à la fois. Bien pour une urgence générale, trop gros pour couper **une** enseigne qui a changé son site. | Interrupteur par enseigne, en plus (§ 7). **DRV-702**. |
| `tests/…` (176 occurrences de `Retailer::LECLERC`, `tests/Helper/Builder/*`, `docs/drive/samples/*.sample.json`, `tests/Drive/Domain/StoreTest.php`) | Les doubles et les exemples supposent Leclerc comme défaut. | Les builders prennent l'enseigne en paramètre (défaut inchangé) ; un exemple de contrat des enseignes (`retailers.sample.json`). **DRV-701**. |

### 3.3 Front web (`kcharignon/repas-front`, `src/`)

| Où | Couplage | À généraliser |
|---|---|---|
| `src/i18n/fr.ts` (44 lignes qui disent « Leclerc », ex. l. 446, 464-473, 513-516, 577, 601-634, 649-691, 722-731, 755, 824-891) | Le nom de l'enseigne **codé en dur** dans les erreurs, le récapitulatif, l'envoi, les réglages, l'aide, **les conditions d'utilisation** (l. 824-891 : compte E.Leclerc, Tickets E.Leclerc). | Le nom devient un paramètre (`{retailer}`), y compris sa forme élidée (« de Leclerc » / « d’Auchan »). Les conditions d'utilisation gardent une section par enseigne. **DRV-708**. |
| `src/features/drive/leclerc.ts` | `DRIVE_HOME_URL = 'https://www.leclercdrive.fr/'` et `finalizeLink()` (forme `/magasin-N-N`). | `retailers.ts` : accueil et lien « Finaliser » selon l'enseigne. **DRV-708**. |
| `src/features/drive/storeAddress.ts` | `parseStoreAddress` : `STORE_HOST` (`@drive-protocol`, motif `*.leclercdrive.fr`) et chemin `/magasin-N-N`. | La règle vient du registre de la couche. **DRV-708**. |
| `src/features/drive/queries.ts` l. 65 et 82, `choicesQueries.ts` l. 19 et 56, `choiceDraft.ts` l. 84 | `retailer: 'leclerc'` **écrit cinq fois** dans les appels d'API. | L'enseigne vient de la sélection, jamais d'un littéral. **DRV-708**. |
| `src/features/drive/DriveSettingsView.vue` | Un seul magasin, une seule enseigne ; « Lire mon magasin » lance `drive.setup` sans enseigne. | « Mes drives » : une ligne par enseigne activée. **DRV-709**. |
| `src/features/drive/bridge/draft.ts`, `useDriveReturn.ts` | Le brouillon de passage `{runId, listId, startedAt}` ne dit pas pour quelle enseigne ; le message `result` non plus (pas de champ `retailer`). | Le brouillon porte l'enseigne ; le retour enregistre les choix pour **elle** (et rejette un retour d'une autre). **DRV-710**. |
| `src/features/drive/bridge/native.ts` | `AppInfo = {version, versionCode, drive}` : l'application n'annonce pas ses enseignes. | Champ **facultatif** `retailers` (absent = `['leclerc']`). **DRV-707**, lu par **DRV-710**. |
| `src/features/drive/appVersion.ts` (`MIN_DRIVE_APP_VERSION`) | Une seule version minimale pour « le drive ». | Reste vrai pour le noyau ; la prise en charge d'une enseigne se lit sur l'annonce de l'application, pas sur un numéro (§ 8). |
| `src/features/drive/appInstall.ts` | Une constante (`APP_RELEASES_URL`), **sans lien avec une enseigne** : rien à faire. | — |
| `src/i18n/__tests__/voice-and-glossary.spec.ts` (22 mentions), 25 mentions dans `DriveReturnView.spec.ts`, etc. | Les tests attendent le mot « Leclerc ». | Ils passent à l'enseigne par paramètre ; un test échoue si « Leclerc » ou « Auchan » apparaît dans un texte hors du registre. **DRV-708**. |

### 3.4 Agent (`drive-agent/`)

| Où | Couplage | À généraliser |
|---|---|---|
| `drive-agent/src/leclerc/` (16 fichiers : `adapter.ts`, `cart.ts`, `search-page.ts`, `search-request.ts`, `store.ts`, `session.ts`, `entry.ts`, `pacer.ts`, `causes.ts`, `errors.ts`…) | **Bien rangé** : tout ce qui est propre au site est dans ce dossier, derrière l'interface `RetailerAdapter` (`adapter.ts`). C'est le bon patron. | Garder `leclerc/` tel quel ; poser le registre à côté. |
| Fuite des types dans `flow/`, `diagnostic/`, `selftest/` | `flow/*.ts` importent `LeclercError`, `LeclercCause`, `RequestStats`, `Candidate`, `Content`, `CartResult` depuis `'../leclerc'` (14 fichiers) ; l'interface s'appelle `RetailerAdapter` mais ses erreurs et causes portent le nom `Leclerc`. | Types neutres (`RetailerError`, `RetailerCause`) exportés par une couche commune ; `leclerc/` les implémente. **DRV-704**. |
| `drive-agent/src/agent.ts` | `new LeclercAdapter(browserDeps(win))` : l'adaptateur est choisi en dur. | Le registre choisit l'adaptateur d'après l'hôte de la page **et** l'enseigne du plan. **DRV-704**. |
| `drive-agent/protocol/check.ts` l. 17 (`HOST = /^[a-z0-9-]+\.leclercdrive\.fr$/`), `plan.ts` l. 111 (`exactly(message, 'retailer', 'leclerc')`), `plan.schema.json` (`"retailer": {"const": "leclerc"}`, motif d'hôte), `types.ts` l. 136 (`retailer: 'leclerc'`) | Le **protocole** fige l'enseigne et l'hôte. | `retailer` devient une énumération issue du registre, l'hôte se valide **par enseigne** (le plan d'une enseigne n'accepte que ses hôtes). Valeurs actuelles inchangées. **DRV-704**. |
| `drive-agent/src/ui/strings.ts` (26 lignes), `src/diagnostic/*` (`checks.ts` 14, `probes.ts` 7) | Textes de l'agent qui disent « Leclerc » ; sondes de diagnostic propres au site. | Nom en paramètre pour les textes (**DRV-705**) ; les sondes restent dans l'adaptateur. |
| `drive-agent/fake-leclerc/` (faux site), `fixtures/leclerc/`, `Makefile` (`agent-e2e`, `agent-fake`, `android-e2e`), `compose.yaml` (hôtes `*.leclercdrive.fr` redirigés vers le faux site), `drive-agent/e2e/*` | Tout le banc de test suppose un faux Leclerc. | Le faux site d'Auchan est un second dossier de même forme (**DRV-717**) ; ne pas le généraliser avant de connaître le vrai site. |

### 3.5 Application Android (`android/`)

| Où | Couplage | À généraliser |
|---|---|---|
| `core/…/NavigationPolicy.kt` | `START_URL = "https://www.leclercdrive.fr/"`, `HOME_HOST`, `ALLOWED_DOMAIN = "leclercdrive.fr"`, `STORE_PATH` (`/magasin-N-N…`), `LOGIN_PATH`, `MOBILE_LABEL` (site mobile `m-…`). **Le cœur de la sécurité de navigation** : seules les pages `https` de ce domaine restent dans l'application. | `RetailerSite` : une table (domaine, accueil, forme de page de magasin, connexion, site mobile) ; la décision de navigation prend l'enseigne en compte. **DRV-706 (xhigh)**. |
| `core/…/BridgeMessages.kt` | `ORIGIN_RULES = setOf("https://*.leclercdrive.fr")`, `DOMAIN_SUFFIX`, `isAllowedOrigin` : **les seules origines où l'objet `repasHost` existe et où l'agent est injecté**. | Les règles d'origine de **toutes** les enseignes activées, et la livraison d'un plan limitée aux pages **de son enseigne**. **DRV-706 (xhigh)**. |
| `core/…/DrivePlan.kt` l. 56, 122, 129-136 | `plan.opt("retailer") != "leclerc"` refuse tout autre plan (`INVALID_PLAN`) ; `STORE_HOST` ; `startUrl` = `NavigationPolicy.START_URL`. | L'enseigne du plan ∈ registre, l'hôte du magasin appartient à cette enseigne, l'adresse de départ est celle de l'enseigne. **DRV-706**. |
| `core/…/DriveEntry.kt`, `StorePathMemory.kt`, `FinalizeOutcome.kt`, `DebugPlan.kt` | « Finaliser » et la mémoire du chemin du magasin (avec ville) sont des notions de Leclerc ; la mémoire est **unique**. | Une mémoire par enseigne (clé = identifiant d'enseigne). **DRV-706**. |
| `app/…/DriveActivity.kt` (16 mentions), `AgentHost.kt` (injection dans `*.leclercdrive.fr`), `app/src/main/assets/injection.js`, `DesktopMode.kt` / `UserAgent.kt` (mode ordinateur permanent, parce que Leclerc sert un site mobile aux routes différentes) | L'écran du drive, l'injection et le mode ordinateur supposent un seul site. | L'écran s'ouvre **sur une enseigne** (extra d'intention) ; mode ordinateur selon l'enseigne ; injection selon l'hôte. **DRV-707**. |
| `app/…/DriveProfile.kt` | Un profil de WebView `drive` (cookies de Leclerc) ; « Se déconnecter de Leclerc » l'efface. | Décision D-M6 (§ 14) : un profil par enseigne, pour que se déconnecter d'une enseigne n'efface pas l'autre. **DRV-707**. |
| `app/src/main/res/values/strings.xml` (9 lignes : `menu_open_drive`, `menu_logout_drive`, `clear_message`, `plan_banner*`, `about_*`) | « Ouvrir le drive Leclerc », « Se déconnecter de Leclerc ». | Noms en paramètre `%1$s`, entrées de menu par enseigne configurée. **DRV-707**. |
| `app.info` (`NativeBridge.appInfo(version, versionCode, drive)`) | N'annonce pas les enseignes. | Ajoute `retailers` (liste d'identifiants). **DRV-707**. |
| `android/emulator/` (faux site en HTTPS), `__tests__/*.spec.ts`, `PROCEDURE.md`, `README.md` (56 mentions), `PUBLICATION.md` | Documentation et scénarios de bout en bout écrits pour Leclerc. | Mettre à jour la documentation (**DRV-711**) ; le scénario Auchan est celui de DRV-717. |

### 3.6 Documentation, runbook, ADR

`architecture.md` (§ 3, 5, 8, 9), `README.md`, `runbook.md` (« Leclerc a changé »), `hypotheses-site-reel.md`, `recette.md`, `note-vie-privee.md`, les skills `repas-agent-dev`, `repas-android-dev`, `repas-drive-back`, `repas-ops` (`references/drive.md`) et l'ADR front 0008 décrivent **un** drive. Rien de faux aujourd'hui ; ils ne doivent simplement pas être recopiés tels quels pour Auchan. **DRV-711** les met à jour (une section « par enseigne » dans chacun).

### 3.7 Synthèse

| Couche | Niveau de couplage | Effort de découplage |
|---|---|---|
| Base de données et clés | Aucun | Nul |
| Back (domaine, handlers) | Faible : une enum, 3 constantes de lecture, une validation numérique | Petit |
| API | Moyen : 3 lectures sans paramètre, énumérations `leclerc` dans OpenAPI | Petit (additif) |
| Front web | Moyen : textes, 5 littéraux, une vue de réglages à un magasin | Moyen |
| Agent | Faible : déjà derrière `RetailerAdapter`, mais ses types portent le nom Leclerc et le protocole fige l'enseigne | Petit à moyen |
| Application Android | **Fort** : sécurité de navigation, origines du pont, injection, profil, textes : tout est écrit pour un domaine | Moyen, **sensible** (xhigh) |
| Banc de test et documentation | Fort mais sans risque | Moyen |

## 4. Conception cible : le registre d'enseignes

### 4.1 Les champs

Chaque couche a **son** registre (les données utiles ne sont pas les mêmes, et les langages diffèrent) ; un **contrat commun** les rapproche (§ 4.2).

| Champ | Sens | Leclerc aujourd'hui | Utilisé par |
|---|---|---|---|
| `id` | Identifiant stable, minuscules, ≤ 20 caractères (colonne `retailer`) | `leclerc` | partout |
| `name` | Nom que les proches disent à voix haute | « Leclerc » | textes |
| `nameDe` | Forme avec « de » (élision) | « de Leclerc » (« d’Auchan » plus tard) | textes (« Se déconnecter de … ») |
| `storeAddressRule` | Règle d'adresse d'un magasin et extraction de l'identifiant | `https://<sous-domaine>.leclercdrive.fr/magasin-<id>-<id>` | back (`Store::create`), web (`parseStoreAddress`), agent, Kotlin |
| `allowedDomains` | Hôtes où l'application reste, où le pont existe et où l'agent est injecté | `leclercdrive.fr` et ses sous-domaines | Kotlin (navigation, origines), agent (plan) |
| `startUrl` | Page où le drive s'ouvre | `https://www.leclercdrive.fr/` | Kotlin, web (repli de « Finaliser ») |
| `siteMode` | `DESKTOP` (mode ordinateur imposé, site mobile refusé) · `MOBILE` · `AS_IS` | `DESKTOP`, hôte mobile `m-…` refusé | Kotlin, agent |
 | back, agent, web (DRV-720, fait ; l'identifiant fait au plus 40 caractères) |
| `offerIdPattern` | Forme de l'identifiant d'offre d'un produit, ou `null` si l'enseigne n'en a pas | `null` | back, agent, web (DRV-721) |
| `antiBot` | Information pour l'exploitation : `DATADOME`, `OTHER`, `UNKNOWN` | `DATADOME` | agent (marqueur de défi), runbook |
| `pacing` | Cadence de départ (pause, gigue, plafond) | 800 ms + gigue 700 ms, plafond 3 s | agent |
| `termsSection` | Clé de la section des conditions d'utilisation | `leclerc` | web |
| `introducedAt` | Date de mise en service | 2026-10 | documentation |

**Le registre ne contient aucun secret ni aucun numéro de magasin.** Les drapeaux « activée ou non » ne sont **pas** dans le registre (c'est de la configuration : § 7).

### 4.2 Un contrat commun entre couches

La concordance se prouve comme pour le plan ([`consignes-agents.md`](consignes-agents.md), « Modifier le plan ») : le back génère `docs/drive/samples/retailers.sample.json` (`make drive-samples`), le front le recopie dans `drive-agent/fixtures/contract/` (`make samples-update`), et chaque couche (web, agent, Kotlin via ses ressources de test) vérifie que **ses** identifiants, noms, règles d'adresse et domaines sont ceux du contrat. Un nouvel `id` dans une couche seulement fait échouer la CI de l'autre. Le contrat est **public** et ne contient que des motifs d'adresses. Champs livrés (DRV-701, 720) : `id`, `name`, `nameDe`, `storeAddressPattern`, `storeAddressExample`, `productIdPattern`, `storeIdSource`, `storeIdPattern` ; `offerIdPattern` suit avec DRV-721. Les motifs sont écrits sans délimiteur, ancrés, dans le sous-ensemble commun à PCRE et à JavaScript (`[0-9]`, jamais `\d`). **Les tests de chaque couche comparent les champs qu'ils connaissent et tolèrent les autres** (un champ additif ne casse pas la CI d'une autre couche).

### 4.3 Un adaptateur d'agent par enseigne

L'interface `RetailerAdapter` (`drive-agent/src/leclerc/adapter.ts`) existe déjà : `readStore`, `searchProducts`, `setCartQuantity`, `readProductFromSearch`, `cartQuantity`, `stats`, `pageCartTotal`, `readSession`, `readArrival`, `lastSearchFacts`, `exploreSearchVariants`, `readEntry`, `followEntry`. Elle est **assez large pour une deuxième enseigne**, avec trois changements :

1. **Types neutres** : `RetailerError` / `RetailerCause` à la place de `LeclercError` / `LeclercCause` dans `flow/`, `diagnostic/`, `selftest/` ; chaque adaptateur fournit ses causes (le texte du runbook reste par enseigne).
2. **Un module par enseigne** : `drive-agent/src/retailers/<id>/` (aujourd'hui `leclerc/`) exporte `{ id, matchesHost(host), createAdapter(deps), strings }`. Le registre `retailers/index.ts` est la **seule** liste.
3. **Sélection** : à la réception d'un plan, `agent.ts` vérifie que `plan.retailer` correspond à l'**hôte de la page** puis crée l'adaptateur de cette enseigne. Hôte d'une enseigne, plan d'une autre : `STORE_MISMATCH` (code existant), sans aucune requête.

Ce qui reste **spécifique** et ne se factorise pas : recherche, lecture des produits, ajout au panier, détection d'un visiteur non connecté, d'une page de défi, d'un « introuvable », d'un site mobile, cadence. **Ce qui est commun** et déjà commun : confirmation avant d'agir, phases A et B, quantité plancher, résultat, état de reprise, bandeau de défi, diagnostic.

### 4.4 Les réglages : un par enseigne, et la liste des enseignes configurées

Le réglage reste **un magasin par utilisateur et par enseigne** (aucun changement de table). Ce qui s'ajoute :

- **La liste des enseignes** (DRV-701 a livré `retailers: [{retailer, name, configured, store}]` ; `isDefault` et la sélection viennent avec DRV-703, l'interrupteur avec DRV-702) vue de la personne : pour chaque enseigne activée, `configured` (un magasin est enregistré), son magasin, et s'il s'agit de son drive habituel.
- **Une préférence de l'utilisateur** (nouvel agrégat `RetailerPreference`, table `drive_retailer_preference`, **une ligne par utilisateur**, DRV-703) : `defaultRetailer` (facultatif, choisi dans « Moi ») et `lastUsedRetailer` (posé quand la personne ouvre le drive). Elle porte un `user_id` : elle entre dans la purge du compte (`UserOwnedDataCoverageTest`, [`CLAUDE.md`](../../CLAUDE.md) § Comptes).
- **La sélection calculée par le back** (service de domaine pur `RetailerSelection`, test en table) : le front n'implémente pas la règle, il la lit. Ainsi le web et, plus tard, tout autre client (iPhone) appliquent **la même**.

Forme additive de `GET /api/v1/drive/settings` (les anciens champs `enabled`, `retailer`, `store` restent, avec leur sens actuel quand il n'y a qu'une enseigne) :

```json
{
  "enabled": true,
  "retailer": "leclerc",
  "store": {"id": "123456", "label": null, "baseUrl": "https://exemple.leclercdrive.fr/magasin-123456-123456"},
  "retailers": [
    {"retailer": "leclerc", "name": "Leclerc", "configured": true, "isDefault": false,
     "store": {"id": "123456", "label": null, "baseUrl": "https://exemple.leclercdrive.fr/magasin-123456-123456"}}
  ],
  "defaultRetailer": null,
  "lastUsedRetailer": null,
  "selection": {"mode": "AUTO", "retailer": "leclerc", "candidates": ["leclerc"]}
}
```

`selection.mode` vaut `NONE` (aucune enseigne configurée : mener aux réglages), `AUTO` (une enseigne s'impose : l'utiliser sans rien demander) ou `ASK` (proposer le choix, `retailer` est la présélection). Les valeurs ci-dessus sont inventées.

## 5. La règle de choix de l'enseigne

### 5.1 La règle proposée

Définitions : **activée** = autorisée par l'interrupteur (§ 7) et connue de l'application installée (§ 8) ; **configurée** = activée **et** la personne y a enregistré un magasin.

| # | Situation | Ce que fait Repas | Touches en plus d'aujourd'hui |
|---|---|---|---|
| R1 | **Aucune** enseigne configurée | « Choisir mon magasin » (le bouton qui fait le travail : règle 6 des principes). S'il n'y a qu'**une** enseigne activée, il lance directement la lecture du magasin, comme aujourd'hui. S'il y en a **plusieurs**, une première question, « Quel drive ? », une tuile par enseigne, puis la lecture du magasin de celle qui est touchée. | 0 (une enseigne) · 1 (plusieurs) |
| R2 | **Une seule** configurée | On l'utilise, **sans rien demander**, même si d'autres enseignes existent. Ajouter une enseigne est un geste volontaire dans « Moi » (§ 6), jamais une demande à l'envoi. | 0 |
| R3 | **Plusieurs** configurées, un **drive habituel** choisi dans « Moi » | On utilise le drive habituel, sans rien demander. L'écran d'envoi garde un lien discret « Envoyer ailleurs » : il change l'enseigne **pour cet envoi seulement** (le drive habituel ne change pas) et devient « dernière utilisée ». | 0 (1 pour « ailleurs ») |
| R4 | **Plusieurs** configurées, **pas** de drive habituel | L'écran d'envoi montre une tuile par enseigne configurée, la **dernière utilisée présélectionnée** (à défaut, la première configurée), et un seul bouton principal : « Ouvrir <enseigne> ». | 0 si la présélection convient, 1 sinon |
| R5 | Une enseigne **désactivée** par l'interrupteur, ou trop récente pour l'application, est **retirée des candidates**. Le drive habituel désactivé est ignoré (retour à R4). S'il ne reste **aucune** candidate : l'envoi n'est pas proposé, avec le même message que l'interrupteur général coupé. Si c'est l'**application** qui ne connaît pas l'enseigne choisie : la phrase de mise à jour de [DRV-640](tickets/DRV-640-version-minimale-pour-le-drive.md), et l'autre enseigne reste proposée. | — | — |

### 5.2 Pourquoi cette règle

1. **Elle respecte les principes du chantier.** Règle 3 (ne jamais demander ce qu'on sait déjà) : R2 et R3 ne posent aucune question. Règle 5 (un défaut sûr et utile) : en R4 la dernière enseigne utilisée est déjà choisie, le geste le plus courant ne coûte rien. Règle 6 (rien à régler avant de servir) : R1 reste un seul bouton avec une seule enseigne.
2. **Elle donne un sens au « drive habituel »**, demandé dans les paramètres par l'utilisateur : ce n'est pas une présélection de plus, c'est « ne me demande plus ». Sans lui, la personne qui a deux enseignes mais n'en veut qu'une pour presque tous ses envois verrait toujours une question.
3. **Le plus explicite gagne.** Quand un drive habituel existe, il l'emporte sur la « dernière utilisée » : un envoi exceptionnel chez l'autre enseigne ne bascule pas les envois suivants.
4. **Le cas général reste inchangé.** Avec une enseigne (le cas de tous les utilisateurs aujourd'hui), R1 et R2 reproduisent exactement le comportement actuel : c'est le critère de réussite du découplage.
5. **Le calcul est dans le back**, testable en table (`RetailerSelection`), et le front ne fait que l'afficher : une seule règle pour le web, Android et un futur iPhone.

**Alternative écartée** : toujours demander quand plusieurs enseignes sont configurées. Une touche de plus à chaque envoi pour une réponse presque toujours la même ; contraire à la règle 3.
**Alternative écartée** : choisir l'enseigne selon l'estimation du coût la moins chère. Séduisant (voir le backlog) mais les prix mémorisés ne sont pas comparables (dates différentes, produits différents) et l'utilisateur n'a rien demandé de tel ; la règle reste prévisible.
**Alternative écartée** : lier la liste à une enseigne au moment d'envoyer (`SENT` porterait l'enseigne). Voir § 9.

### 5.3 Textes d'interface (exemples, tutoiement ; le nom est toujours un paramètre)

| Où | Texte |
|---|---|
| Question R1 (plusieurs enseignes) | « Quel drive utilises-tu ? » · tuiles « Leclerc », « Auchan » |
| Bouton R1 | « Choisir mon magasin {retailer} » |
| Écran d'envoi R4 | « Où envoyer ta liste ? » · tuiles · bouton « Ouvrir {retailer} » |
| Lien R3 | « Envoyer ailleurs » |
| « Moi », liste | « Mes drives » · « {retailer} » + « Ton magasin : …{3 derniers chiffres} » · « Ajouter {retailer} » |
| « Moi », habituel | « Mon drive habituel » (choix parmi les enseignes configurées ; masqué s'il n'y en a qu'une) |
| Application trop ancienne pour une enseigne | « Mets à jour l'application pour envoyer ta liste à {retailer}. » |
| Enseigne désactivée | (le message déjà utilisé quand le drive est coupé) |

À valider par le test de glossaire ([`principes-produit.md`](principes-produit.md) § 5) ; **« enseigne » et « retailer » n'apparaissent jamais à l'écran** : on dit « drive » (mot d'usage accepté) et on nomme l'enseigne. Mot à ajouter au glossaire par DRV-709 : « **Mon drive habituel** » (jamais « par défaut », « préféré »).

## 6. Ce qui reste propre à chaque enseigne

| Donnée | Portée | Pourquoi | Vérifié |
|---|---|---|---|
| Magasin | par utilisateur et par enseigne | un magasin n'a de sens que chez son enseigne | PK `drive_settings (user_id, retailer)` |
| Produits (libellé, contenu, prix, EAN) | par enseigne **et** magasin, faits publics sans propriétaire | un identifiant de produit n'a de sens que chez son enseigne ; les prix varient par magasin | UNIQUE `drive_product (retailer, store_id, retailer_product_id)` |
| Choix mémorisés (habituel, remplacement, refusés) | par utilisateur, enseigne et magasin | un produit de Leclerc ne se met pas au panier d'Auchan | UNIQUE `drive_choice (user_id, retailer, store_id, ingredient_slug)` |
| « J'en ai toujours » | **par enseigne et magasin aujourd'hui** (c'est un `kind` de `Choice`) | voir ci-dessous | idem |
| Rayons | **par enseigne** depuis DRV-642 (lot 1a) : le rayon et la famille d'un produit (`drive_product.rayon_id`, `family_id`, faits du produit : par enseigne **et** magasin comme lui) et les rayons appris par rayon de Repas (`drive_rayon_vote`, un vote par compte, clé `(user_id, retailer, department_slug, rayon_id)`, classés par comptes distincts, **par enseigne seulement**, sans magasin) | un identifiant de rayon n'a de sens que chez son enseigne (un rayon d'Auchan n'est jamais proposé à Leclerc) ; aucun identifiant n'est écrit en dur, l'enseigne reste le paramètre `retailer` | `DrivePlanPostgreSQLLoader::rayonHints()`, `RayonVoteRepository` |
| Estimation du coût | par enseigne (`?retailer=`) | prix mémorisés d'une enseigne et d'un magasin | `DriveEstimatePostgreSQLLoader` |
| Plafonds de volume (5 000 écritures par jour, 1 000 préférences par magasin) | écritures : **par enseigne** (§ 3.2), préférences : par magasin | voir D-M5 | `DriveSettings`, `Choice::MAX_PER_USER_AND_STORE` |
| Limiteur de débit (`drive_write` 60/min, `drive_read` 120/min) | **par utilisateur**, toutes enseignes confondues | c'est la charge sur Repas | `DriveRateLimit` |
| Conditions d'utilisation | une section **par enseigne** | ce que dit chaque enseigne de l'usage de son compte est propre à elle | `fr.ts` l. 824-891 |

**« J'en ai toujours » (sel, poivre) est un fait sur la maison, pas sur la boutique.** Avec deux enseignes, il faudrait le redire pour chacune. Décision D-M4 : **ne pas le changer dans ce chantier** (un changement de modèle et de purge pour un confort), le noter au backlog (DRV-718, « j'en ai toujours » commun à toutes les enseignes) et le mesurer quand une personne aura deux enseignes. Reprise des choix au changement de magasin : déjà au backlog (DRV-606, EAN/libellé) ; même idée entre enseignes, plus tard.

## 7. Interrupteurs

| Niveau | Variable | Effet | Qui |
|---|---|---|---|
| Global | `DRIVE_ENABLED` (existant, inchangé) | Coupe **tout** le drive : `404 DRIVE_DISABLED` ; `GET /drive/settings` répond `enabled: false` ; `reopen` reste permis | l'humain |
| **Par enseigne** (DRV-702) | `DRIVE_RETAILERS`, liste d'identifiants séparés par des virgules, **par défaut `leclerc`** | Une enseigne absente de la liste n'existe pas pour l'utilisateur : absente de `retailers` et de la sélection ; ses routes répondent `404 DRIVE_RETAILER_DISABLED` ; ses données **restent** en base (rallumer la retrouve) | l'humain |

Articulation : `DRIVE_ENABLED=false` l'emporte sur tout. `DRIVE_ENABLED=true` avec `DRIVE_RETAILERS=leclerc` est **exactement** la production d'aujourd'hui ; ajouter `auchan` à la liste est le geste de mise en service, fait par l'humain, jamais par un agent ([`consignes-agents.md`](consignes-agents.md)). Le port `DriveFeature` gagne `isRetailerEnabled(string $retailer): bool`. Retour arrière d'une enseigne qui a changé son site : retirer son identifiant de `DRIVE_RETAILERS` (runbook par enseigne, DRV-711), sans toucher à l'autre. Les listes `SENT` restent `SENT` : `reopen` ne dépend d'aucune enseigne.

Une enseigne **éteinte par défaut** a une conséquence utile : on peut fusionner et déployer son code (DRV-716, DRV-717) bien avant de l'allumer.

## 8. Compatibilité avec ce qui est déjà installé

1. **Protocole v1 : ajouts facultatifs seulement.** `retailer` existe déjà dans `plan` et `setup` ; il passe de `const "leclerc"` à une énumération qui contient d'abord `leclerc` seul. Aucun champ obligatoire n'est ajouté. L'hôte d'un `store` se valide par l'enseigne du message.
2. **Une application qui ne connaît pas une enseigne ne la reçoit pas.** L'application annonce ses enseignes dans `app.info` par un champ **facultatif** `retailers` (liste d'identifiants). **Champ absent = `['leclerc']`.** Le web (`DriveSendView`) n'envoie un plan pour une enseigne que si l'annonce la contient ; sinon il affiche la phrase de mise à jour (même mécanisme que DRV-640). L'application refuse de toute façon un plan d'une enseigne inconnue (`INVALID_PLAN` : elle le fait déjà pour tout ce qui n'est pas `leclerc`, `DrivePlan.kt` l. 136).
3. **Une capacité, pas un numéro.** `MIN_DRIVE_APP_VERSION` (DRV-640) reste le seuil du noyau du drive ; la prise en charge d'une enseigne se lit sur `retailers`, ce qui évite de relever le minimum pour tout le monde quand Auchan arrive.
4. **Le web lit `app.info` en ignorant les clés inconnues** (`parseInbound` de `native.ts` ne rejette pas un champ de plus dans `app.info`) : une application nouvelle sous un vieux site passe. En revanche le validateur de **résultat** rejette toute clé inconnue ; le résultat ne gagne **aucun** champ dans ce chantier.
5. **Ordre de publication** (règle de [`architecture.md`](architecture.md) § 12, le front avant l'application quand le protocole change) : (1) back avec `DRIVE_RETAILERS=leclerc` ; (2) site web (validateur du protocole qui connaît l'énumération) ; (3) application et agent (registres, `retailers` annoncé) ; (4) plus tard, quand l'adaptateur Auchan est prêt et reçu, l'humain ajoute `auchan` à `DRIVE_RETAILERS`. Entre (2) et (3) rien ne change pour l'utilisateur : le web ne propose que des enseignes que l'application annonce.
6. **Ancien site en cache** (service worker) sous un back récent : l'ancien front lit `retailer` et `store` de `GET /drive/settings`, qui valent tant qu'il n'y a qu'une enseigne activée. Tant que `DRIVE_RETAILERS` n'a que `leclerc`, c'est exact.
7. **Anciens exemples de contrat** : `settings.sample.json` gagne les champs ajoutés ; `DriveContractSamplesTest` les compare champ par champ, comme aujourd'hui.

## 9. Ce que deviennent la liste envoyée et le plan

- **La liste `SENT` ne porte pas l'enseigne.** Le verrou protège la **liste** de Repas (on ne la modifie plus pendant qu'on fait les courses), pas un panier particulier. Aucune colonne n'est ajoutée à `shopping_list` : `send`, `reopen`, `complete` ne changent pas (ADR 0023 intacte).
- **Le plan est calculé à la demande, pour une enseigne** : `GET /drive/plans/{id}?retailer=` (facultatif ; sans lui, l'enseigne de la sélection). Il lit les choix du magasin **de cette enseigne** ; une ligne `KNOWN` chez Leclerc peut être `NEW` chez Auchan, sans conflit.
- **Envoyer la même liste aux deux enseignes** est permis (la liste reste `SENT`, « Reprendre l'envoi » rouvre l'écran d'envoi, où l'on peut choisir l'autre) : c'est exactement ce qu'on fait si un produit manque chez la première. Repas ne suit pas ce qui est déjà au panier d'une enseigne (invariant du chantier) ; le récapitulatif dit toujours « dans ton panier {retailer} ».
- **Le brouillon de passage** (`repas.drive.run`, 24 h) et le retour portent l'enseigne : un résultat revenu pour une autre enseigne que celle du brouillon est ignoré avec le message déjà prévu pour un `runId` différent.
- **L'estimation du coût** est par enseigne ; comparer deux enseignes est au backlog, pas dans ce chantier.
- **Bandeau de la liste envoyée** : « Envoyée au drive le … » (déjà sans nom d'enseigne) ; il n'a pas besoin de l'enseigne.

## 10. Plan de découplage en tickets

Principe : **chaque ticket est petit et ne change aucun comportement visible tant qu'il n'y a qu'une enseigne**, ce que chacun prouve par un test qui rejoue le parcours existant et compare les sorties (réponses d'API, textes affichés, messages du protocole) à celles d'avant. Efforts selon la grille convenue ([`consignes-agents.md`](consignes-agents.md), skill des sous-agents) : xhigh sécurité, données et migration ; high fonctionnalité ; medium texte et documentation.

| Ticket | Dépôt | Taille | Effort | Titre | Dépend de |
|---|---|---|---|---|---|
| [DRV-701](tickets/DRV-701-registre-d-enseignes-du-back.md) | back | M | high | Registre d'enseignes du back, enseigne en paramètre des lectures | — |
| [DRV-702](tickets/DRV-702-interrupteur-par-enseigne.md) | back (+ `deploy/`) | S | high | Interrupteur par enseigne `DRIVE_RETAILERS` | 701 |
| [DRV-703](tickets/DRV-703-drive-habituel-et-derniere-enseigne.md) | back | M | xhigh | Drive habituel, dernière enseigne utilisée, retrait d'un drive, sélection | 701, 702 |
| [DRV-704](tickets/DRV-704-registre-d-enseignes-de-l-agent.md) | front (agent) | M | high | Registre d'enseignes de l'agent, types neutres, protocole par enseigne | 701 (contrat) |
| [DRV-705](tickets/DRV-705-textes-de-l-agent-avec-le-nom-de-l-enseigne.md) | front (agent) | S | medium | Textes de l'agent : le nom de l'enseigne en paramètre | 704 |
| [DRV-706](tickets/DRV-706-hotes-autorises-par-enseigne-dans-le-coeur-kotlin.md) | front (`android/core`) | M | xhigh | Hôtes autorisés, origines du pont et mémoires par enseigne (Kotlin) | 704 |
| [DRV-707](tickets/DRV-707-ecran-du-drive-par-enseigne.md) | front (`android/app`) | M | high | Écran du drive, injection, profil, menu et `app.info` par enseigne | 706 |
| [DRV-708](tickets/DRV-708-le-nom-de-l-enseigne-en-parametre-sur-le-web.md) | front (web) | M | medium | Le web : nom en paramètre, plus de littéral `leclerc` | 701 |
| [DRV-709](tickets/DRV-709-mes-drives-dans-moi.md) | front (web) | M | high | « Mes drives » dans « Moi » : liste, ajout, drive habituel | 703, 708 |
| [DRV-710](tickets/DRV-710-choisir-le-drive-a-l-envoi.md) | front (web) | M | high | Choisir le drive à l'envoi (R1 à R5), passage et retour par enseigne | 703, 707, 708, 709 |
| [DRV-711](tickets/DRV-711-documentation-par-enseigne.md) | back (docs) + front (docs) | S | medium | Runbook, recette, skills et documentation « par enseigne » | 701 à 710 |
| [DRV-712](tickets/DRV-712-spike-auchan-parcours-et-magasin.md) | humain | M | — | Spike A1 : parcours, mode drive ou livraison, magasin, site mobile ou ordinateur | — |
| [DRV-713](tickets/DRV-713-spike-auchan-anti-robot-et-connexion.md) | humain | M | — | Spike A2 : anti-robot et connexion | 712 |
| [DRV-714](tickets/DRV-714-spike-auchan-recherche-et-panier.md) | humain | M | — | Spike A3 : recherche, produits, panier | 712 |
| [DRV-715](tickets/DRV-715-decision-auchan.md) | assistant | S | medium | Décision « Auchan : go, mode assisté ou abandon » | 712, 713, 714 |
| [DRV-716](tickets/DRV-716-declarer-auchan-dans-les-registres.md) | back + front | M | high | Déclarer Auchan dans les quatre registres, éteint | 715, 701, 704, 706, 708 |
| [DRV-717](tickets/DRV-717-adaptateur-auchan-squelette.md) | front (agent) | L | high | Adaptateur Auchan (squelette) et faux site | 715, 716 |

Ordre conseillé : **701, 704, 708 en parallèle** (trois dépôts ou couches sans lien) ; puis 702, 703, 705, 706 ; puis 707, 709 ; puis 710 ; 711 en continu. **Les spikes 712 à 714 sont indépendants du code** : ils peuvent se faire dès maintenant, par l'humain, quand il le décide (et ne bloquent que 715 à 717). Les tickets suivant 717 (recherche, panier, entrée, cadence, recette Auchan) **seront écrits par DRV-715** d'après les rapports.

### Ce que « sans changer le comportement visible » veut dire, ticket par ticket

| Ticket | Preuve |
|---|---|
| 701 | Les réponses de `GET /drive/settings` (anciens champs), `plans`, `estimates`, `choices` sont **identiques octet pour octet** à `docs/drive/samples/*.sample.json` d'avant, hors champs ajoutés |
| 702 | `DRIVE_RETAILERS` absent = `leclerc` seul ; tous les tests de l'interrupteur global passent inchangés |
| 703 | Sans ligne `drive_retailer_preference`, la sélection est `AUTO` ou `NONE` comme avant ; migration compatible avec la version précédente du code |
| 704 | Les exemples valides et invalides du protocole (`examples/`), le schéma, l'empreinte de l'agent, les tests e2e contre le faux site : verts sans modification de leurs attentes |
| 705 | Les textes rendus sont identiques (test d'égalité sur les chaînes de l'agent) |
| 706 | `NavigationPolicyTest`, `BridgeMessagesTest`, `DrivePlanTest`, `StorePathMemoryTest` passent sans modification d'attente ; mêmes décisions pour toutes les adresses de leurs tables |
| 707 | `make android-e2e` identique ; mêmes chaînes affichées pour Leclerc |
| 708 | Mêmes textes rendus, mêmes appels d'API (corps et paramètres) pour Leclerc ; captures identiques |
| 709, 710 | Avec une seule enseigne configurée : aucun nouvel écran, mêmes touches ; les nouveaux éléments n'existent qu'avec deux enseignes activées |

## 11. Faits sur Auchan (recherche web du 2026-10-19)

**Méthode et limites.** Recherche web ordinaire (moteur de recherche et lecture de pages **tierces**). **Aucune requête automatisée vers `auchan.fr` ni aucun autre site Auchan** n'a été faite depuis cette machine : les pages `auchan.fr` ne sont citées que comme **résultats de recherche** (titre et extrait), jamais ouvertes. Aucune de ces sources n'est une confirmation par essai : tout est classé ci-dessous en **Rapporté** (une source tierce le dit), **Contradictoire** ou **Inconnu**. Pas d'avis juridique (le sujet des conditions d'utilisation est clos par l'utilisateur).

### 11.1 Ce que disent les sources

| # | Fait | Statut | Source (date) |
|---|---|---|---|
| A1 | Auchan France propose plusieurs modes de courses : **Drive** (retrait en voiture), **Drive piéton**, **Click & Collect**, **Retrait 1 h** (non alimentaire) et **livraison à domicile**. | Rapporté | Fiche App Store « Auchan FR - Courses & fidélité » ([lien](https://apps.apple.com/FR/app/id393068659), lue par recherche le 2026-10-19) ; extrait de la FAQ `auchan.fr` (résultat de recherche, 2026-10-19) ; [Bonial](https://www.bonial.fr/magazine/tout-savoir-sur-le-drive-auchan-et-la-livraison-a-domicile/) (non daté) |
| A2 | Le site demande de **choisir « un drive ou la livraison »** ; l'en-tête liste « Drive, Click & Collect, Livraison… ». | Rapporté | Capture de la page d'accueil par le service [Spider](https://spider.cloud/scrapers/auchan-scraper/), datée du **2026-08-07** (service commercial, non indépendant) |
| A3 | **Les prix ne s'affichent qu'après le choix d'un magasin** ; l'outil de Studio Amba choisit le magasin par code postal dans un navigateur sans écran, puis lit les pages en HTTP. Les cartes de produit sont lues dans des microdonnées schema.org (SKU, prix, stock ; **ni EAN ni nombre d'avis dans le HTML**). | Rapporté | [Apify, Studio Amba](https://apify.com/studio-amba/auchan-scraper) (fiche produit d'un vendeur, **non datée**) |
| A4 | **Anti-robot** : la fiche Apify écrit que « auchan.fr has no anti-bot » (proxys de centre de données suffisants) ; Spider a lu la page d'accueil en HTTP simple (200) le 2026-08-07 ; un article de 2023 parle de CAPTCHA, 403 et limitation de débit en général **sans nommer de produit** ; **aucune source ne nomme DataDome pour Auchan**. | **Contradictoire** et surtout **Inconnu** | [Apify](https://apify.com/studio-amba/auchan-scraper) ; [Spider](https://spider.cloud/scrapers/auchan-scraper/) (2026-08-07) ; [Geonode](https://geonode.com/blog/how-to-scrape-auchan) (2023-08/09). **À ne pas prendre pour une absence de protection** : le chantier a déjà vu DataDome bloquer l'adresse de l'utilisateur chez Leclerc (`recherche.md` § 3) ; un outil de collecte du catalogue ne dit rien du panier ni d'un compte connecté. |
| A5 | **Connexion** : une personne qui a automatisé la recherche et le panier d'Auchan dit que « les API de recherche et de panier sont solides », mais que la connexion a demandé de rétro-concevoir une **chaîne OAuth Salesforce en 6 étapes avec des jetons chiffrés**. | Rapporté, **source unique** (message d'un professionnel sur un réseau social, non ouvert : HTTP 999 ; lu par l'extrait de recherche) | [Profil LinkedIn](https://bg.linkedin.com/in/ivan-sheyenko-49709694), 2026-10-19 |
| A6 | **Pas d'API publique officielle**, pas de projet public de remplissage de panier trouvé ; des **collecteurs de catalogue** payants existent (Spider, Apify). `auchand-check` (npm) ne lit que les créneaux (déjà dans `recherche.md` § 3). | Rapporté (absence de résultat) | recherches du 2026-10-19 |
| A7 | Jow annonce un remplissage de panier chez Auchan (déjà dans `recherche.md` § 5) ; le moyen technique n'est pas documenté. | Rapporté | `recherche.md` § 5 |
| A8 | **Une application mobile existe** (liste de courses, favoris, compte nécessaire) ; le **site** serait la voie d'un agent. | Rapporté | App Store (ci-dessus) |
| A9 | Une plainte de forum dit que le **stock d'un produit peut être « indisponible » en mode Drive et disponible en « Livraison à domicile »** pour le même magasin. | Rapporté, **non confirmé** | résultats de [totalbug](https://www.totalbug.com/auchan-drive) (non daté) |
| A10 | Les adresses `auchan.fr` vues dans les résultats sont toutes sur **un seul hôte** `www.auchan.fr` avec des chemins du type `/…/ep-…` ; aucune ne contient un identifiant de magasin. | Rapporté (inférence : **le magasin n'est probablement pas dans l'adresse**) | résultats de recherche du 2026-10-19 |

### 11.2 Ce que cela change pour la conception

- **Le magasin d'Auchan n'est peut-être pas dans l'adresse** (A10, A3 : il se choisit par code postal, et une session s'en souvient). Le modèle `Store {id, label, baseUrl}` a été pensé pour Leclerc (adresse par magasin). Si c'est confirmé, le magasin devra être **lu dans la page** par l'agent (comme `setup` le fait déjà) et `baseUrl` ne sera plus une adresse de magasin : un ticket à écrire **après DRV-712** (§ 12). C'est la raison pour laquelle `Store` n'est pas touché maintenant.
- **Le « mode »** (Drive, Drive piéton, Click & Collect, livraison) peut changer les prix, le stock (A9) et les créneaux. Repas remplit un panier et ne choisit jamais de créneau : mais quel mode le panier porte à l'ouverture est une inconnue (U3).
- **L'identifiant de produit** n'est peut-être pas numérique (A3 parle de SKU) : d'où le motif porté par le registre (§ 4.1). Pas d'EAN dans le HTML (A3) : la reprise de choix par EAN (DRV-606) ne sera pas disponible pour Auchan avant d'en savoir plus.
- **La connexion** (A5) importe peu pour l'agent (la personne se connecte elle-même, dans la WebView) **sauf** si le parcours de connexion refuse une WebView (U6).
- **L'anti-robot** (A4) est le plus gros risque de planning : s'il existe, c'est le téléphone de l'utilisateur qui le prouvera (DRV-713).

### 11.3 Inconnues, à lever par les spikes

| # | Inconnue | Pourquoi elle compte | Spike |
|---|---|---|---|
| U1 | Comment le magasin se choisit-il et où se lit-il (adresse, page, mémoire de session) ? Un identifiant stable existe-t-il ? | Forme de `Store`, lecture du magasin par l'agent, `storeId` des clés | A1 |
| U2 | Le site mobile et le site ordinateur diffèrent-ils (routes, formulaires) ? Faut-il le mode ordinateur ? | `siteMode`, `UserAgent`, test de page mobile | A1 |
| U3 | Quel mode (Drive, piéton, Click & Collect, livraison) est actif au moment du panier, et change-t-il prix ou stock ? | Quelle page ouvrir, quel mode exiger, prix mémorisés | A1 |
| U4 | Y a-t-il une page d'accueil / un bouton « Commencer mes courses » équivalent, une page « introuvable » trompeuse ? | `readEntry`, `followEntry`, `DriveEntry` | A1 |
| U5 | Un anti-robot (DataDome ou autre) existe-t-il ? Défi, cookies, seuils, comportement après des requêtes en série | Cadence, détection de défi, faisabilité | A2 |
| U6 | La connexion fonctionne-t-elle dans une WebView ? Passe-t-elle par un fournisseur externe (Google, Apple) refusé en WebView ? | Faisabilité dans l'application | A2 |
| U7 | La recherche est-elle une requête lisible depuis la page (HTML, JSON embarqué, API) ? Quels champs ? | Adaptateur : `searchProducts` | A3 |
| U8 | L'ajout au panier : quelle requête, quantité **absolue** ou delta, jeton nécessaire ? | Invariant de la quantité plancher | A3 |
| U9 | Forme et stabilité de l'identifiant de produit ; EAN ; contenu net ; prix et prix au kilo | `productIdRule`, `Product`, `PackCalculator` | A3 |
| U10 | Disponibilité d'un produit : champ, valeurs trompeuses (cf. A9) | Phase A | A3 |
| U11 | Le panier est-il lisible (total, quantité d'un produit) sans écrire ? | `cartQuantity`, `pageCartTotal` | A3 |
| U12 | Photos : hôte, forme d'adresse | Photos du choix (DRV-633) | A3 |
| U13 | Les conditions d'utilisation | **Hors périmètre** (sujet clos par l'utilisateur) | — |

### 11.4 Après les spikes (2026-10-30)

Résultats dans [`spikes/DRV-712-rapport.md`](spikes/DRV-712-rapport.md), [713](spikes/DRV-713-rapport.md), [714](spikes/DRV-714-rapport.md) ; l'état de chaque fait est dans [`hypotheses-site-reel-auchan.md`](hypotheses-site-reel-auchan.md).

| # | Sort | Résultat |
|---|---|---|
| A1, A2 | **Confirmés** | Plusieurs modes ; le site demande « un drive ou la livraison » ; le magasin se choisit par code postal, en quatre gestes. |
| A3 | **Confirmé en partie** | Prix seulement après le choix d'un magasin (oui) ; produits lus dans le DOM et des microdonnées (oui) ; identifiant de produit = **UUID**, pas un SKU numérique ; **EAN** absent de la page de résultats mais présent dans la réponse du panier. |
| A4 | **Faux pour le site actuel** | DataDome est présent (cookie, balise, `x-datadome: protected`) et un reCAPTCHA Enterprise invisible garde la connexion et la création de compte ; **aucun défi vu** sur une centaine de requêtes (« non observé », pas « absent »). |
| A5 | **Contredit** | La connexion est un serveur **Keycloak** (`compte.auchan.fr`), pas une chaîne Salesforce visible ; e-mail et mot de passe, Apple, Google. |
| A6 | **Confirmé, et dépassé** | Pas d'API publique officielle ; mais le site expose un contrat JSON de panier propre (`/cart`, `/cart/update`, `/journey`) que l'agent appelle depuis la page, comme chez Leclerc. |
| A9 | **Non testé** | Stock selon le mode (drive ou livraison). |
| A10 | **Confirmé** | Le magasin n'est pas dans l'adresse ; il se lit par `GET /journey`. |

Inconnues : U1 **fermée** (`GET /journey`) · U2 **fermée** (un site adaptatif, pas de mode ordinateur à imposer) · U3 **partielle** (le mode est `channels` ; livraison non relevée) · U4 **fermée** · U5 **fermée** (présent, aucun défi vu) · U6 **ouverte** (WebView : DRV-726) · U7 **fermée** (HTML de `/recherche?text=`) · U8 **fermée** (quantité absolue) · U9 **fermée** (UUID, et un identifiant d'offre) · U10 **partielle** (valeurs « indisponible » non vues) · U11 **fermée** (`GET /cart`) · U12 **fermée** (`cdn.auchan.fr`) · U13 hors périmètre.

## 12. Les spikes sur téléphone

Règles communes (reprises dans chaque ticket) : **un humain, sur son téléphone, avec son propre compte, jamais depuis un ordinateur ni un serveur** (DataDome a marqué l'adresse de l'utilisateur chez Leclerc) ; **rien d'automatisé** ; à faible cadence ; aucun identifiant, cookie, numéro de magasin, ville ni HTML brut dans le dépôt (public) : on note des **formes**, des **noms de champs**, des **codes** et des **tailles**, avec des exemples inventés. Rapports dans `docs/drive/spikes/DRV-71x-rapport.md`, sur le modèle de [`spikes/DRV-002-rapport.md`](spikes/DRV-002-rapport.md).

- [DRV-712](tickets/DRV-712-spike-auchan-parcours-et-magasin.md) : parcours, choix du magasin et du mode, site mobile ou ordinateur, accueil (U1 à U4).
- [DRV-713](tickets/DRV-713-spike-auchan-anti-robot-et-connexion.md) : anti-robot et connexion (U5, U6).
- [DRV-714](tickets/DRV-714-spike-auchan-recherche-et-panier.md) : recherche, produits, panier (U7 à U12). Pour voir les requêtes, le téléphone peut être **observé** par le débogage USB de Chrome (le trafic reste celui du téléphone) ; c'est à l'humain d'en décider, et le site n'est jamais ouvert dans un navigateur de l'ordinateur.
- [DRV-715](tickets/DRV-715-decision-auchan.md) : la décision (adaptateur complet, mode assisté seulement, ou abandon) et la liste des tickets suivants.

## 13. Ordre par rapport à la fin du drive Leclerc

> **Décision du 2026-10-30** : le propriétaire a lancé Auchan sans attendre la fin de la recette de Leclerc ; le découplage (DRV-701 à 711) et l'adaptateur d'Auchan (DRV-716, 717, 720 à 728) se font en parallèle, **Auchan restant éteint**. Ce qui suit est le raisonnement du 2026-10-19.


L'utilisateur a dit « dans le futur, quand le drive Leclerc sera fini ». Le découplage (701 à 711) **ne dépend pas** de la fin de la recette de Leclerc et ne modifie pas son comportement ; l'adaptateur Auchan (716, 717 et suivants) vient après. Les spikes (712 à 714) peuvent se faire à tout moment où l'utilisateur a un quart d'heure : ils ne touchent pas au code. **Recommandation** : faire d'abord les tickets qui **empêchent d'aggraver** (la règle « Ne pas durcir Leclerc » de [`consignes-agents.md`](consignes-agents.md) s'applique dès maintenant à tout ticket en cours), puis 701 → 704 → 708 (peu de risque, beaucoup d'effet) ; garder 706/707 (Android, sensible) pour après la publication stable de la version en cours de l'application.

## 14. Décisions prises par défaut et questions ouvertes

| # | Décision (par défaut, réversible) | À confirmer par l'utilisateur ? |
|---|---|---|
| D-M1 | La règle de choix R1 à R5 (§ 5) | Oui, à la lecture de l'ADR 0027 : c'est le seul point de produit du chantier |
| D-M2 | Aucun nom d'enseigne codé en dur hors du registre de chaque couche ; le nom est un paramètre des textes | Non (architecture) |
| D-M3 | Un interrupteur par enseigne en plus de l'interrupteur global ; Auchan éteint tant que l'humain ne l'allume pas | Non |
| D-M4 | « J'en ai toujours » reste par enseigne et par magasin dans ce chantier ; au backlog s'il gêne | Non |
| D-M5 | Le plafond quotidien d'écritures de produits reste porté par le réglage, donc par enseigne (5 000 × enseignes activées) | Non |
| D-M6 | Un profil de WebView par enseigne, pour que « Se déconnecter de … » n'efface qu'une enseigne | Non |
| D-M7 | `Store` n'est pas modifié avant le spike A1 | Non |
| D-M8 | La liste `SENT` ne porte pas l'enseigne | Non |
| Q1 | Le drive Auchan visé est **auchan.fr (France)** seulement (pas Luxembourg, Portugal…) | **Tenue par défaut** (les spikes ont été faits sur `auchan.fr`) ; à corriger si ce n'est pas le cas |
| Q2 | Faut-il garder « Chercher à la main » (mode assisté) pour Auchan si l'automatisation est impossible ? | **Tranchée le 2026-10-30 : non**, adaptateur complet (DRV-715). Le mode assisté reste possible comme repli si DRV-726 prouve qu'une connexion en WebView est impossible |
| D-M9 | `Store` d'Auchan : identifiant explicite (UUID lu par `GET /journey`), adresse d'enseigne constante, colonnes `store_id` élargies à 40 (DRV-720) | Non (architecture) |
| D-M10 | Un produit d'Auchan porte un identifiant d'**offre** facultatif en plus de son identifiant (DRV-721, 722) | Non |
| D-M11 | Le pont et l'agent ne sont injectés que sur `www.auchan.fr`, jamais sur `compte.auchan.fr` (page du mot de passe) (DRV-728) | Non (sécurité) |

## 15. Sources

Dépôt : `src/Drive/**`, `docs/drive/**`, ADR 0022, 0023, 0024, 0025 ; dépôt front : `src/features/drive/`, `drive-agent/`, `android/ARCHITECTURE.md`, `android/core/`, `android/app/`. Web (consultées le 2026-10-19, voir § 11.1 pour les réserves) : [App Store Auchan](https://apps.apple.com/FR/app/id393068659) · [Bonial](https://www.bonial.fr/magazine/tout-savoir-sur-le-drive-auchan-et-la-livraison-a-domicile/) · [Spider](https://spider.cloud/scrapers/auchan-scraper/) · [Apify Studio Amba](https://apify.com/studio-amba/auchan-scraper) · [Geonode](https://geonode.com/blog/how-to-scrape-auchan) · [LinkedIn, I. Sheyenko](https://bg.linkedin.com/in/ivan-sheyenko-49709694) · [totalbug](https://www.totalbug.com/auchan-drive) · [Scrape.do Intermarché](https://scrape.do/industries/ecommerce/intermarche-scraper/) (Intermarché est derrière DataDome : indice sur la famille de protections, pas sur Auchan).
