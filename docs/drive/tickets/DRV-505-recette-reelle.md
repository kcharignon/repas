# DRV-505 — Recette en conditions réelles et activation

- **Dépôt** : — (tâche humaine, avec un agent qui guide)
- **Jalon** : M5 Intégration et livraison
- **Taille** : S
- **Dépend de** : DRV-502, DRV-503
- **Statut** : en cours (procédure écrite dans [`../recette.md`](../recette.md) le 2026-10-18 ; parties 3 à 8 faites avec succès par l'utilisateur sur son téléphone les 2026-10-18 et 19, parties 9, 10 et 12 à faire ; non signée)
- **Lire d'abord** : `docs/drive/recette.md` (DRV-501), `docs/drive/runbook.md` (DRV-503), skill `repas-ops`.

## Portée

L'utilisateur déroule la checklist de `recette.md` sur son téléphone Android avec son compte Leclerc et une vraie liste, sans valider de commande. Un agent le guide **une étape à la fois** (jamais d'identifiant dans le chat). Comme l'envoi n'existe pas interrupteur coupé, la recette elle-même se fait **interrupteur allumé** : après un déploiement stable du back et du front (`DRIVE_ENABLED` encore coupé), l'utilisateur approuve les déploiements, allume `DRIVE_ENABLED=true` avec le script du skill `repas-ops` (ADR 0017), déroule la recette (la famille est prévenue de ne pas s'en servir avant la fin), puis décide de le laisser allumé ou de le couper. Si tout est bon, le chantier passe en « usage » : ADR 0022 et 0023 `Accepted` (déjà fait), roadmap close, `CLAUDE.md` à jour.

## Critères d'acceptation

La recette est signée dans `docs/drive/recette.md` (date, version de l'application, de l'agent, du back et du front) ; la fonction est activée en production si l'utilisateur le décide ; le runbook est connu de l'utilisateur.

## État au 2026-10-19

Déroulée avec des applications `android-v0.1.1` à `android-v0.1.4` (la version minimale exigée est `android-v0.1.4`, [`../recette.md`](../recette.md) étape 2.1). Constats : [`../hypotheses-site-reel.md`](../hypotheses-site-reel.md) § 8 ; corrections : [`roadmap.md`](../roadmap.md) § M3c. Restent les parties 9, 10 et 12, puis le tableau de signature.
