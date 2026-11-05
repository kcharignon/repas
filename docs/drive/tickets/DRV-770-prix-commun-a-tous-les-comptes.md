# DRV-770 — Le prix d'un produit est commun à tous les comptes du magasin

- **Dépôt** : back seulement (aucun changement de l'agent, de l'application, du site ni du protocole)
- **Jalon** : M6 Simplicité d'usage (suite de DRV-613 et DRV-656)
- **Taille** : M
- **Dépend de** : DRV-656 (prix sur la ligne du compte, à défaire), DRV-613 (fiche partagée, garde du prix)
- **Statut** : à faire. Décision produit du propriétaire du 2026-11-05. Effort de réalisation : `high` (une migration de données sans schéma, une règle de sécurité de DRV-613 assouplie, ligne de produit et empreinte des choix touchées).
- **Lire d'abord** : [ADR 0032](../../adr/0032-valeurs-propres-a-chaque-compte-pour-les-produits-du-drive.md) **dont l'amendement du 2026-11-05 en fin de fichier (c'est la décision)**, [DRV-656](DRV-656-valeurs-propres-a-chaque-compte.md) (ce qu'on défait, pour le prix seulement), [`architecture.md`](../architecture.md) §§ 5.1 à 5.3, skills `/repas-dev` et `/repas-drive-back`.

## Contexte

Aujourd'hui chaque compte garde sur sa ligne de produit (`drive_choice_product.price_cents`, `price_seen_at`) le prix qu'il a lu, et l'estimation du coût prend ce prix avant celui de la fiche commune. Mais un prix au drive n'a rien de personnel (pas de tarif par compte : les avantages de fidélité sont des cagnottes). Résultat : un compte garde un prix périmé quand un autre a lu le produit plus récemment. Décision : **le prix et sa date vivent sur la fiche commune `drive_product` seule ; tout compte qui accepte le produit la met à jour ; l'estimation de tous lit ce prix.** Le libellé reste propre au compte.

## Règle de fond

