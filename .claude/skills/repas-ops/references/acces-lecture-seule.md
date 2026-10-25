# Accès SSH de l'assistant : lecture seule, imposée par le serveur

**Statut : proposé (2026-10-25), non installé.** Tant que l'utilisateur ne l'a pas installé, les règles de `SKILL.md` § 1 s'appliquent.

## Pourquoi

La règle de permission `Bash(ssh * root@64.227.124.100 *)` laisse l'assistant lancer **n'importe quelle commande en root** sur la production. Le contrôle repose sur la prudence de l'assistant et du classifieur, pas sur le serveur. Ici, c'est le serveur qui limite : la clé donnée à l'assistant ne peut lancer qu'un seul programme, qui ne connaît que des demandes de lecture.

## Ce dont l'assistant a besoin (et rien d'autre)

| Demande | Pour quoi | Ce que ça lance |
|---|---|---|
| `etat` | après un déploiement : conteneurs, versions, dernier déploiement réussi | `docker compose ps`, `BACK_TAG`/`FRONT_TAG`, `state/ok-*` |
| `journal php\|edge\|front\|database [N]` | incident, recette (une erreur 500 ?) | `docker compose logs --tail N` |
| `disque` | site lent, déploiement qui échoue | `df -h /`, `free -m` |
| `drapeau NOM` | vérifier qu'un interrupteur est allumé ou coupé (`DRIVE_ENABLED`, `INGREDIENT_SEEDING_ENABLED`, `ALEXA_ENABLED`, `APP_LATEST_VERSION`, `APP_MIN_VERSION`) | `printenv NOM` dans le conteneur, liste fermée de noms non secrets |
| `sauvegardes` | vérifier la sauvegarde de la nuit | `ls backups`, fin de `cron.log` |
| `rapport comptes\|listes\|migrations` | recette : comptes par statut, listes par statut, dernières migrations | une requête SQL fixe, base ouverte **en lecture seule** |
| `graine` | santé de l'amorçage des ingrédients | `repas:ingredients:seed --check` (lecture seule) |

Tout le reste reste à l'utilisateur, comme aujourd'hui : écrire un drapeau, rouvrir des listes, restaurer, déployer à la main (`! ssh … 'bash -s' < script`).

**Les déploiements n'ont pas besoin de l'assistant en SSH** : la CI (GitHub Actions) se connecte avec le secret `PROD_SSH_*` de l'environnement `production`, après l'approbation de l'utilisateur. L'assistant n'a besoin que de `gh release create`, `git tag` et `git push origin v*` / `android-v*`, déjà permis.

## Installer (l'utilisateur, une fois)

1. Sur le poste : `ssh-keygen -t ed25519 -N '' -C repas-lecture-seule -f ~/.ssh/repas_lecture_seule`
2. `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 "bash -s -- '$(cat ~/.ssh/repas_lecture_seule.pub)'" < .claude/skills/repas-ops/references/installer-acces-lecture-seule.sh`
   Attendu : `OK : accès en lecture seule installé…`. Le script copie le programme dans `/usr/local/sbin/repas-lecture-seule` (propriété de root) et ajoute la clé à `~deploy/.ssh/authorized_keys` avec `restrict,command="…"` : pas de terminal, pas de redirection de port, pas d'autre commande.
3. Installer l'**enveloppe locale**, hors du dépôt : `mkdir -p ~/repas-outils && install -m 755 .claude/skills/repas-ops/references/lecture.sh ~/repas-outils/lecture.sh`. Elle fixe la clé, l'hôte et les options, et refuse tout argument qui n'est pas fait de lettres, chiffres et `_` (jamais de `-`). **Pourquoi** : une règle `Bash(ssh -i … deploy@64.227.124.100 *)` laisse passer des options placées APRÈS l'hôte (`-o ProxyCommand=…`, `-o LocalCommand=…`, un autre `-i`), qu'OpenSSH lit (revue du 2026-10-25, preuve avec `ssh -G`).
4. Essai : `~/repas-outils/lecture.sh aide` (liste des demandes), puis `… etat`. Et la preuve du refus côté serveur : `ssh -i ~/.ssh/repas_lecture_seule -o BatchMode=yes -o IdentitiesOnly=yes deploy@64.227.124.100 "ls /root"` doit répondre `Demande refusée`.
5. Dans `.claude/settings.local.json` : **retirer** `Bash(ssh * root@64.227.124.100 *)` et **ajouter** `Bash(~/repas-outils/lecture.sh *)`. C'est l'utilisateur qui pose les règles, jamais l'assistant.
6. Mettre à jour dans le même commit : `SKILL.md` § 1, `docs/production.md`, `CLAUDE.md` (règle d'exploitation, ADR 0017).

## Ce que la barrière ne couvre PAS (revue du 2026-10-25)

Le serveur n'impose la lecture seule qu'à CETTE clé. Tant que d'autres clés puissantes restent lisibles par l'assistant sur le poste, rien ne l'empêche de les utiliser : `~/.ssh/id_ed25519` (root) et `~/.ssh/repas_deploy` (la clé de la CI, utilisateur `deploy` du groupe `docker`, donc équivalent root). Deux règles `allow` déjà présentes sont aussi des portes ouvertes : `Bash(docker run *)` (on monte `~/.ssh` dans un conteneur) et `Bash(bash -e -c ' *)` (exécute n'importe quoi). Pour que la barrière soit réelle, l'utilisateur choisit parmi :

- retirer ces deux règles `allow` ;
- ajouter des règles `deny` : `Read(~/.ssh/id_ed25519)`, `Read(~/.ssh/repas_deploy)`, `Bash(ssh *)` (l'enveloppe `lecture.sh` reste permise par sa propre règle, plus précise) ;
- ou protéger les deux clés par une phrase de passe (`ssh-keygen -p -f ~/.ssh/id_ed25519`) et ne jamais charger d'agent SSH dans l'environnement de l'assistant.

Aucune règle ne remplace la confiance : elles couvrent l'accident et l'injection (un texte lu dans un journal qui pousserait à lancer une commande), pas un assistant qui voudrait contourner. Le programme du serveur, lui, doit être relu à chaque `case` ajouté : le compte `deploy` étant dans le groupe `docker`, il est la seule chose qui sépare cette clé de root.

## Retirer

Supprimer la ligne `repas-lecture-seule` de `/home/deploy/.ssh/authorized_keys` (et le fichier `/usr/local/sbin/repas-lecture-seule`).

## Ce que ce n'est pas

- Les rapports SQL exposent des **comptes agrégés**, jamais d'adresse e-mail ni de jeton. Pour une autre requête, ajouter un `case` au programme (relu par l'utilisateur) plutôt qu'un accès libre à la base.
- Le journal `journal php` peut contenir des adresses e-mail (confirmation, connexion) : c'est déjà le cas de ce que l'assistant lit aujourd'hui.
