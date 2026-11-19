# 0041 — Journal des listes de courses : archive à la suppression, origine de chaque coche, issue du drive par ligne

- **Statut** : Accepted (2026-11-19, demande du propriétaire)
- **Date** : 2026-11-19
- **Liens** : [0013](0013-agregats-references-par-identifiant.md) (aucune clé étrangère), [0017](0017-deploiement-et-releases.md) (règle 6 : migration compatible), [0021](0021-gestion-des-comptes-par-l-administrateur.md) (purge d'un compte), [0023](0023-liste-envoyee-au-drive.md) (liste envoyée, DRV-745), [0035](0035-contextes-etanches-open-host-service.md) (contextes étanches), [0036](0036-types-de-liste-de-courses.md) ; `docs/listes/journal.md` (requêtes d'analyse)

## Contexte

Le propriétaire veut des traces **en base** pour analyser des comportements étranges autour des listes de courses. Deux manques, vérifiés dans le code (2026-11-19) :

- **Une liste supprimée disparaît sans trace.** `RemoveShoppingListHandler` efface la liste, ses lignes, ses ingrédients et ses repas ; il est lancé par `DELETE /api/v1/shopping-lists/{id}` **et** par `RemoveShoppingListCompletedEventListener`, qui supprime toutes les listes **terminées** du compte dès qu'il en crée une nouvelle (ADR 0036). Une liste terminée ne vit donc que jusqu'à la liste suivante.
- **Une ligne cochée ne dit pas qui l'a cochée.** `shopping_list_row.checked` est un booléen. On ne sait pas distinguer une coche **à la main** (`TickLineOnShoppingList`), une coche posée par **le passage au drive** (`POST /drive/plans/{id}/basket-lines`, DRV-745), ni une ligne que le drive a **refusée** (produit choisi que le panier n'a pas pris), qui reste décochée. L'agent connaît l'issue de chaque ligne (`ADDED`, `CHOSEN` avec ou sans `notInCart`, `UNAVAILABLE`, `SKIPPED`, `HAVE_AT_HOME`, `FAILED` avec son code d'erreur, `docs/drive/architecture.md` § 8) mais le front ne rapporte au back que les articles au panier.

## Décision

### L'archive

1. **Toute suppression d'une liste (sauf celle d'un compte) la copie d'abord dans `shopping_list_archive`**, dans la même transaction du bus : `RemoveShoppingListHandler` appelle `ShoppingListArchiveRepository::archive()` puis supprime. Une liste n'est jamais supprimée sans sa trace, ni tracée sans être supprimée. La cause est gardée (`DELETED_BY_USER` pour `DELETE /shopping-lists/{id}`, `CLEANED_AFTER_COMPLETION` pour le ménage des listes terminées).
2. **L'archive est une copie à plat, sans jointure à faire** : une ligne par liste (identifiant d'origine, propriétaire, nom, statut au moment de la suppression, type de liste et son nom, dates de création et d'envoi, version, instant et cause de l'archivage, nombre de lignes, registre des parties) et deux tableaux JSONB, `lines` (par ligne : identifiant, slug **et nom** de l'ingrédient, quantité, unité **et symbole**, `checked`, `checked_by`, `checked_at`, `drive_outcome`, `drive_reason`, `drive_attempted_at`) et `meals` (identifiant, recette **et son nom**, personnes, groupe de planification, produits posés). Les noms sont copiés : l'archive se lit après la suppression de l'ingrédient, de la recette ou du type.
3. **Elle s'écrit par une seule instruction SQL** (`INSERT … SELECT`, aucun modèle hydraté), un `INSERT` de plus à la suppression (`supprimer-liste` 8 → 9). Une liste inconnue n'écrit rien.
4. **Elle n'est jamais relue par l'application** : aucune route, aucune requête de `Repas` ne la lit ; elle se lit en SQL (`docs/listes/journal.md`).
5. **Elle part avec le compte** (ADR 0021 : « comme s'il n'avait jamais existé ») : `PurgeUserDataHandler` appelle `ShoppingListArchiveRepository::deleteByOwner()` (`supprimer-compte` 29 → 30, `UserOwnedDataCoverageTest`). La suppression d'un compte n'archive pas ses listes.
6. **Elle n'est jamais purgée par âge** (une liste pèse quelques ko) ; une purge par ancienneté serait un nouvel ADR.

### L'origine des coches et l'issue du drive, sur la ligne

7. **Cinq colonnes nullables sur `shopping_list_row`, que l'archive recopie** :
   - `checked_by` (`MANUAL` | `DRIVE`) et `checked_at` : qui a coché la ligne, et quand. Posés avec la coche, **effacés avec elle** (`ShoppingListRow::tick($at)` pose `MANUAL`, `uncheck()` les efface). Une ligne déjà cochée par le drive garde son origine si la personne la coche ensuite. **Une ligne cochée avant cette migration n'a pas d'origine (`NULL`), jamais devinée.**
   - `drive_outcome`, `drive_reason`, `drive_attempted_at` : ce que le **dernier** passage au drive a fait de la ligne. `drive_outcome` ∈ `IN_CART` (au panier, la ligne est cochée par le drive), `NOT_IN_CART` (produit choisi que le panier n'a pas pris : **refusé par le drive, la ligne reste décochée**), `UNAVAILABLE`, `SKIPPED`, `HAVE_AT_HOME`, `FAILED`. `drive_reason` est un **code** de 40 caractères au plus en majuscules (`^[A-Z][A-Z0-9_]{0,39}$`, par exemple le code de l'erreur de l'agent `BLOCKED`), **jamais un texte libre** : le rapport vient d'un site qu'on ne contrôle pas. Un nouveau passage remplace l'issue ; une ligne jamais passée au drive a ces trois colonnes vides.
   - Les colonnes du drive sont écrites **par une seule instruction SQL** (`ShoppingListRowRepository::recordDrivePassage`), **jamais par le modèle** : l'enregistrement d'une liste ne les efface pas (`ShoppingListRow::updateFromModel` ne les copie pas).
8. **Le drive rapporte ce qu'il n'a pas mis au panier.** `POST /api/v1/drive/plans/{id}/basket-lines` garde `ingredientSlugs` (les articles au panier, désormais de 0 à 1 000, **au moins une ligne dans l'un des deux corps**) et gagne `notAdded` : `[{ingredientSlug, outcome, reason?}]` (facultatif, 0 à 1 000), `outcome` ∈ `NOT_IN_CART`, `UNAVAILABLE`, `SKIPPED`, `HAVE_AT_HOME`, `FAILED`. Les lignes de `notAdded` restent **décochées** ; leur issue est gardée. Le même cas d'usage et la même instruction SQL écrivent les deux (la coche des articles au panier, avec `checked_by = DRIVE`, et l'issue de chaque ligne) : `cocher-lignes-du-panier-drive` reste à 4 requêtes. Un ingrédient présent dans les deux listes est au panier. Les slugs des deux listes sont traduits par les alias en une seule fois. Rien d'autre ne change : jamais de décochage, version inchangée, aucun `LineTickedEvent`, mêmes `403`/`404`/`409`.
9. **Rien ne s'appuie sur ces colonnes.** Aucune règle métier, aucune réponse d'API, aucun écran ne les lit : ce sont des traces. Le front les remplit en envoyant `notAdded` ; sans lui (ancien site), seules `checked_by = DRIVE` et `drive_outcome = IN_CART` des articles au panier sont écrites.
10. **Contextes étanches** (ADR 0035) : `Drive` ne connaît pas `Repas`. Il dit `NotAddedLine` / `NotAddedOutcome` (son vocabulaire), l'ACL `BasketLineCommandAdapter` les transpose en chaînes pour `BasketLineCheckCommandOHS` de `Repas`, qui les range dans `DriveLineAttempt` / `DriveLineOutcome`. Le nom de l'enseigne n'est pas gardé (le passage ne le dit pas) ; voir « Alternatives ».

### Migration

11. **Additive** (ADR 0017 règle 6, `Version20261119100000`) : cinq colonnes vides (`checked_by`, `checked_at`, `drive_outcome`, `drive_reason`, `drive_attempted_at`) et une table vide que l'entité du code précédent ne mappe pas. Aucune donnée touchée, aucun retour arrière de schéma nécessaire. `IF NOT EXISTS`, rejouable.
12. **Fusion d'ingrédients** : une ligne fusionnée qui n'est plus cochée perd `checked_by` et `checked_at` (`IngredientMergePostgreSQLRepository`). Le registre `part_shares` copié dans l'archive n'est jamais réécrit.

## Conséquences

- Positives : toute liste supprimée laisse une trace lisible seule ; on voit, sur une liste en cours comme dans l'archive, quelles lignes ont été cochées à la main, lesquelles par le drive, lesquelles le drive a refusées ou n'a pas trouvées, et à quel instant. On peut croiser avec `docs/listes/journal.md`.
- Négatives / coûts : un `INSERT` de plus par suppression de liste, un `DELETE` de plus par suppression de compte ; la table grossit sans limite (quelques ko par liste) ; l'issue du drive n'est connue que si le **site** envoie `notAdded` (changement du front) ; **la raison propre au drive** d'un refus (`NOT_IN_CART`) n'est pas connue : l'agent sait seulement que le panier n'a « rien pris » du produit (quantité obtenue inférieure à 1, DRV-644). Une raison plus fine demande un changement du protocole de l'agent (une application à publier) : à décider sur ce que le journal montre.
- Garde-fous : `ShoppingListArchiveRepositoryTest` (contenu, survie à la liste, purge du compte, une instruction), `ShoppingListRowRepositoryTest` (`recordDrivePassage` : une instruction, jamais de décochage, origine gardée, le modèle n'efface pas l'issue du drive), `ShoppingListJournalMigrationTest`, `DriveBasketLinesApiFlowTest` (parcours bout en bout), `UserOwnedDataCoverageTest`, `IngredientReferencesAreAllRepointedTest` (l'archive est déclarée copie figée), `IngredientSlugWritesAreResolvedTest` (`NotAddedLine`), budgets `supprimer-liste`, `supprimer-compte`, `cocher-lignes-du-panier-drive-avec-refus`.

## Alternatives écartées

- **Un journal d'événements (une ligne par coche, décoche, passage)** : montrerait aussi les allers-retours (cochée, décochée, recochée), mais double l'écriture sur le chemin le plus fréquent (cocher une ligne) et demande de décider quoi garder. À reprendre si l'état courant ne suffit pas à expliquer les cas étranges ; le journal par ligne de cet ADR n'empêche rien.
- **Une table par ligne archivée** : plus simple à interroger ligne à ligne, mais ne garde pas les listes vides et demande de dupliquer les informations de la liste sur chaque ligne. Le JSONB se lit avec `jsonb_array_elements` (`docs/listes/journal.md`).
- **Archiver aussi à la suppression du compte** : contredit l'ADR 0021 (la trace d'un compte supprimé est de la donnée personnelle).
- **Garder l'enseigne du passage** : le contrat de la route ne la porte pas ; l'ajouter obligerait à la nommer par le registre de sa couche (`docs/drive/architecture.md` § 13). À ajouter si le journal montre qu'une enseigne se comporte autrement.
- **Deviner l'origine des coches déjà posées** : une coche d'avant la migration sur une liste envoyée est probablement celle du drive, mais rien ne le prouve ; un `NULL` honnête vaut mieux qu'une trace fausse.
