# DRV-502 — Déploiement : ordre, interrupteur, retour arrière

- **Dépôt** : back
- **Jalon** : M5 Intégration et livraison
- **Taille** : S
- **Dépend de** : DRV-501
- **Statut** : fait (PR [#150](https://github.com/kcharignon/repas/pull/150), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 4.5, 12 ; [ADR 0017](../../adr/0017-deploiement-et-releases.md) ; skill `.claude/skills/repas-ops/` et `docs/production.md`.

## Portée

1. Documenter dans `docs/production.md` et dans la recette de livraison du skill `repas-ops` : ordre des versions (back avec `DRIVE_ENABLED=false`, puis front, puis activation par l'humain), la variable `DRIVE_ENABLED` dans `/opt/repas/.env`, la compatibilité des migrations avec la version précédente (nouvelles tables, colonnes nullables), le retour arrière (le schéma reste) et la requête de remise à l'état `ACTIVE` des listes `SENT` en cas de retour arrière du code.
2. Vérifier `make deploy-smoke` avec la nouvelle variable.
3. Rappel : l'approbation du déploiement en production reste à l'humain ; ses écritures sur le serveur passent par des scripts qu'il lance lui-même (`!`) ; aucun secret dans le chat.

## Critères d'acceptation

Un lecteur suit la page et active/désactive la fonction sans erreur ; `make deploy-smoke` passe.

## Réalisation (2026-10-18, PR #150)

- `docs/production.md`, section « Envoi de la liste au drive » : variables (`DRIVE_ENABLED` ; secrets de signature de l'application, côté dépôt front), les quatre migrations additives, ordre back → front → application → stabilité → l'humain allume, contrôles avant/après en lecture seule, allumer et couper, retour arrière en trois niveaux (couper, remettre le code, restaurer), sauvegardes. Puce de « Ce que la production exige » raccourcie avec un renvoi.
- Skill `repas-ops` : `references/drive.md` (recette guidée : mise en production, coupure, retour d'une version du back, remise en état des listes `SENT`), renvois dans `SKILL.md`, `release.md`, `incident.md` ; scripts `references/ecrire-drive-enabled.sh` (`true`/`false`) et `references/rouvrir-listes-envoyees.sh` (lecture seule, puis `--appliquer`).
- `make deploy-smoke` : vert, sans changement dans `deploy/` (le contrôle « l'envoi de la liste au drive est coupé par défaut » existait déjà). Le script d'interrupteur a été essayé sur la pile du smoke test (vraie image de production).
- **Écart assumé avec l'ADR 0023 §9** : la requête `UPDATE shopping_list SET status = 'ACTIVE', sent_at = NULL WHERE status = 'SENT'` peut laisser deux listes actives à un propriétaire (l'envoi libère la place de liste active). Le script applique une règle par propriétaire : la liste envoyée la plus récente redevient `ACTIVE` s'il n'a aucune liste active, les autres repassent en `PAUSED` ; `sent_at` est effacée et `version` monte d'un cran. L'ADR n'est pas modifiée (consigne du chantier) ; la requête simple y reste valable si personne n'a de liste active à côté.
- Point découvert : `reopen` est derrière `DRIVE_ENABLED`. Fonction coupée, une liste `SENT` se termine ou se supprime mais ne se rouvre pas ; la sortie est le script de remise en état. **Amendé le 2026-10-18 (DRV-613) : `reopen` n'est plus derrière l'interrupteur ; le point est levé.**
