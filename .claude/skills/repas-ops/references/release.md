# Publier une version

La CI fait tout : tests, image GHCR, copie de `deploy/`, `deploy.sh`, contrôle de santé, retour arrière. Il reste à décider *quoi* et *quand*.

1. **Vérifier** que `main` contient ce qu'on veut livrer, CI verte : `gh run list --branch main --limit 3`.
2. **Choisir la version** (`vX.Y.Z`, `-rc1` pour un essai) : correctif = Z, fonctionnalité = Y, rupture = X. Dernière publiée : `gh release list --limit 3`.
3. **Si la version contient une migration** : relire `migrations/Version*.php` ajoutée depuis la dernière release. Elle doit rester compatible avec le code précédent (ajout de colonne oui, suppression ou renommage non : en deux versions). Si elle appartient au chantier drive (statut `SENT`, tables `drive_*`) ou si la fonction est allumée : `references/drive.md`.
   Retour arrière et recette secrète (ADR 0033, `docs/production.md`) : **Ne pas revenir à une version du back antérieure à celle qui porte la recette secrète sans prévenir** : l'ancien code ignore la colonne `recipe.secret` et montrerait de nouveau les recettes secrètes dans « Recettes des autres » ; couper plutôt la fonction ou corriger en avant. La fonction n'a pas d'interrupteur : corriger en avant veut dire livrer une nouvelle version.
4. **Publier** (l'utilisateur décide du moment ; je peux le faire sur sa demande) :
   `gh release create vX.Y.Z --target main --generate-notes` (ajouter `--prerelease` pour un essai).
5. **Suivre** : `gh run watch` sur le run `Release`. Étapes : Contrôles → Tests → Image Docker → Déploiement. Depuis le 2026-10-24 (fin de la bêta, version 1.0.0) l'environnement `production` a de nouveau un relecteur : le job « Déploiement en production » passe en `waiting` après l'image Docker. Je crée la release moi-même (jamais un sous-agent), je suis le run (`gh run view <id> --json status,jobs`), je **préviens l'utilisateur** avec le lien du run dès qu'il attend et je n'approuve jamais : il clique « Review deployments » ; ensuite je constate (étape 6). J'attends aussi que la précédente release du site soit déployée avant la suivante. Entre le 2026-10-20 et le 2026-10-24 (bêta), le déploiement partait seul.
6. **Constater** : `gh run view` doit finir vert. Sur le serveur (lecture) : `ssh … 'cd /opt/repas && cat state/ok-back && docker compose ps'`.

Le front : même chose dans `kcharignon/repas-front` (`gh release create … -R kcharignon/repas-front`) ; il n'appelle que `./deploy.sh front vX.Y.Z`.

Un déploiement en échec revient seul à la version précédente (`deploy.sh`) : le run est rouge, le site reste sur l'ancienne version. Lire la fin du log du job « Déploiement en production », puis `references/incident.md`.
