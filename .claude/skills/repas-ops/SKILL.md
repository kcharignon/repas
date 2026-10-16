---
name: repas-ops
description: Mettre en production, publier une release, revenir en arrière, sauvegarder ou diagnostiquer le serveur de Repas (droplet DigitalOcean, Docker Compose, GHCR, bordure Caddy). À utiliser pour « déployer », « release », « rollback », « sauvegarde », « le site est tombé », la première mise en production, un retour d'urgence vers Twig, ou toute modification de `deploy/` et des workflows `release.yml`. Lit l'ADR 0017 avant d'agir.
---

# repas-ops — exploiter la production de Repas

## 0. Avant toute chose

1. Lire `docs/adr/0017-deploiement-et-releases.md` : c'est la source de vérité. En cas de désaccord avec ce skill, l'ADR gagne.
2. Lire la mémoire `project-cd-deploiement` et `feedback-devops-guidage-pas-a-pas` : l'utilisateur n'est **pas** devops. **Une seule étape par message**, le but en une phrase, ce qu'il doit voir si ça marche, puis on attend son résultat. Jamais de liste d'étapes à la suite. Définir chaque terme nouveau.
3. Aucun secret dans le chat, dans un fichier du dépôt, ni dans une image. L'utilisateur saisit lui-même les valeurs ; je fournis les commandes qui les génèrent *sur le serveur*.

## 1. Le serveur en bref

- Droplet DigitalOcean `repasdocker`, `64.227.124.100`, Ubuntu 22.04, 1 Go de RAM : **jamais de build sur le serveur**.
- Production : `/opt/repas` (compose, `Caddyfile`, `deploy.sh`, `backup.sh`, `.env`, `backups/`, `state/`), propriétaire `deploy`. L'**ancienne installation** (`/root/repas`, projet compose `repas`, base dans `docker/db/data`) reste intacte pour un retour arrière tant que le SPA n'a pas pris la racine.
- Lecture seule en SSH : autorisée (`ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 '…'`, `-o BatchMode=yes`). **Écriture : bloquée pour moi par le système** → j'écris un script dans le scratchpad et l'utilisateur le lance : `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s' < <chemin du script>`. Ne pas contourner.

## 2. Quelle recette ?

| Demande | Fichier |
|---|---|
| Première mise en production (nouveau serveur de déploiement, base de l'ancienne installation reprise) | `references/premiere-mise-en-production.md` |
| Publier une version (back ou front) | `references/release.md` |
| Ça ne marche plus : retour arrière, restauration de la base, diagnostic | `references/incident.md` |
| Le front est inutilisable : rendre Twig en urgence (dernière version qui le servait) | `references/retour-twig.md` |

## 3. Règles

- Une release = un tag `vX.Y.Z` sur un commit de `main` ; jamais de `latest`. Le workflow refuse le reste.
- Toute migration doit rester compatible avec la version précédente du code (ADR 0017 règle 6) : le retour arrière ne défait pas le schéma.
- Avant de toucher à `deploy/` : `make deploy-smoke` (FRONT_IMAGE=… pour essayer le SPA). La CI le rejoue (job `deploy-config`).
- Changer une règle d'exploitation = mettre à jour l'ADR 0017, `CLAUDE.md`, ce skill et `docs/production.md` dans le même commit.
- Twig n'existe plus dans le back (ADR 0018) ; le domaine principal sert le front, l'ancien nom `app.` est redirigé en `301`. Tout changement de nom de domaine ou de redirection passe par l'utilisateur.
- Les PR du back sont fusionnées par l'utilisateur ; celles du front, par moi (CI verte, squash).
