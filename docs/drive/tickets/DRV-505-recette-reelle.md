# DRV-505 — Recette en conditions réelles et activation

- **Dépôt** : — (tâche humaine, avec un agent qui guide)
- **Jalon** : M5 Intégration et livraison
- **Taille** : S
- **Dépend de** : DRV-502, DRV-503
- **Statut** : à faire
- **Lire d'abord** : `docs/drive/recette.md` (DRV-501), `docs/drive/runbook.md` (DRV-503), skill `repas-ops`.

## Portée

L'utilisateur déroule la checklist de `recette.md` sur son téléphone Android avec son compte Leclerc et une vraie liste, sans valider de commande. Un agent le guide **une étape à la fois** (jamais d'identifiant dans le chat). Si tout est bon, l'utilisateur approuve le déploiement et active `DRIVE_ENABLED=true` sur le serveur (ADR 0017), puis le chantier passe en « usage » : ADR 0022 et 0023 `Accepted` (si ce n'est pas déjà fait), roadmap close, `CLAUDE.md` à jour.

## Critères d'acceptation

La recette est signée (date, version du favori, version de Repas) dans `docs/drive/recette.md` ; la fonction est activée en production ; le runbook est connu de l'utilisateur.
