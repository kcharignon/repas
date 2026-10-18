---
name: repas-drive-back
description: Développer le back du chantier « envoyer la liste de courses au drive Leclerc » (contexte `Drive`, verrou de liste `SENT`, interrupteur `DRIVE_ENABLED`, ports `Shared`, plan d'envoi, produits et préférences). À utiliser en complément de `repas-dev` pour tout ticket DRV-1xx, DRV-2xx ou toute modification de `src/Drive/`, des listes de courses envoyées, des unités (`dimension`) ou de l'API `/api/v1/drive/*`.
---

# repas-drive-back — pièges et conventions du back Drive

Complète `repas-dev` (recettes de lecture, d'API et d'écriture : ne pas les redécouvrir ici). Ce skill ne dit que ce qui est propre au chantier.

## 0. Avant toute chose

1. Lire `docs/drive/README.md`, `docs/drive/consignes-agents.md`, `docs/drive/architecture.md` (§ 4 verrou, § 5 contexte, § 7 API), ADR 0022 et 0023, puis le ticket `docs/drive/tickets/DRV-xxx-*.md` (autonome, il fait foi) : ses sections « Réalisation » et « Pièges connus » valent contrat pour les tickets suivants.
2. Vérifier dans `docs/drive/roadmap.md` que le ticket n'est pas déjà fait et que ses dépendances sont fusionnées. Ne rien faire de plus que le ticket demandé.
3. Règles transverses (détail : `consignes-agents.md`) : aucun contact avec Leclerc ni aucune donnée réelle (valeurs factices `123456`, `Ville-Test`) ; jamais de déploiement, de release `vX.Y.Z`, de secret ni d'activation de `DRIVE_ENABLED` en production ; une PR n'est fusionnée que si tous les contrôles de la CI sont verts ; commits courts en français **sans `Co-Authored-By`** ; plusieurs agents travaillent dans le dépôt : travailler dans un worktree ou un clone à part, jamais dans le répertoire d'un autre agent.

## 1. Carte du code (chemins vérifiés)

| Sujet | Où |
|---|---|
| Contexte Drive | `src/Drive/{Domain,Application,Infrastructure}` (modèles : `Domain/Model/`, ex. `Choice`, `Product`, `Retailer`, `Store` ; `Domain/Service/PackCalculator.php`) |
| Erreurs Drive | `src/Drive/Domain/Exception/DriveException.php` (constantes de classe + factories) |
| Ports partagés | `src/Shared/Application/Interface/{DriveFeature,ShoppingListForDriveLoader,IngredientNameLoader}.php`, DTO `src/Shared/Application/Dto/ShoppingListForDrive*.php`, `Shared/Domain/Model/Dimension.php` |
| Implémentations des ports | `src/Repas/Infrastructure/Loader/{ShoppingListForDrive,IngredientName}PostgreSQLLoader.php`, `src/Shared/Infrastructure/Feature/EnvDriveFeature.php` |
| Interrupteur | `Shared/Infrastructure/Http/RequiresDrive.php` + `DriveEnabledListener.php`, `Shared/Domain/Exception/DriveFeatureException.php` |
| Plafond de débit par utilisateur | `src/Drive/Infrastructure/Http/{DriveRateLimit,RateLimitedPerUser,DriveRateLimitListener}.php`, limiteurs `drive_write` et `drive_read` de `config/packages/rate_limiter.yaml`, réponses `429` de `config/packages/nelmio_api_doc.yaml`, helper de test `tests/Helper/RateLimiterTimeTravel.php` |
| Verrou de liste | `src/Repas/Domain/Model/ShoppingList.php` (`send`, `reopen`, `assertEditable`), `Application/Command/{Send,Reopen}ShoppingList/` |
| Alias des ports d'écriture | `config/repositories.yaml` (à compléter pour tout nouveau `*Repository`) |
| Deptrac | `quality-rules/Deptrac/deptrac.bounded-context.yaml` (`Drive: [Shared]`), `deptrac.no-database-in-handler-tests.yaml` |
| Budgets SQL | `tests/Repas/WriteBudget/{scenarios,budgets}.php` (aussi pour les use cases Drive), helpers `tests/Helper/WriteBudgetTestCase.php` |
| Purge du compte | `Drive/Application/Command/PurgeUserDriveData/`, `Drive/Infrastructure/EventListener/PurgeUserDataOnUserDeletedListener.php`, `tests/Shared/Infrastructure/UserOwnedDataCoverageTest.php` |
| Tests Drive | `tests/Drive/{Domain,Application,InputAdapter,OutputAdapter}/`, doubles `tests/Helper/InMemory{Repository,Loader}/`, builders `tests/Helper/Builder/` |

## 2. Règles du domaine

### Dépendances et ports `Shared`
- `Drive → Shared` seulement (Deptrac). `Drive` n'importe ni `Repas` ni `User` : ce dont il a besoin passe par un port `Shared` implémenté dans `Repas`. Utilisateur du jeton : `#[CurrentUser] AuthenticatedUser`, jamais `User\Domain\Model\User`.
- Un contexte n'importe pas l'exception d'un autre : `DriveException` redéfinit les codes dont il a besoin (`SHOPPING_LIST_NOT_SENT`, `SHOPPING_LIST_FORBIDDEN`) avec **le même message** que `ShoppingListException`.
- Les handlers injectent les **interfaces** `Shared` (jamais les classes de `Repas`). `ShoppingListForDriveLoader::get()` ne vérifie pas les droits : voter `SHOPPING_LIST_OWNER` dans le controller, et propriétaire re-contrôlé dans le loader du plan.
- Le fichier de test d'un `QueryHandler` ou d'un `ApiController` est obligatoire et se nomme `{NomDeLaClasse}Test.php` (règle PHPStan `EnsureControllerAndHandlerHaveTestRule`).

### Interrupteur `DRIVE_ENABLED`
- Tout controller Drive et `send` portent `#[RequiresDrive]` : `404 DRIVE_DISABLED` après la sécurité (401 d'abord) et avant la résolution des arguments (pas de 422). **Deux exceptions, sans l'attribut** : `GET /api/v1/drive/settings` (renvoie `enabled: false`) et `reopen` (DRV-613, ADR 0023 §9 : il n'appelle rien de l'enseigne, une coupure ne doit pas figer les listes `SENT` ; sécurité et voter restent). `DriveRoutesAreGuardedBySwitchTest` tient la liste explicite des exceptions (`ALWAYS_ANSWERING`) : en ajouter une est une décision, pas un oubli.
- Une variable d'environnement de production se déclare **explicitement** dans `deploy/compose.yaml` (`${DRIVE_ENABLED:-false}`), sinon la valeur du `.env` embarqué dans l'image s'applique en silence. Toucher `deploy/` impose `make deploy-smoke`. L'activation en production est humaine.
- En test, `DRIVE_ENABLED=true` (`.env.test`, `phpunit.dist.xml`) ; le cas coupé s'essaie avec `tests/Helper/ControlledDriveFeature.php`.

### Plafond de débit par utilisateur (DRV-615, constat C2)
- Toute route de `/api/v1/drive/*` porte `#[RateLimitedPerUser(DriveRateLimit::WRITE)]` (écritures, **60 par minute**, un compteur commun) ou `READ` (lectures qui coûtent 4 requêtes SQL : plan, estimation, **120 par minute**, un compteur commun), ou figure dans `UNLIMITED` de `DriveRoutesAreRateLimitedTest` (aujourd'hui `GET /drive/settings` et `GET /drive/choices`) : en ajouter une est une décision, pas un oubli. Le même test impose la réponse `#[OA\Response(response: 429, ref: '#/components/responses/DriveWriteRateLimited')]` (ou `DriveReadRateLimited`) sur chaque route plafonnée.
- `DriveRateLimitListener` (`kernel.controller`, priorité **-10**) passe après la sécurité et `DriveEnabledListener` (interrupteur coupé : `404`, aucun jeton consommé) et avant les arguments (un corps invalide compte). Clé `user-<id du jeton>`, jamais l'adresse IP seule. Dépassement : `TooManyRequestsHttpException` + `Retry-After` enveloppant `DriveException::rateLimited()` ; `ApiExceptionListener` affiche le code de l'erreur métier enveloppée quand son code est celui du statut HTTP : `{error:{message:"DRIVE_RATE_LIMITED"}}`.
- **Cache, pas base** : l'état de la fenêtre glissante est dans `cache.rate_limiter` (aucune requête SQL, budgets `WriteBudget` inchangés, une machine unique ; en test, pool en mémoire `test.rate_limiter_pool` et `disableReboot()` pour garder le noyau d'une requête à l'autre). Le composant lit l'heure avec `microtime()` : on ne lui injecte pas d'horloge, on **fait passer le temps** avec `RateLimiterTimeTravel::advance()` (recule la fin de fenêtre dans le pool) ; une fenêtre glissante ne se vide complètement que deux intervalles après le dernier coup (avancer de 121 s). Pour vider un compteur sans lancer 60 vraies écritures, appeler les routes d'essai de `DriveProbeController` (même compteur). `Retry-After` est celui du composant, optimiste après une rafale (souvent 1) : le front le traite comme un minimum.

### Verrou `SENT` (ADR 0023)
- Le verrou est un invariant de l'agrégat : toute **nouvelle** méthode qui modifie `ShoppingList` appelle `assertEditable()` ; un handler qui modifie une entité fille par identifiant (comme `UpdateServingMealHandler` avec `Meal`) l'appelle explicitement ; cocher/décocher passent par `ShoppingListRepository::isSent()`.
- `setStatus` reste sans garde (builders et infra). `completed()` reste permis depuis `SENT`. `send` sur une liste déjà `SENT` ne réenregistre pas (version inchangée). `UncheckLine` ne réactive jamais une liste `SENT`.
- Planifier une recette, retirer un repas et `+`/`-` d'un ingrédient passent par la liste **active** : avec la seule liste `SENT` ils répondent `404`, pas `409`.
- Un envoi ne coche jamais de ligne ; le panier de l'enseigne n'est jamais vidé.

### Migrations additives
- Compatibles avec la version précédente du code (ADR 0017 règle 6) : nouvelles tables, colonnes **nullables**, jamais de suppression ni de renommage dans la même version. Le retour arrière ne défait pas le schéma. Documenter dans la migration le SQL de retour arrière si un état nouveau (`SENT`) existe.
- Nom `migrations/Version2026MMJJHHMMSS.php`, `make migrate`, `make schema-validate` vert. Les entités Doctrine de `Drive` sont de simples mappings (`Infrastructure/Entity/`) : le schéma vient de la migration.

### Écritures SQL directes (DRV-204)
- `ChoicePostgreSQLRepository` et `ProductPostgreSQLRepository` écrivent en **SQL direct** : une requête par table quelle que soit la taille du lot (`INSERT … ON CONFLICT`). Les lignes `drive_choice_product` d'une préférence sont **supprimées puis réinsérées** (jamais mises à jour une à une : `UNIQUE (choice_id, rank)` casse pendant un décalage de rangs). Aucune préférence n'est dans l'identity map.
- Aucune clé étrangère : l'intégrité est dans le code et dans la transaction du `command.bus`.
- Identifiant d'un `Product` = UUID v5 de la clé naturelle `(retailer, storeId, retailerProductId)` : deux requêtes concurrentes ne créent pas de doublon.
- Une méthode de `Choice` qui échoue ne change rien ; `addPrimary`, `addFallback` et `reject` font redevenir « produits » un ingrédient « j'en ai toujours ».
- **Premier écrivain gagnant (DRV-613, constat C1)** : une fiche `drive_product` est commune à tout le magasin. `Product::complete()` ne remplit que les champs vides (code-barres, contenu, prix) ; le libellé n'est jamais réécrit ; l'upsert SQL garde en plus libellé, code-barres et contenu déjà écrits (`COALESCE`), même si deux requêtes créent la fiche en même temps. Le prix d'une fiche connue ne change que par `REFRESH_PRICE` ou l'`ADD_PRODUCT` d'un produit que l'utilisateur accepte **déjà** pour cet ingrédient (`Product::seePrice()`). Les valeurs reçues restent contrôlées (422) même quand elles sont ignorées. Reste, assumé : le prix est modifiable par quiconque accepte le produit (seules des données par utilisateur le protégeraient entièrement).
- **Plafonds par utilisateur (DRV-613, constat C2)** : `Choice::MAX_PER_USER_AND_STORE` = 1 000 préférences par utilisateur et par magasin (`ChoiceRepository::countByUserAndStore`, lu seulement quand un lot ou un remplacement **crée** une préférence) ; `DriveSettings::MAX_PRODUCT_WRITES_PER_DAY` = 5 000 écritures de produits (intentions `ADD_PRODUCT`, `REJECT_PRODUCT`, `REFRESH_PRICE`) par jour UTC, comptées dans `drive_settings.product_writes_day` / `product_writes_count` (les réglages sont déjà lus : aucune lecture de plus ; une mise à jour de plus quand le lot écrit des produits). Au-delà : `422 DRIVE_LIMIT_REACHED`, rien n'est enregistré. Un changement de magasin ne remet pas le compteur à zéro.
- `drive_product` est alimenté par les agents des utilisateurs : toute entrée est bornée et validée (id `^[0-9]{1,12}$`, libellé 1..255, EAN 8..14 chiffres, contenu 0,001..100000, prix 0..1 000 000 centimes, slug `^[a-z0-9-]{1,255}$`, 5 acceptés / 20 refusés / 200 intentions). Jamais de HTML, aucune route qui liste ou cherche dans `drive_product` en dehors des produits des propres choix de l'utilisateur.

### Ordre et sens des intentions (`PUT /drive/choices`)
- Intentions appliquées **dans l'ordre reçu** pour un même ingrédient. `ADD_PRODUCT` et `REJECT_PRODUCT` sur un ingrédient « j'en ai toujours » le font redevenir « produits » : pour garder « j'en ai toujours » avec des refus, envoyer `HAVE_AT_HOME` **après** les `REJECT_PRODUCT` (il efface tout).
- Un produit à la fois ajouté et refusé pour un même ingrédient dans un lot : `422 DRIVE_CHOICE_INVALID`, rien d'enregistré. `REFRESH_PRICE` n'a ni libellé ni placement et est **sans effet, sans erreur**, si le produit est inconnu ou non accepté par l'utilisateur pour cet ingrédient.
- `PUT /drive/choices/{slug}` (DRV-208) **remplace d'un coup** la préférence d'un ingrédient (l'ordre de `accepted` donne les rangs) : absente = créée ; listes vides et `haveAtHome` faux = préférence supprimée (sans erreur si elle n'existait pas) ; `haveAtHome` vrai efface les produits, et avec des produits dans la demande = `422` ; un produit n'est jamais supprimé de `drive_product`.
- Ordre des contrôles du handler : lot et intentions (aucune lecture), réglages (`DRIVE_NOT_CONFIGURED`, `DRIVE_STORE_MISMATCH`), plafond du jour (`DRIVE_LIMIT_REACHED`), puis produits, préférences, plafond de préférences, écritures. Le prix n’est pas renvoyé par `GET /drive/choices` (l’estimation du coût d’une liste est `GET /drive/estimates/{id}`, DRV-611, fait).

### Plan d'envoi (`GET /drive/plans/{id}`)
- Un `QueryHandler` délègue au loader ; le loader peut appeler `PackCalculator` (fonction pure du domaine) mais ne manipule aucun agrégat. 4 requêtes au plus quelle que soit la liste. `packs` est calculé **par produit** ; `searchQuery` = nom de l'ingrédient tel quel. Changer ce contrat = changer le message `plan` du front (voir § 5).
- `PackCalculator` : quantité invalide testée en premier ; plafond 99 ; tolérance 1e-9 (1000 g pour 1000 g donne 1, pas 2) ; tout cas douteux = `null` + `TO_CHECK`.

### Erreurs, version, API
- Les erreurs métier sont des **codes en majuscules** (constantes de classe + factory statique), rendus tels quels par `ApiExceptionListener` : `{error:{message:"DRIVE_NOT_CONFIGURED"}}`. Le front les distingue par ce texte : ne pas les renommer. `403` réservé à l'autorisation ; domaine = 404 / 409 / 422.
- Liste de courses : `?int $expectedVersion = null` dans toute nouvelle commande, `assertVersion()` juste après le chargement, `EntityVersion::expected($request)` dans le controller, `#[OA\Parameter(ref: '#/components/parameters/IfMatch')]` et réponse `412`.
- Aucun plan, choix ni donnée d'enseigne en query string (Caddy journalise l'URI) ; aucun champ de mot de passe, cookie ou jeton d'enseigne dans `docs/api/openapi.json`. Les identifiants de produit sont des **chaînes** (`"555111"`) ; ne pas confondre `retailerProductId` (identifiant de l'enseigne, `PUT /drive/choices`) et `productId` (identifiant Repas, UUID v5, lu dans `GET /drive/choices` et renvoyé par `PUT /drive/choices/{slug}`).
- Après tout changement d'API : `make openapi`, relire le diff de `docs/api/openapi.json` ; n'ajouter que des champs (le front fige le contrat sur un commit), jamais retirer ni renommer.

### Purge du compte
- Toute entité qui gagne une colonne `owner|author|creator|user` est ajoutée à `PurgeUserDriveDataHandler` (ou à son équivalent) **et** à `UserOwnedDataCoverageTest::ERASED_WITH_THE_USER` ; `drive_choice_product` (sans colonne utilisateur) se purge par les ids des préférences de l'utilisateur. Le budget de `supprimer-compte` monte alors : l'écrire dans `budgets.php` (ADR 0021).

### Budgets SQL (ADR 0010 règle 12)
- Tout use case d'écriture a un scénario dans `tests/Repas/WriteBudget/scenarios.php` (même pour `Drive`) ; un budget ne fait que baisser : trop gourmand = `make test` rouge ; allégé = `make sql-budget-update` et `budgets.php` dans le commit. Les budgets Drive actuels (`reglages-drive` 2, `choix-drive` 8, `choix-drive-lot` 9, `oublier-choix-drive` 4, `remplacer-preference-drive` 6, `creer-preference-drive` 7) sont constants quel que soit le lot ou la taille de la préférence : c'est voulu. DRV-613 les a relevés, en connaissance de cause : +1 pour le compteur du jour (mise à jour des réglages) sur `choix-drive*`, +1 pour le décompte des préférences quand le lot ou le remplacement en crée une.

## 3. Tests

- Handler (Command ou Query) : **unitaire**, doubles en mémoire (`ChoiceInMemoryRepository`, `DrivePlanInMemoryLoader`…) + `SpyEventDispatcher` ; **jamais** base, kernel ni classe d'`Infrastructure` (Deptrac). Loader : test dans `tests/Drive/OutputAdapter/` sur base réelle (`DatabaseTestCase`, données créées par le test, nombre de requêtes compris). Repository : idem. Domaine : tests à table (`PackCalculatorTest`, `ChoiceTest`).
- Controller : `QueryBusWebTestCase` + `mockCommandBus()` / `MockQueryBus` (une requête par test : le noyau redémarre), clés JSON, 401, 403, 404, 409, 422 ; plus un parcours de bout en bout avec les vrais bus par ressource (`DriveChoicesApiFlowTest`, `DrivePlanApiFlowTest`, `ShoppingListWriteApiFlowTest`).
- Fixtures et exemples : données inventées (`123456`, `Ville-Test`, hôte `*.leclercdrive.fr` factice), jamais un vrai numéro de magasin.

## 4. Recettes

### 4.1 Nouveau use case d'écriture Drive (modèle : `ReplaceIngredientPreference`, DRV-208, fait)
1. Domaine d'abord : une méthode **dédiée** de `Choice` qui valide tout ou ne change rien (`Choice::replace`), plutôt que de composer `markHaveAtHome`, `addFallback` et `reject`, qui laisseraient passer un doublon ou un produit dans les deux listes ; test à table ; exception par factory dans `DriveException`.
2. Port (`Domain/Interface`), implémentation PostgreSQL (SQL direct si lot), alias dans `config/repositories.yaml`, double `InMemory`, builder.
3. `Application/Command/{UseCase}/` : Command `readonly` + Handler `#[AsMessageHandler]` ; ordre des contrôles (bornes sans aucune lecture, réglages, charges en une requête, écritures) ; magasin = celui des réglages sinon `DRIVE_STORE_MISMATCH` ; un identifiant de produit doit désigner un produit **de ce magasin**, sinon `DRIVE_CHOICE_INVALID`.
4. Controller `Infrastructure/Http/Api/{UseCase}/` : `#[RequiresDrive]`, `#[RateLimitedPerUser(DriveRateLimit::WRITE)]`, `#[IsGranted('ROLE_USER')]`, `#[MapRequestPayload]`, `204`, `#[OA\…]` complètes (404 `DRIVE_DISABLED`, 409, 422, 429 par `ref`).
5. Scénario `WriteBudget` ; `make openapi`.

### 4.2 Nouvelle lecture Drive
Recette `repas-dev` § 3.1 + 3.2, avec : port dans `Drive/Application/Loader/`, loader `…PostgreSQLLoader` (nombre de requêtes constant, noms d'ingrédients par `IngredientNameLoader`, slug à défaut), DTO `final readonly`, test du loader avec comptage de requêtes. Une lecture coûteuse (plusieurs requêtes SQL, comme le plan et l'estimation) reçoit `#[RateLimitedPerUser(DriveRateLimit::READ)]` et son `429` ; une lecture bornée par les données de l'utilisateur seul (réglages, préférences) rejoint `UNLIMITED` de `DriveRoutesAreRateLimitedTest`.

### 4.3 Nouvelle table ou colonne
Migration additive → entité de mapping → repository/loader → purge si elle désigne un utilisateur → `UserOwnedDataCoverageTest` → `make schema-validate`.

### 4.4 Nouvelle mutation d'une liste de courses
`assertEditable()` dans la méthode de l'agrégat, test « refuse `SENT` » dans `tests/Repas/Domain/`, `409 SHOPPING_LIST_SENT` documenté dans l'annotation du controller, `expectedVersion`, scénario de budget.

## 5. Contrat avec le front et l'agent

Le front fige `docs/api/openapi.json` sur un commit du back (`npm run api:update`) ; l'agent lit le plan recopié par le front (`drive-agent/protocol/plan.schema.json`). Changer le format du plan, des intentions ou d'une erreur = amender `docs/drive/architecture.md` §§ 7-8 **et** prévenir le ticket front concerné dans la PR (« À relire »). Les exemples `docs/drive/samples/*.sample.json` (DRV-501) reflètent les réponses réelles : `tests/Drive/Contract` échoue quand un DTO change, `make drive-samples` les régénère (relire le diff, c'est le contrat que le front fige).
 Le back n'ouvre jamais Leclerc et ne stocke aucun identifiant, cookie ou jeton d'enseigne.

## 6. Definition of Done

- [ ] `make test` vert, `make quality` vert (`deptrac`, `phpstan` sans nouvelle entrée de baseline, `api-doc-validate`, `api-doc-coverage`, `openapi-check`)
- [ ] `make openapi` relu si l'API change ; `make deploy-smoke` si `deploy/`, `Dockerfile` ou entrypoint changent ; `make audit` si `composer.*` change
- [ ] Budget `WriteBudget` posé ou revu ; purge du compte et `UserOwnedDataCoverageTest` à jour si colonne utilisateur
- [ ] Ticket : statut « fait (PR #…, date) », section « Réalisation » (contrat pour les tickets suivants, écarts), case cochée dans `docs/drive/roadmap.md`, `architecture.md` amendé si un contrat change
- [ ] PR (Quoi / Vérifié / À relire), CI entièrement verte (`gh pr checks`) avant `gh pr merge --squash --delete-branch` ; l'étape « Check HTTP reachability » du job « Tests » est parfois instable : `gh run rerun --failed`, jamais en la contournant ; issue fermée (`gh issue close <n>`)

## 7. Erreurs fréquentes

- `Repository` dans un `QueryHandler`, `Loader` dans un `CommandHandler`, base ou kernel dans un test de handler.
- Importer `Repas\Repas\…` depuis `Drive` ; oublier `#[RequiresDrive]` ; oublier l'alias dans `config/repositories.yaml` ou `UserOwnedDataCoverageTest` après une nouvelle colonne utilisateur.
- Une migration qui supprime, renomme ou rend non nulle une colonne utilisée par la version précédente.
- Mettre à jour une à une les lignes `drive_choice_product`, ou une requête par intention (le budget échoue).
- Oublier `#[RateLimitedPerUser]` (ou son `429` OpenAPI) sur une nouvelle route du drive : `DriveRoutesAreRateLimitedTest` échoue.
- Renommer un code d'erreur ou retirer un champ de réponse (le front et l'agent le lisent).

## 8. Interdits

Contacter Leclerc ; mettre une donnée réelle dans le code, les tests ou la doc ; activer `DRIVE_ENABLED` en production ; déployer ou créer une release ; `--no-verify`, force-push, push sur `main` ; fusionner une PR dont un contrôle de la CI n'est pas vert ; `Co-Authored-By` dans un commit ; modifier les ADR 0022 et 0023 sans que le ticket le demande.
