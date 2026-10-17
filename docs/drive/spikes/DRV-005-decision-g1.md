# DRV-005 — Décision G1 (2026-10-17)

**Décision : GO, avec une application Android comme véhicule de l'agent** (à la place du favori JavaScript, que Chrome Android refuse). ADR 0022 passe à `Accepted`.

## Les preuves

| Critère de G1 | Preuve | Verdict |
|---|---|---|
| L'agent s'exécute sur le téléphone | Application de test (WebView) sur Pixel 9 : le banc d'essai s'exécute dans les pages Leclerc ([DRV-011](DRV-011-rapport.md)). Chrome Android, lui, ne lance pas les favoris JavaScript ([DRV-006](DRV-006-installation-favori.md) suite). | OK avec l'application |
| Les requêtes passent l'anti-robot | PC : une quarantaine de requêtes en série sans blocage ([DRV-002](DRV-002-rapport.md) lignes 4, 6). Téléphone, mode ordinateur : recherche, ajout, quantités, lot de **30 recherches sur 30** sans blocage. | OK |
| Écriture au panier | HTTP 200 avec la requête exacte du site : en-tête `zcwb`, adresse sans ville, contexte de recherche ([DRV-007](DRV-007-requete-ajout-panier.md)) ; quantité absolue dans les deux sens (essai 3b) ; panier revenu à l'état initial après le retrait. | OK |
| Connexion et session | L'utilisateur se connecte lui-même dans l'application ; il **reste connecté** après la fermeture complète et la réouverture. | OK |
| Choix du produit : P1 (cadre natif) ou P2 (liste de l'agent) | `X-Frame-Options: DENY` sur toutes les pages, cadre refusé ([DRV-004](DRV-004-entetes.md), DRV-002 ligne 7). | **P2** |
| CGU | Sujet clos par l'utilisateur (D23). | OK |
| Qualité de la correspondance | 23 ingrédients sur 30 ont le bon produit dans les 3 premiers ; les 7 échecs sont les fruits et légumes frais (DRV-002 ligne 18). Même résultat sur le téléphone. | Acceptable (choix de l'utilisateur, mémorisation) |
| Contenu net | Déduit pour 25 produits sur 25 (libellé et prix par unité de mesure). | OK |

## Décisions prises

- **P2** : le choix du produit se fait dans la liste que l'agent affiche lui-même.
- **Véhicule** : application Android, forme **A** (compagnon natif Kotlin, dossier `android/` du dépôt front, qui prolonge `drive-agent/android-lab/`) ; la forme B (Repas empaqueté) n'a pas été éprouvée (D26).
- **Leclerc affiché en mode ordinateur** dans la WebView (le site mobile `m-courses` a d'autres routes ; D25 acceptée).
- **Transport du plan** : de Repas vers l'application par un lien `repasdrive://open?plan=…` (schéma propre à l'application, le plan reste en base64url comme le protocole v1) ; de l'application vers Repas par `https://kantin.eu/drive/retour#result=…` (origine écrite en dur). Le fragment vers une page Leclerc (D21) n'est plus nécessaire : D21 est **sans objet**.
- **Distribution** : fichier APK signé, publié en release GitHub, installé à la main ; mises à jour signalées par l'application ; Google Play (test interne) reste une option plus tard (D27). La clé de signature est créée et gardée par l'utilisateur, jamais dans le dépôt.
- **Clé de mémorisation** : par magasin (identifiants de produit non comparés entre magasins, DRV-002 ligne 15 non testée).
- **EAN** : absent des données de recherche ; la clé reste l'identifiant Leclerc (ligne 16).
- **Prix** : mémorisés avec le produit (D22).

## Ce qui reste ouvert (ne bloque pas G1)

- Adresse de la page panier (DRV-002 ligne 14) et page panier dans DRV-004 : relevées à l'intégration.
- Deux magasins différents (ligne 15), EAN sur la fiche produit (ligne 16).
- Le lien `repasdrive://` depuis la PWA installée (ligne 11) : validé au ticket DRV-314 sur un vrai téléphone.
- Ergonomie du site PC affiché sur un téléphone (connexion, finalisation de la commande) : à juger en recette (DRV-505).
- Résistance à long terme de l'anti-robot avec le mode ordinateur sur téléphone : surveillance, interrupteur `DRIVE_ENABLED`, runbook (DRV-503).
