# DRV-733 — Bandeaux de l'écran du drive propres à l'enseigne

- **Dépôt** : front (`android/app/`, `android/core/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-728
- **Statut** : fait (PR front #262, 2026-10-30) : `RetailerSite.hasStorePages` ; `StoreBanner` ne rend plus `SETUP`, `OPEN` ni `STORE_FIRST` sans page de magasin ; Leclerc identique ; le texte de `PLAN` garde la tournure de Leclerc (ticket à part si besoin).
- **Lire d'abord** : le rapport de DRV-728 ; `android/ARCHITECTURE.md` (« Plusieurs enseignes ») ; skill `repas-po`.

## Contexte

Les bandeaux de l'écran du drive (`StoreBanner` : « Touche « Commencer mes courses » », « Choisis ton magasin… ») sont ceux de Leclerc, où le magasin est une **page** que l'application reconnaît. Chez Auchan il n'y a aucune page de magasin : l'agent lit le magasin par `GET /journey`, la personne le choisit dans la page du site. Les bandeaux s'afficheraient sur presque toutes les pages d'Auchan.

## Portée

Pour une enseigne sans page de magasin (table `RetailerSite` : pas de `storePages`), aucun bandeau de magasin ; Leclerc strictement identique ; le comportement se déduit de la table, jamais du nom de l'enseigne ; aucun nouveau texte.

## Critères d'acceptation

Tests unitaires (enseigne fictive sans page de magasin : aucun bandeau ; Leclerc : les bandeaux d'avant) ; `make android-test` et les scénarios verts 0, 1, 1b, 19 inchangés.
