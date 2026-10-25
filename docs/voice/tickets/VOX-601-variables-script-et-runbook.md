# VOX-601 — Variables, script d'écriture et runbook

- **Dépôt** : back
- **Jalon** : M6
- **Taille** : S
- **Dépend de** : VOX-204
- **Statut** : fait (2026-10-25)
- **Lire d'abord** : skill `repas-ops` ; `.claude/skills/repas-ops/references/ecrire-mailer-env.sh` et `ecrire-drive-enabled.sh` (modèles) ; `docs/production.md` (« Envoi de la liste au drive »).

## Portée

1. `references/ecrire-alexa-env.sh` (lancé par l'utilisateur, `! ssh … 'bash -s' < script`) : saisit **sur le serveur** (`read -rs`, sans écho) le secret du client, l'écrit **haché** (`ALEXA_OAUTH_CLIENT_SECRET_HASH`) dans `/opt/repas/.env`, demande l'ID du skill et les URL de redirection, n'allume pas la fonction ; affiche le secret **une seule fois** pour qu'il le colle dans la console Amazon (VOX-002). Variante `bash -s -- true|false` pour `ALEXA_ENABLED` (modèle : `ecrire-drive-enabled.sh`), avec contrôle de santé après redémarrage.
2. `references/alexa.md` du skill `repas-ops` : mise en service, allumer/couper, quoi regarder dans les journaux (en lecture seule), retour arrière (couper `ALEXA_ENABLED` ; les liaisons restent).
3. `docs/production.md` : section « Commande vocale Alexa » (variables, interrupteur, ce qui est purgé avec un compte).
4. `make deploy-smoke` si `deploy/` change.

## Critères d'acceptation

- Aucun secret en clair dans le dépôt, ni dans les sorties du script hors l'affichage unique ; le script est idempotent ; coupé, la fonction répond `404`.
