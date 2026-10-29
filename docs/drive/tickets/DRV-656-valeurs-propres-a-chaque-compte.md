# DRV-656 — Chaque compte garde son libellé et son prix du produit

- **Dépôt** : back seulement (aucun changement de l'agent, de l'application, du site ni du protocole)
- **Jalon** : M6 Simplicité d'usage (suite des fiches de produits, DRV-613 et DRV-652)
- **Taille** : M
- **Dépend de** : DRV-613 (fiche partagée « premier écrivain gagnant »), DRV-652 (même mécanisme : colonnes sur la ligne `drive_choice_product`)
- **Statut** : fait (PR back à renseigner à la fusion, 2026-10-29). Décision produit du propriétaire du 2026-10-29. Effort de réalisation : `high` (migration de données, trois loaders, contrat de lecture inchangé).
- **Lire d'abord** : [ADR 0032](../../adr/0032-valeurs-propres-a-chaque-compte-pour-les-produits-du-drive.md), [`architecture.md`](../architecture.md) §§ 5.1 à 5.3, [`DRV-652`](DRV-652-memoriser-la-contenance-en-unite-de-la-liste.md) (précédent exact), skill `/repas-drive-back`.

## Contexte

Une fiche produit du drive (`drive_product` : libellé, code-barres, contenu net, rayon, prix) est commune à tous les comptes du magasin : le premier compte qui enregistre un produit fixe sa fiche, et le prix est celui de la dernière observation. Un compte voyait donc le libellé et le prix lus par un autre compte. Décision : **chaque compte garde son libellé et son prix, ceux qu'il a lui-même observés ; la fiche partagée ne sert plus que de valeur de repli.**

## Règle de fond

- Les valeurs propres au compte vivent sur la ligne du produit dans sa préférence : `drive_choice_product.label`, `price_cents`, `price_seen_at` (nullables, effacées avec la ligne donc avec le compte, aucune colonne utilisateur de plus), comme la contenance de DRV-652.
- Un produit refusé ne garde que son libellé (jamais de prix).
- Toute lecture destinée à un compte prend la valeur de sa ligne, la fiche en repli seulement quand la ligne n'en a pas ; le prix et sa date se prennent **ensemble** du même côté.
- La forme des réponses (clés JSON, protocole de l'agent, contrat OpenAPI) ne change pas.

## Réalisation

- **Domaine** : `ProductObservation` (valeur), portée par `Choice` (`observe()`, `observePrice()`, `getObservation()`, élaguée par chaque méthode qui retire un produit, effacée par « j'en ai toujours »). `Product::validLabel()` expose la règle de libellé de la fiche (DRV-613) au libellé de la ligne.
- **Écritures** (`SaveProductChoicesHandler`) : `ADD_PRODUCT` écrit le libellé et le prix lu sur la ligne ; `REJECT_PRODUCT` le libellé ; `REFRESH_PRICE` le prix (produit accepté), sans changer la date de la préférence. La fiche partagée est créée ou complétée comme avant.
- **Lectures** : `DriveEstimatePostgreSQLLoader` (libellé et prix par paire), `DrivePlanPostgreSQLLoader` (libellé des produits acceptés et refusés, tri des refusés), `ChoicePostgreSQLLoader` (libellé, tri) : `COALESCE(cp.label, p.label)`.
- **Écriture SQL** : `ChoicePostgreSQLRepository` porte les trois colonnes dans la réécriture des lignes (neuf colonnes par ligne) ; aucune requête de plus.
- **Migration** `Version20261029100000` : colonnes additives puis **recopie unique** de la fiche vers les lignes existantes (libellé de tout produit connu, prix et date des produits acceptés qui en ont un), SQL pur, rejouable, sans écraser (décision 5 de l'ADR 0032).
- **Empreinte des choix** (`DriveChoiceFingerprint`) : voit les nouvelles colonnes.
- **Budgets SQL** : les budgets existants sont inchangés ; le scénario `rafraichir-prix-drive` (lot qui ne fait que rafraîchir des prix) est posé à 9.
- **Contrat** : `docs/api/openapi.json` et `docs/drive/samples/*.sample.json` inchangés (`make openapi-check`, `tests/Drive/Contract`).

## Pièges connus

- Une ligne **sans valeur à elle** suit encore la fiche (prix de la dernière observation d'un autre compte) : produit rangé depuis l'écran de gestion sans l'avoir lu, prix non lu par l'agent, ligne réécrite par l'ancien code. Elle se détache dès que le compte enregistre ou rafraîchit le produit.
- Le prix et sa date vont ensemble : ne jamais mélanger le prix de la ligne et la date de la fiche (ni l'inverse).
- Un retour arrière du code ne défait pas le schéma ; la réécriture des lignes par l'ancien code efface les valeurs de la préférence concernée (retour au repli).
