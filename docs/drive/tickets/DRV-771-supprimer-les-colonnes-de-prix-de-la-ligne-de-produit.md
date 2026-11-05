# DRV-771 — Supprimer les colonnes de prix de la ligne de produit du compte

- **Dépôt** : back seulement
- **Jalon** : M6 Simplicité d'usage (fin de DRV-770)
- **Taille** : S
- **Dépend de** : DRV-770 **en production depuis au moins une release stable** (aucun retour arrière vers le code d'avant DRV-770 n'est plus envisagé)
- **Statut** : à faire plus tard. Ne pas lancer avant l'accord du propriétaire : c'est la seule étape qui ne se défait pas par un retour arrière du code. Effort de réalisation : `xhigh` (migration de base non additive).
- **Lire d'abord** : [ADR 0032](../../adr/0032-valeurs-propres-a-chaque-compte-pour-les-produits-du-drive.md) (amendement du 2026-11-05, point 4), [DRV-770](DRV-770-prix-commun-a-tous-les-comptes.md), ADR 0017 règle 6, `docs/production.md` (sauvegarde avant migration).

## Contexte

DRV-770 a fait du prix un fait commun de la fiche `drive_product` et n'écrit ni ne lit plus `drive_choice_product.price_cents` et `price_seen_at`. Les colonnes sont restées pour que le retour arrière du code reste possible (expand / contract). Une fois DRV-770 stable en production, on les supprime.

## Réalisation

- Migration qui supprime les deux colonnes de `drive_choice_product` ; `down` les recrée (nullables, `INTEGER` et `TIMESTAMP(0) WITHOUT TIME ZONE` comme à l'origine, voir `Version20261029100000`), sans données.
- Retirer le mapping obsolète de `ChoiceProduct` et toute mention dans le code, les tests, `docs/drive/architecture.md` et le skill `repas-drive-back`.
- Vérifier avant d'ouvrir la PR : aucune requête du dépôt ne nomme plus les colonnes (`grep -rn "price_cents\|price_seen_at" src tests` ne trouve que `drive_product` et les anciennes migrations) ; la migration passe sur une base qui a la version précédente.
- Déploiement : release du back comme les autres, **sauvegarde avant la migration** (le script de déploiement la fait), aucune variable d'environnement.

## Critères d'acceptation

1. Les colonnes n'existent plus ; `make test` et `make quality` verts ; schéma et mapping cohérents.
2. Le déploiement de la release se passe comme d'habitude (sauvegarde, contrôle de santé).