- `drive_product.price_cents` / `price_seen_at` = la dernière observation de n'importe quel compte qui **accepte** le produit. Un produit refusé n'écrit jamais de prix. Bornes `ProductPrice` (0 à 1 000 000 centimes) et plafond d'écritures par compte et par jour (DRV-613) inchangés. Un prix non lu ne remplace rien.
- `ADD_PRODUCT` met le prix de la fiche à jour **dès la première acceptation** (aujourd'hui la garde `isAccepted` d'avant l'ajout l'empêche). `REFRESH_PRICE` reste réservé à un produit que le compte accepte.
- L'estimation lit le prix et sa date de la fiche, **jamais de la ligne du compte**.
- Le libellé propre au compte (`COALESCE(cp.label, p.label)`) ne change pas.
- La forme des réponses (clés JSON, protocole de l'agent, contrat OpenAPI, `docs/drive/samples/*.sample.json`) ne change pas.

## Réalisation

- **Domaine** : `ProductObservation` ne garde que le libellé (retirer `priceCents`, `priceSeenAt`, `priced()`, `repriced()`, `withoutPrice()` et ce qui n'a plus d'usage ; adapter `seen()`, `seenAgain()`, `load()`). Retirer `Choice::observePrice()` et le paramètre prix d'`observe()`. Relire les commentaires de `Choice`, `ProductObservation` et `Product::complete()` / `seePrice()` : ils décrivent l'ancien régime.
- **Écritures** (`SaveProductChoicesHandler`) : `ADD_PRODUCT` d'un produit accepté (ou qui le devient dans le même intent) avec un prix lu appelle `seePrice()`, y compris à la première acceptation (décider si c'est avant ou après `addPrimary`/`addFallback` ; le produit doit être accepté pour l'ingrédient). `REJECT_PRODUCT` n'écrit jamais de prix. `REFRESH_PRICE` n'écrit que la fiche : **il n'enregistre plus aucune préférence** (plus de `touchedChoices` pour lui, la date `updated_at` de la préférence ne bouge pas, le budget SQL baisse). Garder les contrôles de bornes sur un prix reçu même quand il ne sert pas.
- **Lecture** : `DriveEstimatePostgreSQLLoader::choices()` / `product()` : prix et date de `p.priceCents` / `p.priceSeenAt` seulement (retirer `ownPrice*`/`sharedPrice*` et la jointure de prix sur la ligne). Vérifier par recherche (`price_cents`, `priceCents`, `priceSeenAt`, `ownPrice`) qu'aucune autre lecture ne prend le prix de la ligne.
- **Écriture SQL** : `ChoicePostgreSQLRepository` n'écrit plus `price_cents` / `price_seen_at` dans la réécriture des lignes (`INSERT` et `SELECT`).
- **Entité** : `ChoiceProduct` garde le mapping des deux colonnes, marquées obsolètes avec une phrase qui renvoie à DRV-771 (elles restent en base : retour arrière du code, ADR 0017 règle 6 ; un test de cohérence schéma/mapping s'il existe doit rester vert).
- **Empreinte des choix** : `IngredientChoiceReader` (ligne qui agrège `cp.price_cents` et `cp.price_seen_at`) et `DriveChoiceFingerprint` : le prix n'est plus un attribut du choix, il sort de la ligne résumée. Vérifier que l'empreinte ne sert qu'à comparer deux instants d'une même vérification (adoption, copie) et qu'aucune valeur n'en est conservée entre deux versions.
- **Migration de données** (nouvelle `Version2026110…`, SQL pur, **aucun changement de schéma**, rejouable) : pour chaque fiche `drive_product`, si une ligne `drive_choice_product` **acceptée** porte un prix daté plus récent que celui de la fiche (ou si la fiche n'a pas de prix), recopier sur la fiche le prix et la date de la ligne la plus récente. Ne jamais effacer ni rétrograder un prix, ne toucher ni le libellé ni aucune ligne de compte. `down` : vide (la remise à niveau ne se défait pas, sans conséquence). Test sur le schéma réel dans l'esprit de `ProductObservationMigrationTest` (recopie, fiche déjà plus récente intacte, fiche sans prix, deux lignes de dates différentes, rejouabilité).
- **Budgets SQL** : le scénario `rafraichir-prix-drive` baisse (plus de réécriture des lignes) ; `make sql-budget-update` puis relire le diff de `tests/Repas/WriteBudget/budgets.php` (les autres budgets du drive ne doivent pas monter).
- **Tests à adapter ou ajouter** : `ProductObservationTest`, `ChoiceTest` (plus de prix sur la ligne), `SaveProductChoicesHandlerTest` (deux comptes : le prix lu par le second met la fiche à jour et le premier le voit ; première acceptation d'un produit connu met la fiche à jour ; un produit refusé n'écrit pas de prix ; prix hors bornes refusé ; `REFRESH_PRICE` n'enregistre aucune préférence), `ChoiceRepositoryTest`, test du loader d'estimation (prix de la fiche, plus de repli), `GetDriveEstimateApiControllerTest` / `DriveEstimateApiFlowTest` (bout en bout), `ProductObservationMigrationTest` (celui de DRV-656 reste vrai : il teste cette migration-là), `ChoiceBuilder`.
- **Documentation, dans le même commit que le code** (règle de `CLAUDE.md`) : paragraphe « Drive » de `CLAUDE.md` (« Libellé et prix d'un produit : propres à chaque compte… » devient « libellé propre au compte, prix commun du magasin »), `docs/drive/architecture.md` §§ 5.1 à 5.3 (lignes du `Produit`, de `ProductObservation`, du schéma `drive_choice_product`, de `REFRESH_PRICE`), skill `.claude/skills/repas-drive-back/SKILL.md` (puce « Libellé et prix propres à chaque compte »), `docs/adr/README.md` (ligne de l'ADR 0032 : le prix est redevenu commun), `docs/drive/README.md` si le ticket y figure ; renseigner la PR dans l'en-tête de ce ticket à la fusion.

## Pièges connus

- **Ne pas supprimer les colonnes** `price_cents` / `price_seen_at` de `drive_choice_product` ici : le code de la release précédente les lit, un retour arrière échouerait. C'est DRV-771.
- Le prix et sa date vont ensemble, toujours : jamais le prix d'une observation et la date d'une autre.
- Une ligne réécrite par le nouveau code perd son ancien prix de ligne : sans conséquence, plus rien ne le lit.
- Un produit **refusé** ne doit jamais écrire de prix sur la fiche (c'était le sens de la garde de DRV-613) : le nouveau test doit le prouver.
- `UserOwnedDataCoverageTest` : aucune table ni colonne utilisateur ne change.
- La suite complète (`make test`) doit être verte AVANT d'ouvrir la PR ; `make quality` aussi (PHPStan : la baseline ne fait que diminuer).

## Critères d'acceptation

1. Deux comptes du même magasin : A accepte un produit à 2,00 €, B l'accepte ensuite à 2,10 € ; l'estimation de A (liste suivante) affiche 2,10 € et la date de B. Test qui le prouve.
2. Un compte qui refuse un produit n'écrit aucun prix sur la fiche.
3. `REFRESH_PRICE` ne change ni la date ni le contenu d'aucune préférence.
4. Aucune lecture du code ne prend plus le prix de `drive_choice_product`.
5. La migration de données ne change que `drive_product.price_cents` / `price_seen_at`, jamais dans le sens d'un prix plus ancien.
6. Contrat OpenAPI et exemples inchangés (`make openapi-check`, `tests/Drive/Contract`), `make test` et `make quality` verts.

## Hors périmètre

- La suppression des colonnes (DRV-771).
- Une médiane ou un historique de prix (écartés dans l'amendement de l'ADR 0032).
- Le libellé, la contenance : inchangés.
