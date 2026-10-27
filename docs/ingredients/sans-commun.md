# Plus d'ingrédient commun : le catalogue de l'administrateur

Plan d'exécution de l'[amendement du 2026-10-20 à l'ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md#amendement-du-2026-10-20--plus-dingrédient-commun). Rédigé le 2026-10-20 par l'assistant architecte, qui n'implémente pas. Il **remplace** ce que [`migration.md`](migration.md) § 12 et les tickets ING-008 et ING-009 d'origine prévoyaient pour la suite de la migration (adoption à la première utilisation, puis propriétaire du catalogue) ; il ne change rien à ce que la migration ING-003 a déjà fait (6 comptes migrés, le 2026-10-20). Tickets : [ING-008](tickets/ING-008-amorcage-des-comptes.md), [ING-009](tickets/ING-009-passation-des-communs.md), [ING-010](tickets/ING-010-documentation-recette-exploitation.md), [ING-011](tickets/ING-011-marqueur-de-base.md) à [ING-015](tickets/ING-015-front-ingredient-de-base.md). Roadmap : [`roadmap.md`](roadmap.md).

**Aucune donnée personnelle ici** : ni adresse e-mail (le compte protégé est `User::PROTECTED_EMAIL`), ni identifiant d'utilisateur, ni nom d'ingrédient personnel. Les noms du fichier [`base-ingredients.tsv`](base-ingredients.tsv) sont ceux des anciens communs, des ingrédients de référence publics.

## 1. Le modèle cible, en dix lignes

1. **Plus aucun ingrédient « commun ».** Tout ingrédient a un propriétaire : `ingredient.creator` n'est plus jamais vide, et plus aucun code ne s'appuie sur « vide ».
2. Les **261 anciens communs deviennent des ingrédients personnels** du **propriétaire du catalogue**, le compte administrateur protégé (`User::PROTECTED_EMAIL`, un seul). Les slugs ne bougent pas : ses recettes, ses listes (dont les 8 envoyées au drive) et ses 12 choix du drive **ne sont pas touchés**.
3. L'administrateur est **un utilisateur comme les autres** pour les ingrédients : il crée, modifie, supprime et fusionne les siens, et ne voit pas ceux des autres.
4. **Tout compte a ses ingrédients.** À l'activation (confirmation d'adresse, mot de passe oublié d'un compte en attente, activation par l'administrateur, création par Google), un compte reçoit **une copie des ingrédients « de base » du propriétaire**, avec leurs conversions propres et un alias par copie, dans la transaction de l'activation. Pas à l'inscription. Une seule fois.
5. **« De base »** est un marqueur (`ingredient.is_base`) que **seul le propriétaire** pose ; il dit « ceci part aux nouveaux comptes ». Classement des anciens communs, validé : **151 de base, 110 spécifiques** ([`base-ingredients.tsv`](base-ingredients.tsv)).
6. **Rien n'est propagé après coup** : ce que le propriétaire change ou ajoute ne concerne que les comptes futurs. Les 6 comptes déjà migrés gardent leurs 261 copies. Rien n'est supprimé chez personne.
7. Le **compte vide** (ni ingrédient ni recette, jamais migré) reçoit son amorçage avant la passation.
8. **L'adoption à la première utilisation disparaît** (plus de filet : l'atomicité de l'activation et une requête de santé le remplacent). La table d'alias reste : anciens slugs (onglets, brouillons d'envoi du drive), fusions (`kind = merge`), provenance (`adopted_from`).
9. L'amorçage automatique se coupe par une variable d'environnement (`INGREDIENT_SEEDING_ENABLED`, coupée par défaut, allumée par l'humain, comme `DRIVE_ENABLED`).
10. **L'ordre est le piège** : voir § 2 et § 4. Tout code déployé fonctionne **avant, pendant et après** le changement de données ; le nettoyage ne part qu'après.

Vocabulaire : **passation** = le changement de données (`creator` des 261 prend l'identifiant du propriétaire, marqueur posé) ; **amorçage** = la copie des ingrédients de base à un compte ; **rattrapage** = l'amorçage d'un compte actif qui n'a pas encore le sien (le compte vide, un compte activé quand l'interrupteur était coupé) ; **forme F0** = la base d'aujourd'hui (261 ingrédients sans créateur) ; **forme F1** = la base après la passation (aucun sans créateur) ; **R1** = les sorties du back qui fonctionnent sur F0 et F1 ; **R2** = la sortie de nettoyage, qui ne fonctionne que sur F1.

## 2. Pourquoi cet ordre, et la règle d'amorçage transitoire

### 2.1 Le piège

La visibilité d'aujourd'hui est « `creator` = moi, **ou** sans créateur et pas adopté par moi » (`IngredientVisibility`). Trois conséquences dictent tout :

- passer les 261 au propriétaire **avant** que le compte vide et tout compte actif aient leurs copies, et ils ne voient plus **rien** ;
- déployer le code de nettoyage (visibilité « `creator` = moi » seulement) **avant** la passation, et le propriétaire ne voit plus rien ;
- amorcer par « les communs » avant la passation puis « les ingrédients du propriétaire » après suppose que le code sache lire **les deux**, au même endroit, sans bascule à coordonner.

### 2.2 La règle d'amorçage (une seule, valable avant, pendant et après)

> Les ingrédients à copier à un compte sont ceux qui sont **marqués « de base »** et dont le créateur est **le propriétaire du catalogue ou vide**. En rattrapage, on y ajoute les ingrédients du catalogue (créateur propriétaire ou vide) que les lignes de ce compte référencent.

Avant la passation (F0), seuls les 151 anciens communs classés « de base » portent le marqueur, et leur créateur est vide : la règle donne ces 151. Après (F1), elle donne les ingrédients de base du propriétaire (151, plus ceux qu'il marquera). Le « ou vide » est écrit **à un seul endroit** (un fragment de requête du catalogue, qui remplace `isCommonSql` pour cet usage) et disparaît dans ING-014. Un marqueur posé sur l'ingrédient d'un autre compte est **inerte** : la règle exige le créateur propriétaire ou vide.

Conséquence voulue : avant que le classement soit posé (marqueur partout à `FALSE`), l'amorçage ne copie **rien** et ne marque le compte comme amorcé que s'il a reçu quelque chose (un compte non amorcé reste non amorcé, donc continue de voir les communs partagés en F0 et sera servi par le rattrapage). Aucune fenêtre n'est dangereuse.

### 2.3 Amorcé ou non, et idempotence

Un compte est **amorcé** quand il a au moins un alias `adoption` (`IngredientAliasRepository::isAdopter`). L'amorçage d'un compte déjà amorcé ne fait rien (réactiver un compte, rejouer le rattrapage : aucune écriture, y compris les versions). Un compte qui aurait supprimé **toutes** ses copies redevient non amorcé : un rattrapage ou une réactivation le sert de nouveau (cas rare, voulu). `--complete` (console seulement) complète aussi un compte déjà amorcé ; on ne l'emploie que sur demande explicite pour un compte, jamais sur tous.

### 2.4 Le marqueur « de base » : qui le pose, ce qu'il copie

- **Un booléen sur l'ingrédient** (`is_base`, défaut `FALSE`, migration additive que l'ancien code ignore). Il dit « ceci part aux nouveaux comptes » et n'a de sens que chez le propriétaire du catalogue.
- **Seul le propriétaire du catalogue le pose** : `POST` et `PUT` d'un ingrédient avec `isBase` différent de la valeur actuelle, par tout autre compte (utilisateur ordinaire, administrateur qui n'est pas le propriétaire) : **`403` `INGREDIENT_BASE_FORBIDDEN`**, rien n'est écrit. Recommandation : refuser plutôt qu'ignorer (un champ ignoré en silence ferait croire à un réglage qui n'existe pas ; `403` est réservé à l'autorisation, ADR 0007 § 11 à 13). Le champ est **facultatif** : absent, le marqueur ne change pas (un ancien client ne le remet jamais à zéro). Le contrôle est dans le handler (métier), pas seulement dans le controller.
- **Défense en profondeur** : l'amorçage ne lit que les ingrédients dont le créateur est le propriétaire (ou vide, avant la passation) ; un marqueur posé sur l'ingrédient d'un autre compte, par un défaut ou un accès direct à la base, est **inerte**. Toute copie (amorçage, copie de recette, adoption, copie en masse) naît avec le marqueur à `FALSE` : les copies reçues n'en portent jamais.
- **Ce que l'amorçage copie** : les ingrédients de base **avec leurs conversions propres** ; les conversions générales, les unités et les rayons restent partagés. Les 110 « spécifiques » restent chez le propriétaire.
- **La donnée initiale** est le fichier validé [`base-ingredients.tsv`](base-ingredients.tsv) (261 lignes, 151 de base) : la commande de passation le pose, de façon idempotente, et vérifie 151 vrais et 110 faux avant et après.
- **Livré par ING-011 ([PR #242](tickets/ING-011-marqueur-de-base.md))** : le propriétaire du catalogue est résolu par le port `Shared` `IngredientCatalogOwner` (le compte de `User::PROTECTED_EMAIL`, ou de `INGREDIENT_CATALOG_OWNER_EMAIL` en développement seulement ; `null` s'il n'existe pas, et alors personne ne pose le marqueur). Les réponses montrent `isBase` au seul propriétaire, `canSetBase` seulement sur un ingrédient **à lui** (le handler accepte aussi, jusqu'à ING-014, un ancien commun du propriétaire : le réglage n'apparaît dans l'écran qu'après la passation).

### 2.5 Livré par ING-008 ([PR #244](tickets/ING-008-amorcage-des-comptes.md))

- **L'événement.** `Shared\Domain\Event\UserActivatedEvent(userId)`, dispatché **après** l'enregistrement du compte, dans la transaction du bus, par `VerifyEmailHandler` (compte `PENDING`), `ResetPasswordHandler` (compte `PENDING`) et `ChangeUserStatusHandler` (vers `ACTIVE` depuis un autre statut). Un compte actif qui le reste, un compte désactivé qui confirme son adresse, l'inscription publique (le compte naît `PENDING`) et tout refus n'émettent rien. `UserActivationDispatchesEventTest` échoue si un gestionnaire de `src/User/Application` appelle `verifyEmail(`, `changeStatus(`, `setStatus(`, `User::create(`, `registerFromIdentity(` ou cite `ExternalIdentityResolver` (AUTH-006) sans contenir `new UserActivatedEvent(`. **La connexion avec Google (AUTH-006) doit dispatcher l'événement** dans ses deux cas qui activent (création d'un compte actif, liaison d'un compte en attente).
- **L'interrupteur.** `Shared\Application\Interface\IngredientSeedingFeature` (variable `INGREDIENT_SEEDING_ENABLED`, `%env(bool:default::…)%` : coupée quand elle est absente, vide ou invalide). L'écouteur `SeedIngredientsOnUserActivatedListener` (synchrone, donc dans la transaction de l'activation) ne dispatche `SeedUserIngredientsCommand(userId, 'activation')` que s'il est allumé ; coupé, il ne pose aucune requête. L'échec de l'amorçage fait échouer l'activation (D6) : `500`, `{error:{message}}` sans donnée personnelle, rien n'est écrit, le lien de confirmation n'est pas consommé, la personne réessaie.
- **La source, écrite une fois** (`IngredientVisibility::isCatalogSql` / `isBaseCatalogSql`, lues par `SeedingSourceReader`, le même code pour l'écriture et pour le rapport) : créateur = propriétaire **ou vide**, et `is_base` ; plus, quand le compte a des données, les ingrédients du catalogue que ses recettes, ses listes et ses choix du drive (port `Shared` `DriveIngredientReferences::referencedSlugs`) référencent. Précisions décidées par ING-008 : (1) **aucun ingrédient « de base » dans le catalogue** (classement pas encore posé) ou **pas de propriétaire** : rien n'est écrit **même si les lignes du compte référencent des ingrédients**, le compte reste non amorcé et un avertissement est journalisé (copier les seuls ingrédients référencés le rendrait « amorcé » avec une partie du catalogue, et il ne serait jamais servi pour le reste) ; (2) un ingrédient du compte déjà né de la source (`adopted_from`) et strictement conforme est **désigné** par l'alias au lieu d'être doublé ; (3) un slug déjà pris prend `-2`, `-3`… (`IngredientSlugPolicy`).
- **Le moteur** est celui d'ING-003 (`IngredientAdoptionBulkRepository` : cinq méthodes ajoutées, `lockSeeding`, `accountExists`, `isSeeded` — même définition que `IngredientAliasRepository::isAdopter`, comparées par un test —, `ingredientsToSeed`, `copyCatalogIngredients` ; les anciennes ne changent pas), avec les mêmes empreintes avant/après, le même repointage (listes envoyées comprises, `acceptSent` vrai par défaut pour un compte qui s'active, versions +1), **29 requêtes constantes** quel que soit le nombre d'ingrédients (budget `amorcer-compte`) ; la confirmation d'adresse en coûte 33 (`confirmer-adresse-amorcage`), l'activation par l'administrateur 32 (`activer-compte-amorcage`), interrupteur coupé elles gardent leur budget d'avant (4 et 4). Aucun événement, aucune statistique, le compte n'est ni chargé ni enregistré.
- **Le rattrapage** `php bin/console repas:ingredients:seed` : à blanc par défaut, `--check` (la requête de santé), `--apply`, `--accept-sent`, `--user` / `--except` (référence courte ou identifiant ; `--user` peut viser un compte en attente ou désactivé), `--source` (défaut `rattrapage-AAAA-MM-JJ`), `--keep-going`, `--complete` (exige `--user`). Périmètre par défaut : comptes `ACTIF`, hors propriétaire, non amorcés. `--complete` donne à un compte déjà amorcé ce que le catalogue a de plus **et repointe** ses lignes restées sur l'origine d'une adoption qu'il a déjà (un vieil onglet) : c'est le remède de la requête de santé quand elle voit une telle ligne. Codes de sortie de `--check` : **0** tout est conforme, **1** il reste à faire (ou une anomalie bloque), **3** rien à écrire pour la sélection mais la santé est rouge (le script d'exploitation n'a alors ni sauvegarde ni écriture à faire et le dit). `--apply` ne tombe pas en échec pour une santé rouge hors de ce qu'il a servi : il l'affiche, et le script rejoue `--check` en dernier.
- **La requête de santé** (`SeedingHealthDTO`, affichée par le rapport) : (1) comptes actifs hors propriétaire sans aucun alias d'adoption, (2) comptes actifs sans aucun ingrédient à eux, (3) lignes (recettes, lignes et ingrédients de liste, choix du drive) d'un compte hors propriétaire sur un ingrédient qui n'est pas à lui — **quel que soit son statut** : un compte en attente ou désactivé qui garde des lignes sur les anciens communs la rend rouge, et c'est voulu (P2 de la passation) ; il se sert avec `--user` —, (4) les mêmes lignes pour le propriétaire. **Tant qu'il reste un ingrédient sans créateur, le propriétaire n'entre ni dans (2) ni dans (4)** (il utilise les anciens communs) ; dès qu'il n'en reste aucun, il y entre.
- **Le retour arrière des données** n'a plus de commande : celle d'ING-003 (`repas:ingredients:adopt-commons --undo=<source>`) a été retirée par ING-014 (le test `SeedingUndoByTheMigrationCommandTest` avec elle) ; la sauvegarde `avant-amorcage-ingredients` est la seule voie, sur décision humaine.

### 2.6 Livré par ING-013 ([PR #247](tickets/ING-013-simplifier-visibilite-droits-resolveur.md), R2 avec ING-014)

- **Une seule règle de visibilité** : `IngredientVisibility::visibleBy` = « `creator` = moi », appliquée par tous les chargeurs et dépôts (comparés par `IngredientVisibilityTest`). Les fragments `isCommonSql` / `isCatalogSql` / `isBaseCatalogSql` **restent** : ce ne sont pas des règles de visibilité, ils servent les commandes d'exploitation (`adopt-commons`, `seed`, `hand-over-catalog`) ; ING-014 les retire avec le « ou vide ».
- **Droits** : plus de `$viewerIsAdmin` nulle part ; `canEdit` toujours vrai (le champ reste) ; `OwnerVoter` sans passe-droit `ROLE_ADMIN` pour `INGREDIENT_OWNER` (D3) ; la fiche d'un autre est `404`, `PUT`/`DELETE` `403`.
- **Résolveur** : `IngredientSlugResolver::resolve`, alias valides seulement (un ancien slug), plus d'adoption à la volée ni d'adoptant.
- **Copie de recette** : provenance par la clé `adopted_from` sinon le slug de la source ; toute copie retient son origine (D7) et n'est jamais comptée comme une création.
- **Fusion et doublons** : plus de cas « commun » (`MergeBlock::COMMON_INGREDIENT` et `CANNOT_MERGE_COMMON_INGREDIENT` ne sont plus produits ; la valeur reste décrite dans le contrat OpenAPI pour les clients en cache, « n'est plus renvoyé »).
- **Conversions (D4)** : l'écran de l'administrateur ne montre que les conversions générales et celles de ses ingrédients ; créer ou modifier une conversion d'un ingrédient d'un autre : `404`.
- **Conversions universelles (2026-10-27)** : une conversion sans ingrédient (« kilo > gramme », « litre > millilitre » : les 13 de `conversion.json`) sert à tous les ingrédients et ne se perd pas. Aucune route ne supprime une conversion ; supprimer un ingrédient, purger un compte ou fusionner ne touchent que les lignes dont `ingredient` est un slug (`UniversalConversionsAreNeverRemovedTest`) ; `PUT /api/v1/conversions/{id}` d'une universelle ne change ni sa paire d'unités ni son ingrédient (`422 UNIVERSAL_CONVERSION_IS_FIXED`, seul le coefficient). **Cause probable de la disparition** : `UpdateIngredientHandler` retrouvait « la conversion achat -> recettes » avec un port qui renvoyait aussi la ligne universelle de la paire, puis la réécrivait à l'ingrédient édité (elle devenait propre à cet ingrédient) ; ce port ne rend plus que la conversion PROPRE. Cette ligne propre est cherchée dans les DEUX sens (`findOwnBetween`) : une conversion enregistrée à l'envers (« 1 centilitre = 0,0333 bouteille », formulaire des conversions propres ou ancien code) est mise à jour en gardant son sens (coefficient 1/N), jamais doublée d'une seconde ligne dans le sens achat -> recettes ; à deux lignes pour la même paire, celle du sens achat -> recettes passe d'abord, sinon la plus ancienne par identifiant. À l'enregistrement d'un ingrédient, l'unité des recettes doit toujours se convertir en unité d'achat sur le graphe final (coefficient facultatif quand le graphe relie déjà les deux unités). La migration `Version20261027100000` (données seulement, additive, `down` neutre) remet les 13 quand elles manquent : les deux unités doivent exister, aucune universelle ne doit déjà relier la paire dans un sens ou dans l'autre (la garde porte sur la PAIRE, jamais sur l'identifiant : une ligne ajoutée à la main à l'écran d'administration suffit), jamais d'écrasement ni de doublon. Les copies (amorçage, copie de recette, fusion) emportent les conversions PROPRES de l'original, jamais les universelles.

  **Correctif du 2026-10-27 et rapport de santé** : en production, « centilitre > millilitre x 10 » manquait encore après `Version20261027100000` (son identifiant fixe désignait une conversion détournée, `ON CONFLICT (id) DO NOTHING` l'avait ignorée). `Version20261027150000` (données seulement, additive, `down` neutre, rejouable) crée les lignes qui manquent encore sous leur identifiant fixe s'il est libre, sinon sous un identifiant dérivé et stable (`md5(identifiant fixe || ':universelle')` écrit en UUID), avec la même garde sur la PAIRE d'unités ; une conversion détournée reste une conversion propre à son ingrédient, jamais touchée (on ne devine pas ce que son propriétaire voulait). `php bin/console repas:conversions:check` (lecture seule, demande `conversions` de l'accès en lecture seule d'`repas-ops`) dit : les 13 universelles de référence présentes, manquantes ou avec un autre coefficient, les universelles en plus (« flacon > bouteille » ajoutée à la main), les conversions propres qui répètent une paire universelle (nombre par paire et coefficient, dont celles qui portent l'identifiant d'une ligne de référence : les détournées), et par compte (rang, référence courte, jamais d'adresse ni d'identifiant) les ingrédients dont l'unité des recettes ne se convertit pas en unité d'achat et les lignes de recettes dont l'unité ne se convertit pas en celle de leur ingrédient (le `convertible` de la lecture de la recette, même `ConversionGraph`). Verdict VERT, ou À REGARDER s'il manque une universelle de référence ou s'il existe un ingrédient non convertible ; code de sortie 0 dans les deux cas.
- **Conversions propres par ingrédient (2026-10-27, [amendement de l'ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md))** : le propriétaire d'un ingrédient — jamais un administrateur sur celui d'un autre — ajoute, change et retire ses conversions propres, vers n'importe quelle unité et sans limite de nombre, par `GET|POST /api/v1/ingredients/{slug}/conversions` et `PUT|DELETE …/{conversionId}` (`IngredientOwnConversions`, `CreateIngredientConversionHandler` et ses deux frères ; le slug reçu est résolu par les alias). `GET` rend `{member, common, totalItems, convertible, cookingUnitSlug, purchaseUnitSlug}` : `member` ses conversions propres, `common` les universelles qui touchent l'une de ses unités (unités des recettes et d'achat, unités de ses conversions propres), en lecture seule. **Refus** : paire déjà propre dans un sens ou dans l'autre `409 CONVERSION_ALREADY_EXISTS` ; deux unités identiques `422 CONVERSION_SAME_UNIT` ; coefficient non fini ou ≤ 0 `422` ; conversion universelle, d'un autre ingrédient ou inconnue `404` ; ingrédient d'un autre `403` (écriture) ou `404` (lecture). **Invariant** : après `PUT` ou `DELETE`, l'unité des recettes se convertit encore en unité d'achat, jugé sur le jeu hypothétique (`ConversionService::canConvertWith`) avant d'écrire, sinon `422 CONVERSION_REQUIRED_BY_INGREDIENT` et rien n'est écrit. Les unités compatibles de l'ingrédient se recalculent après chaque écriture. **Lecture de la recette** : chaque ligne de `GET /api/v1/recipes/{id}` porte `unitName`, `purchaseUnitSlug`, `purchaseUnitName` et `convertible` (graphe de l'ingrédient lu en une requête, `ConversionGraph`, la même réponse que la planification) ; une ligne `convertible: false` empêche la planification tant que la conversion manque. **Ordre** : les conversions propres passent avant les universelles dans le graphe (à longueur égale la valeur de l'ingrédient l'emporte). Prouvé par `IngredientConversionsApiFlowTest` (parcours JWT sur base réelle, copie comprise). **Ingrédients à régler** : `GET /api/v1/ingredients/to-fix` rend d'un coup les ingrédients de l'utilisateur (jamais ceux d'un autre, un administrateur compris) dont l'unité des recettes ne se convertit pas en unité d'achat : `{member: [{slug, name, departmentName, cookingUnitSlug, cookingUnitName, purchaseUnitSlug, purchaseUnitName}], totalItems}`, triés par nom puis identifiant, 200 au plus (`totalItems` dit le vrai nombre) ; deux unités identiques ne sont jamais « à régler » ; même graphe que `convertible` et que `repas:conversions:check`, deux requêtes quel que soit le nombre d'ingrédients (`IngredientToFixLoaderTest`). C'est la liste que le front affiche pour régler d'un coup les ingrédients sans conversion (la production en comptait 20 le 2026-10-27, dont 3 copies du catalogue dans chaque compte).
- **Budgets** : trois scénarios montent d'une ou deux requêtes (la provenance lit toujours les alias), deux scénarios d'adoption à la volée disparaissent ; voir la « Réalisation » du ticket.
- **Ce code ne marche que sur la base d'après la passation** (§ 7, colonne F1) : la migration-garde d'ING-014 l'impose.

### 2.7 Livré par ING-014 (R2, avec ING-013)

- **Retiré** : la migration d'adoption d'ING-003 (commande `repas:ingredients:adopt-commons`, `AdoptCommonIngredientsForUser*`, `UndoCommonIngredientsAdoptionForUser*`, `GetAdoptionReport*`, le script `adopter-ingredients.sh`, `AdoptionDocumentedSqlTest` et les tests de la commande), l'adoption unitaire `AdoptCommonIngredient*` et `Ingredient::adopt`, six méthodes du port de masse sans appelant (`isConcerned`, `commonsToAdopt`, `copyIngredients`, `adoptionsOfRun`, `repointToCommons`, `deleteAdoptionAliases`) avec leurs modèles `CommonIngredientToAdopt` et `UndoableAdoption`, `IngredientException::notCommon`, la branche « sans créateur » de `RemoveIngredientHandler`, les scénarios de budget `adopter-ingredient` et `adopter-catalogue`. **Reste** (ne pas toucher) : le moteur de masse (celui de l'amorçage), la table d'alias et son résolveur, `adopted_from`, la fusion, les doublons.
- **Domaine** : `Ingredient::create(string $creatorId, …)` et `getCreatorId(): string` ; `Ingredient::load()` lève une `LogicException` claire (slug, renvoi à ce document) sur une ligne sans créateur. La colonne `ingredient.creator` reste nullable (compatibilité avec le code précédent, ADR 0017 règle 6).
- **Garde-fou** : `NoSharedIngredientTest` échoue si un fichier de `src/` (hors `Migrations` et `DataFixture`) écrit `creator IS NULL`, `creatorId IS NULL`, `creator IS DISTINCT`, `getCreatorId() === null`, `isCommon…` ou `creatorId: null`. **Aucune exception** : la commande de passation `hand-over-catalog`, son classement, son script et le « ou vide » du catalogue (`IngredientVisibility::isCommonSql`, `isCatalogSql`) sont retirés par la seconde PR d'ING-014 (la passation est faite depuis le 2026-10-21). `SeedingSourceReader` n'a plus d'état « supposé », `AdoptionSnapshotReader` exige la liste des ingrédients surveillés, la sonde de santé compte les ingrédients sans propriétaire existant (attendu : 0) et le propriétaire y est toujours inclus.
- **Migration-garde** `Version20261024120000` (aucun changement de schéma) : en `prod`, `abortIf` s'il existe un ingrédient sans créateur (`deploy.sh` revient alors seul à la version précédente) ; ailleurs, avertissement seulement. Vérifié en production en lecture seule le 2026-10-24 : 0 ingrédient sans créateur sur 1 823.
- **Fixtures de développement** : `IngredientFixture` pose le catalogue chez le propriétaire (le compte protégé, ou `INGREDIENT_CATALOG_OWNER_EMAIL` s'il existe) et marque de base ceux du classement (147 des 256 de la fixture) ; `DemoRepasFixture` amorce les deux comptes de démonstration par `SeedUserIngredients` avant leurs recettes (qui n'utilisent que des ingrédients « de base »). Prouvé par `DevelopmentFixturesTest`.

## 3. Réponses aux points de cadrage (a) à (m)

| | Question | Réponse |
|---|---|---|
| a | Passer les 261 avant d'amorcer le compte vide et les futurs comptes ? | **Jamais.** Le rattrapage (compte vide, comptes sans amorçage) et l'interrupteur allumé sont des **préconditions** de la passation, contrôlées par la commande et rejouées dans sa transaction (§ 6) |
| b | Un code qui marche avant, pendant, après | **R1** lit les deux formes (visibilité inchangée ; amorçage à la règle § 2.2) ; **R2** (visibilité « créateur = moi ») ne sort qu'après la passation, derrière une migration-garde qui refuse de s'appliquer tant qu'un ingrédient sans créateur existe (le déploiement revient alors tout seul à R1). Matrice § 7 |
| c | Source de l'amorçage | § 2.2 : de base, créateur propriétaire ou vide |
| d | L'administrateur reçoit-il des alias ou `adopted_from` ? | **Non.** Un alias dit « pour U, l'origine X est son ingrédient Y » ; pour le propriétaire X et Y sont le même ingrédient (tautologie ; et le code efface déjà un alias dont la clé est un ingrédient existant du même compte). `adopted_from` dit « né d'une copie » : les anciens communs sont des **originaux**, comptés comme créés par lui (statistiques recalculées à la passation), pas des adoptions. Les copies des autres gardent `adopted_from` = le slug de l'ingrédient du propriétaire, qui reste vrai (même slug) |
| e | Les 12 choix du drive, 33 lignes de recette, 37 lignes de liste de l'administrateur | **Ne bougent pas** : même slug, seul `ingredient.creator` change ; aucune version (`recipe.version`, `shopping_list.version`) ne monte ; les 8 listes envoyées gardent statut et date d'envoi (contrôles § 5) |
| f | Un `UPDATE ingredient SET creator` suffit-il ? | **Oui pour les ingrédients** : les conversions propres sont rattachées par `conversion.ingredient` (le slug), sans colonne propriétaire ; les alias des autres comptes (`VALID_ALIASES` : « la copie existe et appartient au compte ») ne regardent pas l'origine ; `adopted_from` reste vrai comme provenance. **Ce qui change : le contrôle `--check` d'ING-003** (il exige que la clé d'un alias soit un commun) devient faux après la passation ; il est remplacé par l'invariant du § 5 et retiré dans ING-014. Le classement (`is_base`) se pose dans la même exécution |
| g | Fenêtre de concurrence | La passation est **une instruction** dans une transaction, ~261 lignes, quelques millisecondes. Une activation qui amorce pendant ce temps lit l'une ou l'autre forme, la règle § 2.2 les donne identiques. Une écriture d'un compte qui poserait une ligne sur un ingrédient sans créateur juste avant le commit laisserait une ligne « d'un autre » : l'invariant du § 5 la détecte, un rattrapage la repointe. On lance dans un créneau calme (la nuit), jamais un jour de release |
| h | Réutiliser `adopt-commons` / `adopter-ingredients.sh` ? | **Étendre sans toucher** : l'algorithme et le dépôt de masse d'ING-003 servent de moteur (nouveau cas d'usage `SeedUserIngredients`, nouvelle commande `repas:ingredients:seed`, à blanc par défaut, `--apply`, `--check`, empreintes avant/après, une transaction par compte, idempotent, sauvegarde par le script). La commande et le script d'ING-003 restent intacts jusqu'à ING-014 (leur retour arrière reste valable). Détail : ING-008 et ING-009 |
| i | Retour arrière des données | § 5 : une requête (documentée, testée) qui remet à vide le créateur des seuls anciens communs ; plus la sauvegarde `avant-passation-communs`. Inutile pour revenir du code R1 vers l'avant-dernière version (R1 lit les deux formes) |
| j | Interrupteur | `INGREDIENT_SEEDING_ENABLED` (`false` par défaut, listé dans `deploy/compose.yaml`, script humain `ecrire-ingredient-seeding.sh`) ; il ne commande que l'amorçage **automatique** à l'activation ; la commande de rattrapage marche interrupteur coupé |
| k | Abus et taille | § 8 |
| l | Administration des ingrédients, `canEdit`, purge, recherche, copie, fusion, doublons, drive | § 9 |
| m | Ce que le front change | § 10, ticket ING-015 |

## 4. Plan d'exécution : sorties et opérations, dans l'ordre

Qui : **dev** = un agent d'implémentation (un ticket = une PR) ; **assistant** = l'assistant principal (crée les releases, lit la production en lecture seule, prépare chaque script) ; **humain** = l'utilisateur, qui lance chaque script d'écriture (`!`), une étape à la fois, sans secret dans le chat (skill `repas-ops`, ADR 0017).

| # | Qui | Étape | Dépend de | Effet visible |
|---|---|---|---|---|
| 0 | assistant | cette PR de cadrage fusionnée | — | aucun |
| 1 | dev | **ING-011** marqueur « de base » + propriétaire du catalogue + champ d'API ; puis **ING-012** « plus de nouveau commun » (même agent ou rebaser : mêmes fichiers) | 0 | aucun, sauf : un ingrédient créé par l'administrateur reste à lui |
| 2 | dev | **ING-008** amorçage à l'activation, rattrapage, interrupteur (coupé) ; **ING-009** commande de passation, données, scripts | 1 (011 d'abord) | aucun (interrupteur coupé, commandes non lancées) |
| 3 | assistant | **R1** : une ou deux sorties du back (la migration `is_base` est additive) | 1, 2 fusionnés | aucun |
| 4 | — | un jour calme sans autre sortie du back | 3 | — |
| 5 | assistant | **relevés en lecture seule** (§ 5 et § 11) : photographie, comptes, classement, comptes à amorcer | 4 | — |
| 6 | humain | **à blanc** de la passation (`passer-communs-a-l-administrateur.sh` sans argument), lu avec l'assistant | 5 | aucun |
| 7 | humain | **classement + amorçage** : `… --appliquer --jusqu-a-l-amorcage` (sauvegarde `avant-passation-communs`, phase 1 : les 261 reçoivent le marqueur d'après le fichier ; phase 2 : tout compte actif non amorcé est amorcé, le compte vide) | 6 | le compte vide voit ses ingrédients |
| 8 | assistant | contrôles § 5 (« avant ») ; à l'écran, par l'humain, le compte vide | 7 | — |
| 9 | humain | **allumer l'interrupteur** (`ecrire-ingredient-seeding.sh`) ; l'assistant vérifie la variable dans le conteneur | 8 | aucun |
| 10 | humain + assistant | **essai de bout en bout** : un compte d'essai (adresse de l'humain), inscription, confirmation, ses ~150 ingrédients, ses conversions ; suppression du compte par l'administrateur (purge) | 9 | un compte jetable |
| 11 | humain | **la passation** : `… --appliquer` (nouveau à blanc, sauvegarde `avant-passation-communs`, phases 1 à 4 rejouées, vérification) | 10 | l'administrateur voit **les mêmes** ingrédients qu'avant ; plus aucun sans créateur |
| 12 | assistant | contrôles § 5 (« après ») ; à l'écran : administrateur, un compte migré, le compte vide, le compte d'essai | 11 | — |
| 13 | dev | **ING-013** (visibilité, droits, résolveur, copie, fusion, doublons, conversions) puis **ING-014** (code mort, garde-fou, fixtures, migration-garde) : PR ouvertes dès que R1 est en production, **fusionnées seulement après l'étape 12** | 12 | aucun, sauf D3 (l'administrateur ne modifie plus les ingrédients des autres) et D4 |
| 14 | assistant | **R2** : la sortie de nettoyage (ING-013 et ING-014 fusionnés), **un autre jour que la passation** | 13 | aucun |
| 15 | dev | **ING-015** front : interrupteur « de base » et pastille ; développé en parallèle de 2 à 3 (le contrat est figé à la fusion d'ING-011), livrable dès qu'ING-011 est en production (l'interrupteur n'apparaît que pour le propriétaire, sur un ingrédient à lui : donc après la passation), essai de bout en bout après l'étape 12 | 11 | l'administrateur peut marquer ses ingrédients |
| 16 | dev | **ING-010** documentation, recette, exploitation : au fil des tickets, clos après 15 | — | aucun |

**Délais.** Au plus court : jour J = R1 ; J+1 = étapes 5 à 12 (passation) ; J+2 = R2 (étape 14). On peut séparer les étapes 7–10 (J+1) de la passation 11 (J+2) et décaler R2 à J+3 pour observer ; on ne fait **jamais** la passation le jour d'une sortie du back (règle d'ING-003). Une inscription ou une activation ordinaire pendant l'un de ces jours est sans danger (§ 2.2).

**En parallèle.** ING-011 et ING-012 touchent `CreateIngredientHandler` : même agent. ING-013 et ING-014 se préparent pendant 2 à 11 (rebaser sur `main` après chaque sortie) mais restent des PR non fusionnées. ING-015 (front) et ING-007 (front, en cours) sont indépendants : ING-007 n'appelle que des routes déjà en production et tolère un `blockedBy` inconnu. Deux agents du back en même temps se marchent sur `migrations/` (seul ING-011 en écrit une), `tests/Repas/WriteBudget/budgets.php` et `docs/api/openapi.json` : rebaser avant chaque PR.

**Ce que lance l'assistant sur la production** : les relevés en lecture seule, les `GET` de santé, la création des releases (ADR 0017, amendement du 2026-10-20). **Ce que lance l'humain** : les trois scripts d'écriture (`passer-communs-a-l-administrateur.sh`, `amorcer-ingredients.sh`, `ecrire-ingredient-seeding.sh`) ; le script de passation fait lui-même la sauvegarde. Un agent de code ne lance rien sur le serveur.

## 5. Les contrôles SQL

Lecture seule. Le propriétaire se trouve sans adresse : `SELECT id FROM "user" WHERE roles::jsonb @> '["ROLE_ADMIN"]'::jsonb;` (attendu : **une** ligne) ; on passe l'identifiant en variable (`-v owner=…`), jamais dans un fichier du dépôt. Ces blocs sont ceux qu'exécutera `CatalogHandoverDocumentedSqlTest` (ING-009) sur la base de test.

**Avant** (étapes 5, 8 et 11) :

<!-- passation-controles-avant-sql -->
```sql
-- psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v owner=<identifiant du propriétaire> -f -
-- 1. Le propriétaire : une ligne, ACTIVE, administrateur ; avant la passation il n'a ni alias ni ingrédient hérité des communs
SELECT u.status, (u.roles::jsonb @> '["ROLE_ADMIN"]'::jsonb) AS administrateur,
       (SELECT count(*) FROM ingredient i WHERE i.creator = u.id) AS ingredients,
       (SELECT count(*) FROM ingredient_alias a WHERE a.user_id = u.id) AS alias
  FROM "user" u WHERE u.id = :'owner';

-- 2. Les anciens communs : nombre (261), classement (de_base : 0 avant la phase 1, 151 après), sommes de contrôle
--    attendues : somme_des_slugs = a43e401aaf59b6e65bd1f433f7347c7b, somme_des_slugs_de_base = 3776a0ad5d1fbc427f9431656c14b7e2 (après la phase 1)
SELECT count(*) AS communs,
       count(*) FILTER (WHERE is_base) AS de_base,
       count(*) FILTER (WHERE adopted_from IS NOT NULL) AS avec_origine,
       count(*) FILTER (WHERE slug ~ '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') AS au_format_d_un_compte,
       md5(string_agg(slug, ',' ORDER BY slug COLLATE "C")) AS somme_des_slugs,
       md5(string_agg(slug, ',' ORDER BY slug COLLATE "C") FILTER (WHERE is_base)) AS somme_des_slugs_de_base,
       (SELECT count(*) FROM conversion v JOIN ingredient c ON c.slug = v.ingredient WHERE c.creator IS NULL) AS conversions_propres
  FROM ingredient WHERE creator IS NULL;

-- 3. Lignes d'un AUTRE compte que le propriétaire qui référencent encore un ingrédient sans créateur (attendu avant la passation : 0 partout)
SELECT 'recettes' AS t, count(*) FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS NULL AND r.author <> :'owner'
UNION ALL SELECT 'lignes de liste', count(*) FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS NULL AND l.owner <> :'owner'
UNION ALL SELECT 'ingredients de liste', count(*) FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS NULL AND l.owner <> :'owner'
UNION ALL SELECT 'choix du drive', count(*) FROM drive_choice c JOIN ingredient i ON i.slug = c.ingredient_slug
 WHERE i.creator IS NULL AND c.user_id <> :'owner';

-- 4. Chaque compte : statut, propriétaire ou non, ingrédients, alias d'adoption, ingrédients de base (sans identifiant ni adresse)
SELECT u.status, (u.id = :'owner') AS proprietaire,
       (SELECT count(*) FROM ingredient i WHERE i.creator = u.id) AS ingredients,
       (SELECT count(*) FROM ingredient_alias a WHERE a.user_id = u.id AND a.kind = 'adoption') AS alias_adoption,
       (SELECT count(*) FROM ingredient i WHERE i.creator = u.id AND i.is_base) AS de_base
  FROM "user" u ORDER BY proprietaire DESC, u.status, ingredients DESC;

-- 5. Santé, hors propriétaire : comptes actifs sans aucun ingrédient, comptes actifs sans amorçage (attendu avant la passation : 0 et 0)
SELECT (SELECT count(*) FROM "user" u WHERE u.status = 'ACTIVE' AND u.id <> :'owner' AND NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.creator = u.id)) AS actifs_sans_ingredient,
       (SELECT count(*) FROM "user" u WHERE u.status = 'ACTIVE' AND u.id <> :'owner'
           AND NOT EXISTS (SELECT 1 FROM ingredient_alias a WHERE a.user_id = u.id AND a.kind = 'adoption')) AS actifs_sans_amorcage;
```

**La photographie** (avant et après, à comparer chiffre pour chiffre) est celle de [`migration.md`](migration.md) § 13 étape 1, plus les sommes de listes et de recettes du bloc suivant.

**Après** (étape 12, puis à chaque sortie et chaque semaine tant que le chantier n'est pas clos) :

<!-- passation-controles-apres-sql -->
```sql
-- psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v owner=<identifiant du propriétaire> -f -
-- 1. Plus aucun ingrédient sans créateur (attendu : 0)
SELECT count(*) AS sans_createur FROM ingredient WHERE creator IS NULL;

-- 2. Les anciens communs sont chez le propriétaire, identiques à avant (ils se reconnaissent à leur slug sans identifiant de compte et à leur
--    origine vide) : 261, de_base 151, somme_des_slugs a43e401aaf59b6e65bd1f433f7347c7b, somme_des_slugs_de_base 3776a0ad5d1fbc427f9431656c14b7e2,
--    et le même nombre de conversions propres qu'avant
SELECT count(*) AS anciens_communs,
       count(*) FILTER (WHERE is_base) AS de_base,
       md5(string_agg(slug, ',' ORDER BY slug COLLATE "C")) AS somme_des_slugs,
       md5(string_agg(slug, ',' ORDER BY slug COLLATE "C") FILTER (WHERE is_base)) AS somme_des_slugs_de_base,
       (SELECT count(*) FROM conversion v JOIN ingredient c ON c.slug = v.ingredient
         WHERE c.creator = :'owner' AND c.adopted_from IS NULL AND c.slug NOT LIKE '%' || :'owner') AS conversions_propres
  FROM ingredient WHERE creator = :'owner' AND adopted_from IS NULL AND slug NOT LIKE '%' || :'owner';

-- 3. L'invariant du modèle : toute ligne désigne un ingrédient du compte qui la possède (attendu : 0 partout, tous les comptes)
SELECT 'recettes' AS t, count(*) FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS DISTINCT FROM r.author
UNION ALL SELECT 'lignes de liste', count(*) FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS DISTINCT FROM l.owner
UNION ALL SELECT 'ingredients de liste', count(*) FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS DISTINCT FROM l.owner
UNION ALL SELECT 'choix du drive', count(*) FROM drive_choice c JOIN ingredient i ON i.slug = c.ingredient_slug
 WHERE i.creator IS DISTINCT FROM c.user_id;

-- 4. Références orphelines (attendu : 0 partout, comme avant)
SELECT 'recipe_row' AS t, count(*) FROM recipe_row x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'shopping_list_row', count(*) FROM shopping_list_row x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'shopping_list_ingredient', count(*) FROM shopping_list_ingredient x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'drive_choice', count(*) FROM drive_choice x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient_slug)
UNION ALL SELECT 'conversion', count(*) FROM conversion x WHERE x.ingredient IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient);

-- 5. Rien n'a bougé ailleurs : listes envoyées, versions, nombres de lignes, à comparer au relevé d'avant (mêmes chiffres, mêmes sommes)
SELECT (SELECT count(*) FROM shopping_list WHERE status = 'SENT') AS listes_envoyees,
       (SELECT md5(string_agg(id || ':' || version || ':' || coalesce(sent_at::text, ''), ',' ORDER BY id)) FROM shopping_list) AS somme_des_listes,
       (SELECT md5(string_agg(id || ':' || version, ',' ORDER BY id)) FROM recipe) AS somme_des_recettes,
       (SELECT count(*) FROM recipe_row) AS lignes_de_recettes,
       (SELECT count(*) FROM shopping_list_row) AS lignes_de_listes,
       (SELECT count(*) FROM shopping_list_ingredient) AS ingredients_de_listes,
       (SELECT count(*) FROM drive_choice) AS choix_du_drive;

-- 6. Santé : comptes actifs sans aucun ingrédient, comptes actifs (hors propriétaire) sans amorçage (attendu : 0 et 0)
SELECT (SELECT count(*) FROM "user" u WHERE u.status = 'ACTIVE' AND NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.creator = u.id)) AS actifs_sans_ingredient,
       (SELECT count(*) FROM "user" u WHERE u.status = 'ACTIVE' AND u.id <> :'owner'
           AND NOT EXISTS (SELECT 1 FROM ingredient_alias a WHERE a.user_id = u.id AND a.kind = 'adoption')) AS actifs_sans_amorcage;
```

**Retour arrière des données** (dernier recours, décision humaine, après sauvegarde, en une transaction ; inutile pour revenir du code R1 à la version d'avant, qui lit les deux formes). Il remet « sans créateur » les seuls anciens communs : un ingrédient du propriétaire se reconnaît comme tel à son **slug sans l'identifiant d'un compte** et à son origine vide (tout ingrédient créé ou copié ensuite a pour slug le nom suivi de l'identifiant du compte). Le marqueur « de base » reste (le code d'avant l'ignore) ; les comptes amorcés depuis la passation restent valables (ce sont des copies d'ingrédients de base, la forme d'ING-003) ; le compteur « ingrédients créés » du propriétaire, que la passation avait fait monter d'autant, est ramené à ce qu'il était par la même instruction. **Testé** (`CatalogHandoverDocumentedSqlTest`, sur un jeu de type production) : après ce SQL, **toutes les tables sont identiques à ce qu'elles étaient juste avant la passation**, somme de contrôle par table, statistique comprise, et rejouer la commande rétablit l'état d'après, à l'identique.

<!-- passation-retour-arriere-sql -->
```sql
-- psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 --single-transaction -v owner=<identifiant du propriétaire> -f -
-- Une seule instruction : les anciens communs redeviennent sans créateur, et le compteur « ingrédients créés » du propriétaire (que la passation avait
-- fait monter d'autant) est ramené à ce qu'il était
WITH rendus AS (
  UPDATE ingredient SET creator = NULL
   WHERE creator = :'owner' AND adopted_from IS NULL AND slug NOT LIKE '%' || :'owner'
   RETURNING slug)
UPDATE "user" SET statistics = jsonb_set(statistics::jsonb, '{ingredients}',
         to_jsonb(greatest(coalesce((statistics::jsonb ->> 'ingredients')::int, 0) - (SELECT count(*) FROM rendus), 0)))::json
 WHERE id = :'owner';

-- Contrôle (attendu : le nombre d'anciens communs qui existent encore, 261 si aucun n'a été supprimé depuis)
SELECT count(*) AS sans_createur FROM ingredient WHERE creator IS NULL;
```

Après un retour arrière des données, l'administrateur qui crée un ingrédient avec le **code d'avant R1** recrée un commun (robinet rouvert) ; avec R1 il garde le sien. Rejouer la passation (idempotente) remet tout en ordre.

## 6. La passation, phase par phase

> **Historique (ING-014, seconde PR, 2026-10-24).** La passation est faite (2026-10-21) ; sa commande, son script, son classement et leurs tests sont retirés du code. Ce qui suit décrit ce qui a été fait.

**Livré par ING-009 (PR [#245](tickets/ING-009-passation-des-communs.md))** : commande `php bin/console repas:ingredients:hand-over-catalog`, script `.claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh`, classement `src/Repas/Infrastructure/Data/base-ingredients.json` (la copie sous `src/` du TSV validé : `BaseIngredientsDataTest` échoue si les deux divergent). Le pas à pas d'exploitation est au § 14.

Commande `php bin/console repas:ingredients:hand-over-catalog`, lancée par `passer-communs-a-l-administrateur.sh`. Sans option : **à blanc**, lecture seule (aucun `INSERT`, `UPDATE` ni `DELETE`, aucun verrou).

| Phase | Ce qu'elle fait | Transaction | Rejouable |
|---|---|---|---|
| 1. Classer | pose `is_base` sur les anciens communs d'après le fichier (151 vrais, 110 faux), un seul `UPDATE` qui n'écrit que les marqueurs qui changent ; refuse si l'ensemble des slugs sans créateur n'est pas **exactement** celui du fichier (un commun inconnu ou manquant : il faut le classer d'abord) ; n'a plus rien à classer une fois la passation faite (un marqueur que le propriétaire a changé ensuite lui appartient) | une | oui (rien à écrire = pas même un `UPDATE`) |
| 2. Amorcer | amorce chaque compte **actif** non amorcé (hors propriétaire) par `SeedUserIngredients` (le compte vide) : une transaction par compte, empreintes avant/après, listes envoyées repointées si besoin (`--accept-sent`), source `rattrapage-AAAA-MM-JJ` | une par compte | oui (un compte amorcé est ignoré) |
| 3. Contrôler | préconditions P1 à P7 ci-dessous, relues **dans** la transaction de la phase 4 | — | — |
| 4. Passer | `UPDATE ingredient SET creator = <propriétaire> WHERE creator IS NULL AND slug IN (<fichier>)` ; empreinte des anciens communs (tout sauf `creator` et `is_base`, conversions propres comprises), du contenu du propriétaire (recettes, listes, versions, listes envoyées) et de ses choix du drive, comparées avant/après ; la statistique « ingrédients créés » du propriétaire annoncée **une fois** | une, verrou consultatif `catalog-handover` (`pg_advisory_xact_lock`) | oui (rien à passer = rien d'écrit, rien d'annoncé) |
| 5. Vérifier | relit le rapport : plus rien à classer, à amorcer ni à passer, la requête de santé est verte (§ 5 « après ») | lecture | oui |

**Options** : `--apply` (écrit, une transaction par phase), `--check` (lecture seule, code de sortie 0 seulement si la passation est faite et conforme), `--stop-before-handover` (classement et amorçage seulement ; avec `--check`, « classement posé, plus rien à amorcer »), `--accept-sent` (obligatoire dès qu'une liste envoyée au drive d'un compte à amorcer serait repointée), `--source=<nom>`. **À blanc, le code de sortie dit si `--apply` avec les mêmes options écrirait (0) ou refuserait d'écrire (1)** : le script s'en sert pour s'arrêter avant la sauvegarde. Écrire est refusé hors `prod` et `dev`. La commande **s'arrête au premier échec** et ne continue jamais « pour voir ».

**Rien n'est écrit à moitié quand on sait déjà que la suite sera refusée** : `--apply` complet vérifie d'abord que les préconditions seront remplies une fois le classement posé et l'amorçage fait (le rapport projette P2 et P3, que l'amorçage lève, et juge les autres telles quelles) ; si l'une manquera (P4 : l'interrupteur est coupé, par exemple), il le dit et n'écrit **rien**, ni classement ni amorçage. `--apply --stop-before-handover` n'exige que l'absence d'anomalie bloquante et l'accord pour les listes envoyées.

**Préconditions de la phase 4** (relues dans la transaction ; si l'une manque, rien n'est écrit et le message dit laquelle) :

- **P1** le propriétaire est résolu par le port (le compte de `User::PROTECTED_EMAIL`, un seul : l'adresse est unique), `ACTIF` et administrateur ;
- **P2** aucune ligne (recette, liste, ingrédient de liste, choix du drive) d'un compte **autre que le propriétaire**, quel que soit son statut, ne référence un ingrédient sans créateur ;
- **P3** tout compte `ACTIF` autre que le propriétaire est amorcé (au moins un alias d'adoption) et a au moins un ingrédient (mêmes définitions que la requête de santé d'ING-008, comparées par un test) ;
- **P4** l'interrupteur `INGREDIENT_SEEDING_ENABLED` est **allumé** (sinon un compte activé après la passation n'aurait rien) ;
- **P5** les slugs sans créateur sont exactement ceux du fichier, aucun n'a d'origine (`adopted_from`), aucun n'a la forme d'un slug de compte (garantie du retour arrière) ;
- **P6** aucune référence orpheline (lignes de recettes et de listes, ingrédients de liste, choix du drive, conversions) ;
- **P7** (ajoutée par ING-009) aucune ligne du propriétaire ne désigne l'ingrédient personnel d'un autre compte : sinon l'invariant d'après la passation (§ 5, contrôle 3) serait rouge sans que la passation y soit pour rien.

Ce que la passation **ne fait pas** : aucun `INSERT`, aucun `DELETE` (hors l'amorçage de la phase 2, compte par compte), aucune ligne de recette, de liste ou de choix modifiée, aucune version, aucun alias ni aucune conversion pour le propriétaire ; sur les 261 ingrédients, seuls `creator` et `is_base` changent (§ 3 e). La phase 4 coûte **31 requêtes SQL** (`WriteBudget` `passation-communs`), dont **un seul `UPDATE`** d'ingrédients et celui de la statistique, quel que soit le volume du propriétaire (prouvé de 3 à 40 recettes, de 3 à 120 choix du drive : `HandOverBudgetTest`) ; le classement en coûte 3 (`classer-ingredients-de-base`). Mesuré sur un propriétaire de 12 000 lignes et 6 comptes migrés : phase 4 seule 0,2 s ; commande entière (à blanc, classement et amorçage, passation, second lancement, vérification) 0,1 s, 0,4 s, 0,3 s, 0,2 s, 0,05 s.

**Deux corrections de performance livrées avec ce ticket, dans la lecture de la source de l'amorçage** (`SeedingSourceReader`, ING-008), parce que la passation (et chaque activation de compte) la lit plusieurs fois et qu'elle devenait lente avec le volume : (1) la colonne « copie déjà présente » n'est plus une sous-requête corrélée qui relit toute la table `ingredient` pour chaque candidat mais une table dérivée jointe une fois ; (2) les trois `EXISTS` corrélés des lignes du compte sont remplacés par une sous-requête non corrélée (`IN (… UNION …)`), car leur prix, multiplié par le nombre d'ingrédients, franchissait `jit_above_cost` et déclenchait la compilation JIT de PostgreSQL (0,5 à 1 s pour une requête de quelques millisecondes, mesuré avec 1 800 ingrédients et 2 500 lignes de liste). Même résultat (les 69 tests d'ING-008 passent), 4 ms au lieu de 1 s.

## 7. Compatibilité : code × données

| Code en production | Données F0 (261 sans créateur) | Données F1 (261 au propriétaire) |
|---|---|---|
| **Avant R1** (v0.6.15 et suivantes jusqu'à R1) | normal | le propriétaire voit ses ingrédients (`creator` = lui) ; les comptes amorcés aussi ; un compte **non amorcé** ne voit plus rien ; l'administrateur qui crée un ingrédient recrée un commun (le robinet se rouvre). Revenir d'aussi loin après la passation n'a pas de raison d'être : on joue plutôt le retour arrière des données (§ 5) |
| **R1** | normal | normal |
| **R2** | **refusé** : la migration-garde (ING-014) abandonne le démarrage (`abortIf`) tant qu'un ingrédient sans créateur existe ; `deploy.sh` revient seul à la version précédente (ADR 0017 règle 5) | normal |

Règles qui en découlent : (1) R1 ne lit jamais « sans créateur » pour **décider** autre chose que la règle de visibilité d'avant, qu'il garde telle quelle ; (2) R2 est la **seule** sortie qui retire le traitement de « sans créateur », et elle sort après la passation ; (3) une sortie du back entre-temps (correction) part de `main` **sans** les PR de nettoyage, qui restent non fusionnées jusqu'à l'étape 12 ; (4) le retour arrière du **code** d'une version à la précédente reste sans danger (ADR 0017 règle 6) : aucune migration de ce chantier n'est destructive, `is_base` est une colonne ajoutée que l'ancien code ignore (valeur par défaut `FALSE`).

## 8. Abus, taille, coût

- **Taille d'un amorçage** : au plus ~150 ingrédients, ≤ 75 conversions propres, ~150 alias, soit **de l'ordre de 300 lignes** par compte (le catalogue complet de 261 faisait ~600). Une transaction, de l'ordre de 30 requêtes constantes (ING-003 : 26, 0,6 s pour un compte de 10 000 lignes). Un budget `WriteBudget` est figé pour la confirmation d'adresse et pour l'activation par l'administrateur (annoncé dans chaque PR).
- **Inscriptions publiques** : le compte naît `PENDING` et **n'est pas amorcé** : un flot d'inscriptions jamais confirmées ne crée aucune ligne d'ingrédient. Il faut en plus recevoir l'e-mail de confirmation et cliquer ; l'inscription est limitée à 10 par heure et par adresse IP (`registration`), la confirmation à 20 par heure (`email_verification`). Au pire, 10 comptes confirmés par heure et par IP ≈ 3 000 lignes : de l'ordre du négligeable, et coupable à l'interrupteur.
- **Activation répétée** : idempotente (§ 2.3) ; passer `ACTIF` → `DÉSACTIVÉ` → `ACTIF` n'écrit rien la seconde fois.
- **Interrupteur coupé** : l'activation réussit sans amorçage ; le compte reste non amorcé. En F0 il continue de voir les communs partagés ; en F1 il ne verrait rien : c'est pourquoi la passation exige l'interrupteur allumé (P4), et pourquoi un interrupteur coupé après la passation se traite en urgence uniquement, suivi d'un rattrapage (`repas:ingredients:seed`) dès qu'on le rallume.
- **Échec de l'amorçage à l'activation** : l'activation échoue avec lui (même transaction, décision D6) : la personne voit une erreur à la confirmation et réessaie ; rien n'est à moitié fait. Une défaillance systématique se coupe par l'interrupteur.

## 9. Impact sur l'existant (l)

> **Livré par ING-013** (PR #247) : toutes les lignes marquées ING-013 sont en vigueur dans le code ; la colonne « Après » est l'état du code, pas une cible.

| Sujet | Aujourd'hui | Après (ticket) |
|---|---|---|
| Création d'un ingrédient par l'administrateur | un commun (`creator` vide) | un ingrédient à lui, comme tout le monde (ING-012, **R1**, livré) ; l'API ne change pas |
| Modifier, supprimer un ingrédient | `OwnerVoter` : un administrateur passe toujours | seul le créateur ; plus de passe-droit (ING-013, **R2**, D3) |
| Fiche d'un ingrédient d'un autre | un administrateur la lit | `404` pour tous (ING-013) |
| `canEdit` (liste du rayon, fiche, recherche) | « créateur = moi, ou administrateur et commun » | « créateur = moi », donc toujours vrai pour ce qui est visible ; le champ reste dans le contrat **et dans le front** (`v-if="ingredient.canEdit"` inchangé : toujours vrai, et il protège un compte pas encore amorcé d'un lien qui répondrait `403`) ; son retrait du contrat est hors chantier |
| Visibilité (liste, recherche, choix pour une recette, candidats du rapprochement) | « créateur = moi, ou sans créateur et non adopté » (`IngredientVisibility`) | « créateur = moi » (ING-013) |
| Résolveur d'écriture | alias valides + adoption à la volée pour un « adoptant » | alias valides seulement (ING-013) |
| `Recipe::assertIngredientIsAvailable` | « sans créateur, ou à l'auteur » | « à l'auteur » (ING-013) |
| Copie de recette (ING-004) | étape 0 : provenance par `adopted_from` ou slug **d'un commun** ; adoption à la volée | étape 0 : clé d'alias = `adopted_from` sinon slug de l'ingrédient source ; la copie retient cette origine (D7) ; plus de branche « commun » (ING-013) |
| Fusion (ING-005) | `422 CANNOT_MERGE_COMMON_INGREDIENT`, `blockedBy: COMMON_INGREDIENT` | disparaissent ; l'administrateur fusionne les siens (ING-013) ; le front tolère déjà un `blockedBy` inconnu |
| Doublons (ING-006) | un commun n'est jamais proposé | sans objet ; « deux adoptions d'origines différentes » reste ; le catalogue de 261 ne donne aucune paire (test existant) |
| Recherche (ADR 0019) | même visibilité | même visibilité simplifiée |
| Drive (`drive_choice`) | slug d'ingrédient | inchangé : aucune table ni route du drive n'est touchée (le port `Shared` `DriveIngredientReferences` gagne `referencedSlugs`, ING-008) |
| Suppression de compte (ADR 0021) | alias, paires écartées, copies supprimés | inchangé, plus les marqueurs et copies amorcés ; **le propriétaire est protégé** (ni désactivé, ni supprimé) : ses 261 ingrédients ne partent jamais avec un compte ; `UserOwnedDataCoverageTest` : `ingredient.creator` y est déjà |
| Statistiques « ingrédients créés » | les communs n'y sont pas | le propriétaire compte ses 261 (recalcul à la passation) ; un amorçage reste « pas une création » |
| Écran des conversions de l'administrateur | toutes les conversions de tous les comptes (≈ +450 lignes depuis ING-003) | les conversions générales et celles de **ses** ingrédients (ING-013, D4) |
| Création d'une conversion par l'administrateur | sur n'importe quel slug | sur un ingrédient **à lui** ou générale (ING-013) |
| `IngredientSlugWritesAreResolvedTest` | exige un résolveur à chaque écriture à slug | inchangé |

## 10. Ce que le front change (m)

Ticket [ING-015](tickets/ING-015-front-ingredient-de-base.md). Liste précise :

1. `IngredientEditorView.vue` : interrupteur **« Ingrédient de base »** (aide : « Copié aux nouveaux comptes. ») **visible seulement si `canSetBase`** (champ de la fiche calculé par le back : le front ne devine pas qui est le propriétaire) ; envoyé dans le `PUT` ; absent du formulaire de création.
2. `DepartmentView.vue` : pastille **« de base »** sur les lignes dont `isBase` est vrai (donc seulement chez le propriétaire) ; le `v-if="ingredient.canEdit"` du lien de modification **reste** (toujours vrai pour ce qui est visible ; le retirer avant que tout compte soit amorcé afficherait un lien qui répond `403`).
3. `src/api/schema.d.ts` régénéré (`npm run api:update`) ; champs ajoutés tolérés absents (un ancien back ne les envoie pas) ; **jamais `null` imposé** dans les requêtes.
4. Doubles de test, faux back des e2e (`e2e/`), `src/__tests__/helpers.ts` : retirer l'hypothèse « un ingrédient commun n'est pas modifiable par un utilisateur » ; ajouter le cas administrateur.
5. Textes (`fr.ts`) : deux clés ; `voice-and-glossary.spec.ts` étendu (tutoiement, aucun mot interdit) ; aucun texte ne parle de « commun ».
6. Vérifier sans rien changer : `ConversionsAdminView.vue` (la liste est filtrée par le back, le sélecteur d'ingrédient `useIngredientOptions` montre déjà les ingrédients de l'administrateur), `IngredientSearchResults.vue`, le sélecteur de recette (`RecipeRowEditor.vue`), le parcours « fusionner » d'ING-007 (le bouton n'est plus jamais masqué pour cause de « commun »).
7. Captures lues (360 px, clair et sombre) : l'administrateur (interrupteur et pastille), un compte migré, un compte neuf amorcé.

Aucun changement pour : drive, listes de courses, recettes, copie de recette.

## 11. Inventaire : ce qui change, ce qui devient mort

Chemins relatifs à `src/` ; les symboles comptent, les numéros de ligne bougent.

**Écrit dans R1 (avant la passation)**

| Quoi | Ticket |
|---|---|
| `Ingredient::isBase`, colonne `ingredient.is_base`, champ `isBase` des DTO, `canSetBase`, `IngredientPayload::$isBase` | ING-011 |
| Port `Shared\Application\Interface\IngredientCatalogOwner` + implémentation `User` | ING-011 |
| `CreateIngredientHandler` : toujours un ingrédient personnel (`CreateIngredientCommand::$isAdmin` retiré), doublon par `IngredientRepository::existsForCreator` (slug **ou** nom normalisé du créateur, une requête ; `exists` ne change pas) | ING-012 (livré, PR #243) |
| `Shared\Domain\Event\UserActivatedEvent` + dispatch par `VerifyEmailHandler`, `ResetPasswordHandler`, `ChangeUserStatusHandler` (et par la connexion Google, AUTH-006) + test d'architecture | ING-008 |
| `SeedUserIngredients`, `repas:ingredients:seed`, écouteur `SeedIngredientsOnUserActivatedListener`, port `IngredientSeedingFeature`, `DriveIngredientReferences::referencedSlugs`, variable `INGREDIENT_SEEDING_ENABLED` (`deploy/compose.yaml`, `.env`, `deploy/.env.example`, `deploy/smoke-test.sh`) | ING-008 |
| `repas:ingredients:hand-over-catalog`, `src/Repas/Infrastructure/Data/base-ingredients.json` (le TSV de `docs/`), scripts | ING-009 |

**Mort après la passation, supprimé par ING-013 (comportement) puis ING-014 (reste)**

| Symbole / fichier | Pourquoi |
|---|---|
| `Repas\Infrastructure\Visibility\IngredientVisibility` : `isCommon`, `isCommonSql`, `slugsAdoptedBy`, le `NOT IN` | plus de commun à masquer ; reste « créateur = moi » |
| `IngredientPostgreSQLLoader` : `canEdit` (branche administrateur), `get` (passe-droit), paramètres `$viewerIsAdmin` ; `GetIngredientQuery`, `ListIngredientsByDepartmentQuery`, `SearchIngredientsQuery` : `$viewerIsAdmin` ; `IngredientLoader` | plus de cas administrateur |
| `OwnerVoter` : passe-droit `ROLE_ADMIN` pour `INGREDIENT_OWNER` | D3 |
| `Recipe::assertIngredientIsAvailable` (branche « sans créateur ») | plus de commun |
| `IngredientSlugResolver` : adoption à la volée, `SOURCE_FIRST_USE`, `resolveForUser` (devient `resolve`) ; `IngredientRepository::findCommonSlugs` ; `AliasLookup::$adopter` | plus d'adoption à la volée |
| `Command\AdoptCommonIngredient\*` (cas d'usage unitaire) | idem |
| `Ingredient::isCommon`, `IngredientCandidate::isCommon`, `MergeParty::$isCommon`, `MergeBlock::COMMON_INGREDIENT`, `IngredientException::cannotMergeCommon` / `notCommon`, branche de `IngredientMergePlanner`, `IngredientMergePostgreSQLLoader`, `MergeIngredientsHandler`, `DuplicateIngredientFinder` | plus de commun |
| `CopyRecipeHandler` : branches `getCreatorId() === null`, adoption à la volée d'un candidat commun ; `Ingredient::copyFromOriginal` (origine) | D7 |
| `IngredientMatchCandidatesPostgreSQLRepository` (communs non adoptés) | idem |
| `CreateConversionHandler` / `UpdateConversionHandler` : n'importe quel slug ; `ConversionLoader::list` : toutes les conversions | D4 |
| ING-003 : `Command\AdoptCommonIngredientsForUser\*`, `UndoCommonIngredientsAdoptionForUser\*`, `Query\GetAdoptionReport\*`, `IngredientAdoptionReportLoader` et son implémentation, `src/Command/AdoptCommonIngredientsCommand.php` (`repas:ingredients:adopt-commons`), `adopter-ingredients.sh`, leurs tests, les blocs SQL testés de `migration.md` | la migration est faite ; son `--check` et son `--undo` raisonnent sur des « communs » |
| `AdoptionSnapshotReader` : le « surveillé » `creator IS NULL` | devient « un ingrédient d'un autre compte » |
| `IngredientBuilder` : création par défaut **sans** propriétaire (77 fichiers de test l'utilisent) ; fixtures `IngredientFixture`, `Data/ingredient.json` (champ `creator`), `DemoRepasFixture` | l'ingrédient de test naît chez un utilisateur ; les fixtures posent le catalogue chez le propriétaire de démonstration et marquent les mêmes 151 |
| Docs : `analyse.md` §§ 3, 4, 6, 14 ; `migration.md` §§ 2 à 12 ; `fusion.md` règle 2 ; `rapprochement.md` § 3 étape 0 | historique : un bandeau les renvoie ici |

**Garde-fou d'architecture (ING-014)** : `NoSharedIngredientTest` échoue si `src/` (hors `Migrations`, `DataFixture` et ce test) contient `creator IS NULL`, `creatorId IS NULL`, `creator IS DISTINCT`, `getCreatorId() === null`, `isCommon`, `creatorId: null` pour un ingrédient ; si `Ingredient::create` accepte un créateur vide ; ou si l'entité Doctrine d'un ingrédient est lue avec un créateur vide sans erreur claire. Les conversions **générales** (`conversion.ingredient` vide) sont un autre sujet et restent permises. Une **migration-garde** (ING-014) abandonne si un ingrédient sans créateur existe (`APP_ENV=prod` seulement : en développement, recharger les fixtures).

**Reste, et ne doit pas être touché** : table `ingredient_alias` (`adoption`, `merge`) et son résolveur d'anciens slugs, `adopted_from`, `IngredientAliasResolver` (port `Shared`), `IngredientSlugWritesAreResolvedTest`, le moteur de masse d'ING-003 (devenu celui de l'amorçage), `IngredientNameMatcher`, la fusion, les doublons, `ingredient_distinct`.

## 12. Risques et inconnues

| # | Risque | Gravité | Réponse |
|---|---|---|---|
| S1 | Le compte vide ou un compte sans amorçage ne voit plus rien après la passation | haute | rattrapage avant (phase 2), P3, P4, atomicité de l'activation, requête de santé § 5 |
| S2 | R2 sort avant la passation : le propriétaire ne voit plus rien | haute | PR de nettoyage non fusionnées avant l'étape 12 ; migration-garde ; retour arrière automatique du code |
| S3 | Une ligne d'un compte autre que le propriétaire reste sur un ingrédient sans créateur (écriture concurrente) | moyenne | P2 dans la transaction, invariant § 5 « après », rattrapage (qui repointe) |
| S4 | Un chemin d'activation oublie l'événement (par exemple la connexion Google, AUTH-006, en cours) : compte actif sans ingrédient | haute | test d'architecture sur les gestionnaires qui activent ; requête de santé hebdomadaire ; AUTH-006 à relire à sa fusion |
| S5 | L'amorçage échoue en production et bloque des confirmations d'adresse | moyenne | transaction annulée, message clair, interrupteur coupé en urgence, rattrapage après |
| S6 | Un jeu de classement faux (un commun absent ou en trop) | moyenne | P5 : égalité exacte avec le fichier ; le classement validé par l'utilisateur est le fichier |
| S7 | Le robinet se rouvre : l'administrateur crée un commun avec un ancien code | basse | ING-012 dans R1 ; la passation est idempotente, la rejouer reprend l'ingrédient égaré (P5 le signale d'abord) |
| S8 | Les statistiques « ingrédients créés » de l'administrateur sautent à 261 puis baissent des copies de recette (D7) | basse | voulu, dit dans la PR ; recalcul par `repas:update-users-statistics` |
| S9 | Un second propriétaire (autre administrateur) crée des ingrédients « de base » | basse | `403` (D2) ; un marqueur hors propriétaire est inerte |
| S10 | Deux chantiers du back écrivent des migrations en même temps | moyenne | numéroter après la dernière de `main`, rebaser |
| I1 | Le compte vide a-t-il des lignes (listes, choix du drive) sur des communs ? | à relever | relevé 2 ci-dessous ; la règle « de base plus ce qui est référencé » le couvre dans tous les cas |
| I2 | Y a-t-il un administrateur autre que le propriétaire ? | à relever | relevé 1 ; une seule ligne attendue |
| I3 | Comptes en attente ou désactivés avec des lignes sur des communs ? | à relever | relevé 2 (P2 les compte quel que soit le statut) |

### Relevés de production demandés (lecture seule, avant l'étape 5)

Les six relevés R1 à R6 sont au § 14 (bloc exécuté par `CatalogHandoverDocumentedSqlTest`), avec les blocs « avant » du § 5. Ils ne contiennent aucune donnée personnelle. Ce qu'ils lèvent : R1 = I2 (un seul administrateur ?), R2 = I1 et I3 (le compte vide et les comptes en attente ou désactivés ont-ils des lignes sur des communs ?), R3 = l'origine des alias, R4 = les statuts, R5 = la photographie globale (à comparer après), R6 = les ingrédients que le propriétaire a déjà créés depuis R1.

## 13. L'amorçage, pas à pas (ING-008)

Lancé par l'humain, une étape par message avec l'assistant (skill `repas-ops`, `references/amorcage-ingredients.md`), avec les scripts `.claude/skills/repas-ops/references/amorcer-ingredients.sh` (rattrapage) et `ecrire-ingredient-seeding.sh` (interrupteur). **Aucun agent de code n'exécute ces scripts sur le serveur.** Ces étapes sont celles 7 à 10 du § 4 ; la phase 2 de la passation (ING-009) appelle le même cas d'usage.

1. **Avant.** R1 est en production (back avec ING-008) et `printenv INGREDIENT_SEEDING_ENABLED` dans `php` vaut `false` ; aucune autre release du back ce jour-là ; une sauvegarde récente.
2. **Le rapport à blanc**, lecture seule : `! ssh … 'bash -s' < .claude/skills/repas-ops/references/amorcer-ingredients.sh`. Lire avec l'utilisateur : le catalogue (combien « de base »), les comptes retenus (le compte vide), les collisions de slug, les listes envoyées (elles seront repointées, contenu, statut et date d'envoi inchangés), la santé. **Tant que le classement n'est pas posé (ING-009, phase 1), le rapport dit « ANOMALIE BLOQUANTE : aucun ingrédient « de base » dans le catalogue » et l'écriture est refusée** : c'est voulu, on classe d'abord.
3. **L'essai sur un compte** (facultatif) : `… 'bash -s -- --appliquer --accepter-listes-envoyees --compte=<référence>'`, puis à l'écran, par l'utilisateur, ses recettes, sa liste, ses produits du drive (mêmes noms, mêmes quantités).
4. **L'écriture** : `… 'bash -s -- --appliquer --accepter-listes-envoyees'` : rapport, **sauvegarde `avant-amorcage-ingredients`**, une ligne par compte (« copies créées… empreinte … identique »), vérification. Au premier échec le script s'arrête : le compte en cause n'a rien reçu, lire le message, relancer est sans danger.
5. **La santé** : `… 'bash -s -- --verifier'` ; attendu : `verdict : VERT`. Un compte en attente ou désactivé qui garde des lignes sur les anciens communs la rend rouge (code 3) : décider avec l'utilisateur de le servir (`--compte=<référence>`) ou de le laisser (la passation, elle, l'exigera, P2).
6. **L'interrupteur** : `! ssh … 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh` ; sortie attendue `OK : INGREDIENT_SEEDING_ENABLED=true, le conteneur php le voit et /api/v1/health répond.`
7. **L'essai de bout en bout** : un compte d'essai (l'adresse de l'utilisateur) s'inscrit, confirme l'adresse, voit ses ~150 ingrédients et leurs conversions ; l'administrateur le supprime (purge complète : ingrédients, conversions, alias). Si la confirmation échoue (`500`), couper l'interrupteur (`… 'bash -s -- false' …`), relire les journaux (`docker compose logs --tail 60 php`), rattraper ensuite avec le script.
8. **Chaque semaine** (et après chaque release du back) tant que le chantier n'est pas clos : `--verifier`. Un compte activé interrupteur coupé, ou par un chemin oublié, est vu ici.

**Retour arrière.** (a) Le code : sans danger, l'ancien code lit tout ce que l'amorçage écrit (copies = ingrédients personnels ordinaires, alias d'adoption). (b) L'interrupteur : le couper arrête l'amorçage automatique. (c) Les données d'un lancement, sur décision humaine : `adopter-ingredients.sh --appliquer --annuler=<source>` (la `source` est dans le rapport : `rattrapage-AAAA-MM-JJ` ou `activation`), sauvegarde `avant-annulation-adoption-ingredients` ; les copies restent. (d) La restauration de `avant-amorcage-ingredients` (`references/incident.md` § 3), jamais automatique.

## 14. La passation, pas à pas (ING-009)

> **Historique (ING-014, seconde PR, 2026-10-24).** Procédure exécutée le 2026-10-21 ; la commande et le script qu'elle lance n'existent plus. Pour mémoire seulement.

Lancée par l'humain avec l'assistant, **une étape par message** (skill `repas-ops`, `references/passation-ingredients.md`), avec les scripts `.claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh` (classement, amorçage, passation), `amorcer-ingredients.sh` (rattrapage, santé) et `ecrire-ingredient-seeding.sh` (interrupteur). **Aucun agent de code n'exécute ces scripts ni cette commande sur le serveur** : l'agent qui les a écrits les a essayés sur des bases jetables seulement. Ces étapes sont les étapes 5 à 13 du § 4. `ssh …` veut dire `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100`.

**Avant tout** : R1 (le back avec ING-008, ING-011, ING-012 et ING-009) est en production depuis au moins un jour et `/api/v1/health` répond ; **aucune autre release du back ce jour-là** ; la nuit ou un moment calme ; aucun envoi de liste en cours sur un téléphone ; une sauvegarde récente (celle de la nuit suffit, le script en fait une propre avant d'écrire).

**1. Les relevés R1 à R6 (l'assistant, lecture seule).** Le propriétaire se trouve sans adresse : `SELECT id FROM "user" WHERE roles::jsonb @> '["ROLE_ADMIN"]'::jsonb;` (**une** ligne ; on passe l'identifiant en variable `-v owner=…`, jamais dans un fichier du dépôt ni dans le chat). Puis le bloc ci-dessous, avec `docker compose exec -T database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v owner=<identifiant> -f -'`. À noter : ce sont les chiffres de référence, on les compare après.

<!-- passation-releves-sql -->
```sql
-- psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v owner=<identifiant du propriétaire> -f -
-- R1. Les administrateurs (attendu : 1 ligne, ACTIVE)
SELECT u.status, count(*) FROM "user" u WHERE u.roles::jsonb @> '["ROLE_ADMIN"]'::jsonb GROUP BY u.status;

-- R2. Le compte vide (ni ingrédient ni recette) : ses lignes sur des ingrédients sans créateur, par table, et son statut
SELECT u.status,
       (SELECT count(*) FROM shopping_list l WHERE l.owner = u.id) AS listes,
       (SELECT count(*) FROM shopping_list l WHERE l.owner = u.id AND l.status = 'SENT') AS listes_envoyees,
       (SELECT count(*) FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE l.owner = u.id AND i.creator IS NULL) AS lignes_de_liste,
       (SELECT count(*) FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE l.owner = u.id AND i.creator IS NULL) AS ingredients_de_liste,
       (SELECT count(*) FROM drive_choice c JOIN ingredient i ON i.slug = c.ingredient_slug WHERE c.user_id = u.id AND i.creator IS NULL) AS choix_du_drive
  FROM "user" u
 WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.creator = u.id) AND NOT EXISTS (SELECT 1 FROM recipe r WHERE r.author = u.id);

-- R3. Origine des alias depuis la migration (des adoptions « first-use » ou « copy » ont-elles eu lieu ?)
SELECT kind, source, count(*) FROM ingredient_alias GROUP BY kind, source ORDER BY 3 DESC;

-- R4. Comptes par statut (les comptes en attente ou désactivés seront amorcés à leur activation)
SELECT status, count(*) FROM "user" GROUP BY status;

-- R5. La photographie globale, à comparer après chaque écriture (mêmes nombres et mêmes sommes, sauf ce que l'écriture change : les copies du compte vide)
SELECT (SELECT count(*) FROM "user") AS comptes,
       (SELECT count(*) FROM ingredient) AS ingredients,
       (SELECT count(*) FROM ingredient WHERE creator IS NULL) AS sans_createur,
       (SELECT count(*) FROM conversion) AS conversions,
       (SELECT count(*) FROM ingredient_alias) AS alias,
       (SELECT count(*) FROM recipe_row) AS lignes_de_recettes,
       (SELECT count(*) FROM shopping_list_row) AS lignes_de_listes,
       (SELECT count(*) FROM shopping_list_ingredient) AS ingredients_de_listes,
       (SELECT count(*) FROM drive_choice) AS choix_du_drive,
       (SELECT count(*) FROM drive_choice_product) AS produits_des_choix,
       (SELECT count(*) FROM shopping_list WHERE status = 'SENT') AS listes_envoyees,
       (SELECT md5(string_agg(id || ':' || version || ':' || coalesce(sent_at::text, ''), ',' ORDER BY id)) FROM shopping_list) AS somme_des_listes,
       (SELECT md5(string_agg(id || ':' || version, ',' ORDER BY id)) FROM recipe) AS somme_des_recettes;

-- R6. Les ingrédients que le propriétaire a déjà (créés depuis R1) : leurs slugs finissent tous par son identifiant, c'est ce qui les distingue des
--     anciens communs pour le retour arrière (attendu : autant de lignes avec que de lignes en tout)
SELECT count(*) AS ingredients_du_proprietaire,
       count(*) FILTER (WHERE slug LIKE '%' || :'owner') AS avec_son_identifiant,
       count(*) FILTER (WHERE is_base) AS de_base
  FROM ingredient WHERE creator = :'owner';
```

À lire avec l'utilisateur, en mots simples : R1 une ligne (un seul administrateur, actif) ; R2 le compte vide a-t-il des listes, une liste envoyée, des choix du drive sur des anciens communs (le rapport les repointera, contenu, statut et date d'envoi ne changent pas) ; R3 la source des alias (`migration-…` : la migration du 2026-10-20 ; aucune `first-use` ni `copy` attendue) ; R4 combien de comptes en attente ou désactivés (ils ne sont pas servis maintenant, ils le seront à leur activation ; **s'ils ont des lignes sur des anciens communs, la passation est refusée par P2** : décider avec lui de les servir un par un, `amorcer-ingredients.sh --compte=<référence>`).

**2. Le rapport à blanc (l'humain, lecture seule).** `! ssh … 'bash -s' < .claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh`. Rien n'est modifié. Lire avec l'assistant : le propriétaire (actif, administrateur, un seul), le fichier (261 dont 151, sommes de contrôle `a43e401aaf59b6e65bd1f433f7347c7b` et `3776a0ad5d1fbc427f9431656c14b7e2`), « Anciens communs : 261 sans créateur », « accord avec le fichier : exact ; phase 1 : 151 marqueur(s) à écrire », les comptes retenus pour l'amorçage (**le compte vide, et lui seul**, avec ce qu'il recevrait et repointerait, **liste envoyée** comprise), la santé, les préconditions (P2 et P3 « levée par l'amorçage », P4 « il est coupé » : normal, on l'allume à l'étape 6), et les deux lignes finales (« Prêt pour le classement et l'amorçage : oui » ; « Prêt pour la passation complète : NON … il manquera encore : P4 »). Aucune « ANOMALIE BLOQUANTE » ; sinon on s'arrête et on la comprend. Avec les options de l'étape suivante, le rapport dit si elle écrirait : `… 'bash -s -- --jusqu-a-l-amorcage --accepter-listes-envoyees'` termine par « --apply, avec ces options, écrirait. ».

**3. Le classement et l'amorçage du compte vide (l'humain).** `! ssh … 'bash -s -- --appliquer --jusqu-a-l-amorcage --accepter-listes-envoyees' < …/passer-communs-a-l-administrateur.sh`. Le script refait le rapport (il s'arrête s'il dit que l'écriture serait refusée), fait la **sauvegarde `avant-passation-communs`** (dire où elle est), écrit (phase 1 : « 261 ingrédient(s) sans créateur classés : 151 « de base », 110 « spécifiques » ; 151 marqueur(s) écrit(s) » ; phase 2 : une ligne par compte amorcé, « empreinte … identique »), puis relit. Attendu en dernière ligne : « OK : classement posé et comptes amorcés. La passation n'est PAS faite ». **Au premier échec il s'arrête** : le compte en cause n'a rien reçu (transaction annulée), le classement déjà posé le reste, relancer est sans danger après lecture du message.

**4. Les contrôles « avant » (l'assistant) et l'écran (l'utilisateur).** Le bloc « avant » du § 5 : le propriétaire actif, administrateur, sans ingrédient ni alias ; 261 anciens communs, **151 de base**, sommes de contrôle du classement, 0 ligne d'un autre compte sur un ancien commun, plus aucun compte actif sans amorçage ni sans ingrédient. Puis R5 comparé à l'étape 1 : seuls `ingredients`, `alias` et `conversions` ont augmenté (les copies du compte vide), les listes et recettes sont à leurs versions **+1** pour le seul compte vide. À l'écran, par l'utilisateur : le compte vide voit ses ingrédients de base, ses listes (dont l'envoyée) et ses produits du drive sont intacts.

**5. L'interrupteur (l'humain).** `! ssh … 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh` ; attendu `OK : INGREDIENT_SEEDING_ENABLED=true, le conteneur php le voit et /api/v1/health répond.` (le site coupe quelques secondes). L'assistant vérifie la variable dans le conteneur.

**6. L'essai de bout en bout (l'humain et l'assistant).** Un compte d'essai (l'adresse de l'utilisateur) s'inscrit, confirme son adresse, voit ses ~150 ingrédients et leurs conversions ; l'administrateur le supprime (purge complète). Si la confirmation échoue (`500`), couper l'interrupteur (`… 'bash -s -- false' …`), lire `docker compose logs --tail 60 php`, rattraper ensuite par `amorcer-ingredients.sh`.

**7. Le rapport à blanc de la passation.** Même commande qu'à l'étape 2 : le compte vide n'est plus retenu, P1 à P7 sont toutes « OK » (P4 comprise), « Prêt pour la passation complète (--apply) : OUI ». Sinon le rapport dit laquelle manque.

**8. La passation (l'humain).** `! ssh … 'bash -s -- --appliquer' < …/passer-communs-a-l-administrateur.sh` (`--accepter-listes-envoyees` seulement si le rapport le demande). Le script : rapport, **nouvelle sauvegarde** `avant-passation-communs`, puis la commande (« Classement déjà posé », « Aucun compte à amorcer », puis « 261 ingrédient(s) passé(s) au propriétaire en une instruction ; … empreinte des ingrédients … et du contenu du propriétaire … identiques avant et après ; statistique … annoncée une fois »), puis la vérification : « Passation faite et conforme ». Durée : une à quelques secondes.

**9. Les contrôles « après » (l'assistant) et l'écran (l'utilisateur).** Le bloc « après » du § 5 : 0 sans créateur, 261 anciens communs chez le propriétaire (151 de base, mêmes sommes de contrôle, **même nombre de conversions propres** qu'avant), **l'invariant « toute ligne désigne un ingrédient du compte qui la possède » à 0 partout**, 0 orphelin, mêmes listes envoyées et mêmes sommes de listes et de recettes qu'avant (R5 de l'étape 4), 0 compte actif sans amorçage. À l'écran, par l'utilisateur : **l'administrateur voit les mêmes ingrédients qu'avant**, ses recettes, ses listes (dont les envoyées), son plan d'envoi et ses produits du drive ; un compte migré ; l'ancien compte vide (il ne voit plus que ses ~156 ingrédients : les « spécifiques » qu'il voyait comme partagés restent chez le propriétaire, c'est la décision D5) ; un compte d'essai neuf.

**10. Après.** `… 'bash -s -- --verifier'` (passation) et `amorcer-ingredients.sh --verifier` (santé) à chaque release du back et chaque semaine tant que le chantier n'est pas clos. **ING-013 et ING-014 ne se fusionnent qu'après l'étape 9**, R2 un autre jour que la passation.

**Revenir en arrière (dernier recours, décision de l'utilisateur, du plus léger au plus lourd).** (a) Le **code** : sans danger, R1 lit les deux formes. (b) L'**interrupteur** : le couper. (c) Les **données de la passation** : le SQL du § 5 (« Retour arrière des données »), en une transaction, après une sauvegarde ; il remet « sans créateur » les seuls anciens communs et ramène la statistique du propriétaire ; tout le reste (copies, alias, marqueurs, comptes amorcés) est valable ; rejouer la passation remet tout en ordre. (d) La **restauration** de `avant-passation-communs` (`references/incident.md` § 3 du skill), jamais automatique. Un échec de la commande ne laisse rien à moitié : la phase 4 est une transaction, la phase 2 une transaction par compte (relancer est sans danger).
