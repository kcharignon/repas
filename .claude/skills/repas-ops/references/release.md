# Publier une version

La CI fait tout : tests, image GHCR, copie de `deploy/`, `deploy.sh`, contrôle de santé, retour arrière. Il reste à décider *quoi* et *quand*.

1. **Vérifier** que `main` contient ce qu'on veut livrer, CI verte : `gh run list --branch main --limit 3`.
2. **Choisir la version** (`vX.Y.Z`, `-rc1` pour un essai) : correctif = Z, fonctionnalité = Y, rupture = X. Dernière publiée : `gh release list --limit 3`.
3. **Si la version contient une migration** : relire `migrations/Version*.php` ajoutée depuis la dernière release. Elle doit rester compatible avec le code précédent (ajout de colonne oui, suppression ou renommage non : en deux versions). Si elle appartient au chantier drive (statut `SENT`, tables `drive_*`) ou si la fonction est allumée : `references/drive.md`.
4. **Publier** (l'utilisateur décide du moment ; je peux le faire sur sa demande) :
   `gh release create vX.Y.Z --target main --generate-notes` (ajouter `--prerelease` pour un essai).
5. **Suivre** : `gh run watch` sur le run `Release`. Étapes : Contrôles → Tests → Image Docker → Déploiement. Depuis le 2026-10-20 (phase bêta, jusqu'à la 1.0.0) l'environnement `production` n'a plus de relecteur : le déploiement part tout seul quand la CI est verte, je crée la release moi-même (jamais un sous-agent) et j'attends que la précédente release du site soit déployée avant la suivante. Avant : bouton « Review deployments » sur la page du run.
6. **Constater** : `gh run view` doit finir vert. Sur le serveur (lecture) : `ssh … 'cd /opt/repas && cat state/ok-back && docker compose ps'`.

Le front : même chose dans `kcharignon/repas-front` (`gh release create … -R kcharignon/repas-front`) ; il n'appelle que `./deploy.sh front vX.Y.Z`.

Un déploiement en échec revient seul à la version précédente (`deploy.sh`) : le run est rouge, le site reste sur l'ancienne version. Lire la fin du log du job « Déploiement en production », puis `references/incident.md`.
