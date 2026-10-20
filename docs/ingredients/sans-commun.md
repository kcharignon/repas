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
- **Le retour arrière des données** n'a pas de commande propre : la commande d'ING-003 (`repas:ingredients:adopt-commons --undo=<source>`, intacte jusqu'à ING-014) défait un lancement d'amorçage par la `source` de ses alias (prouvé par `SeedingUndoByTheMigrationCommandTest`) ; sinon la sauvegarde `avant-amorcage-ingredients`.

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

**Retour arrière des données** (dernier recours, décision humaine, après sauvegarde, en une transaction ; inutile pour revenir du code R1 à la version d'avant, qui lit les deux formes). Il remet « sans créateur » les seuls anciens communs : un ingrédient du propriétaire se reconnaît comme tel à son **slug sans l'identifiant d'un compte** et à son origine vide (tout ingrédient créé ou copié ensuite a pour slug le nom suivi de l'identifiant du compte). Le marqueur « de base » reste (le code d'avant l'ignore) ; les comptes amorcés depuis la passation restent valables (ce sont des copies d'ingrédients de base, la forme d'ING-003).

<!-- passation-retour-arriere-sql -->
```sql
-- psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 --single-transaction -v owner=<identifiant du propriétaire> -f -
UPDATE ingredient SET creator = NULL
 WHERE creator = :'owner' AND adopted_from IS NULL AND slug NOT LIKE '%' || :'owner';

-- Contrôle (attendu : le nombre d'anciens communs qui existent encore, 261 si aucun n'a été supprimé depuis)
SELECT count(*) AS sans_createur FROM ingredient WHERE creator IS NULL;
```

Après un retour arrière des données, l'administrateur qui crée un ingrédient avec le **code d'avant R1** recrée un commun (robinet rouvert) ; avec R1 il garde le sien. Rejouer la passation (idempotente) remet tout en ordre.

## 6. La passation, phase par phase

Commande `php bin/console repas:ingredients:hand-over-catalog` (ING-009), lancée par `passer-communs-a-l-administrateur.sh`. Sans option : **à blanc**, lecture seule.

| Phase | Ce qu'elle fait | Transaction | Rejouable |
|---|---|---|---|
| 1. Classer | pose `is_base` sur les anciens communs d'après le fichier (151 vrais, 110 faux) ; refuse si l'ensemble des slugs sans créateur n'est pas **exactement** celui du fichier (un commun inconnu ou manquant : il faut le classer d'abord) | une | oui (même résultat) |
| 2. Amorcer | amorce chaque compte **actif** non amorcé (hors propriétaire) par `SeedUserIngredients` (le compte vide) : une transaction par compte, empreintes avant/après, listes envoyées repointées si besoin (`--accept-sent`) | une par compte | oui (un compte amorcé est ignoré) |
| 3. Contrôler | préconditions P1 à P6 ci-dessous, relues **dans** la transaction de la phase 4 | — | — |
| 4. Passer | `UPDATE ingredient SET creator = <propriétaire> WHERE creator IS NULL AND slug IN (<fichier>)` ; statistiques « ingrédients créés » du propriétaire recalculées ; empreinte des 261 (tout sauf `creator`) comparée à celle d'avant | une, verrou consultatif `catalog-handover` | oui (rien à passer = rien à faire) |
| 5. Vérifier | rejoue `--check` : § 5 « après » | lecture | oui |

`--apply --stop-before-handover` s'arrête après la phase 2 (étape 7 du plan). `--check` ne fait que lire et dit « Rien à faire : passation faite et conforme » ou ce qui reste (code de sortie 0 seulement si tout est conforme). La commande **s'arrête au premier échec** et ne continue jamais « pour voir ».

**Préconditions de la phase 4** (si l'une manque, rien n'est écrit et le message dit laquelle) :

- **P1** le propriétaire est résolu par le port, `ACTIF`, administrateur, protégé ;
- **P2** aucune ligne (recette, liste, ingrédient de liste, choix du drive) d'un compte **autre que le propriétaire** ne référence un ingrédient sans créateur ;
- **P3** tout compte `ACTIF` autre que le propriétaire est amorcé (au moins un alias d'adoption) et a au moins un ingrédient ;
- **P4** l'interrupteur `INGREDIENT_SEEDING_ENABLED` est **allumé** (sinon un compte activé après la passation n'aurait rien) ;
- **P5** les slugs sans créateur sont exactement ceux du fichier, aucun n'a d'origine (`adopted_from`), aucun n'a la forme d'un slug de compte (garantie du retour arrière) ;
- **P6** aucune référence orpheline.

Ce que la passation **ne fait pas** : aucun `INSERT`, aucun `DELETE` (hors l'amorçage de la phase 2, compte par compte), aucune ligne de recette, de liste ou de choix modifiée, aucune version, aucun alias ni aucune conversion pour le propriétaire ; sur les 261 ingrédients, seuls `creator` et `is_base` changent (§ 3 e).

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

Les blocs « avant » du § 5 suffisent, avec en plus, sans aucune donnée personnelle :

```sql
-- R1. Les administrateurs (attendu : 1 ligne)
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

-- R4. Comptes en attente ou désactivés (seront amorcés à leur activation)
SELECT status, count(*) FROM "user" GROUP BY status;
```

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
