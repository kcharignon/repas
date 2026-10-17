# DRV-502 — Déploiement : ordre, interrupteur, retour arrière

- **Dépôt** : back
- **Jalon** : M5 Intégration et livraison
- **Taille** : S
- **Dépend de** : DRV-501
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 4.5, 12 ; [ADR 0017](../../adr/0017-deploiement-et-releases.md) ; skill `.claude/skills/repas-ops/` et `docs/production.md`.

## Portée

1. Documenter dans `docs/production.md` et dans la recette de livraison du skill `repas-ops` : ordre des versions (back avec `DRIVE_ENABLED=false`, puis front, puis activation par l'humain), la variable `DRIVE_ENABLED` dans `/opt/repas/.env`, la compatibilité des migrations avec la version précédente (nouvelles tables, colonnes nullables), le retour arrière (le schéma reste) et la requête de remise à l'état `ACTIVE` des listes `SENT` en cas de retour arrière du code.
2. Vérifier `make deploy-smoke` avec la nouvelle variable.
3. Rappel : l'approbation du déploiement en production reste à l'humain ; ses écritures sur le serveur passent par des scripts qu'il lance lui-même (`!`) ; aucun secret dans le chat.

## Critères d'acceptation

Un lecteur suit la page et active/désactive la fonction sans erreur ; `make deploy-smoke` passe.
