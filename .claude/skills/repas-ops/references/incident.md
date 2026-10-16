# Incident : le site ne répond plus ou une release s'est mal passée

Toujours commencer par **lire**, sans rien modifier.

## 1. Diagnostic (lecture seule)

```
ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'cd /opt/repas && docker compose ps && cat .env | grep -E "^(BACK|FRONT)_TAG=" && cat state/ok-back state/ok-front 2>/dev/null'
ssh … 'cd /opt/repas && docker compose logs --tail 60 php'      # aussi : edge, front, database
ssh … 'df -h / && free -m'                                          # disque ou mémoire pleins ?
curl -sk -o /dev/null -w "%{http_code}\n" https://<domaine>/api/v1/health
```

Causes fréquentes : disque plein (images : `docker image prune -af --filter until=336h`) ; mémoire (1 Go : voir `dmesg | grep -i oom`) ; certificat (`docker compose logs edge | grep -i acme`) ; nom de domaine qui ne pointe plus sur le droplet ; migration en échec (logs de `php` : le conteneur redémarre en boucle).

## 2. Remettre une version précédente (code)

`deploy.sh` le fait déjà si le contrôle de santé échoue. À la main, pour une version qui « passe » le contrôle mais se comporte mal :
`ssh deploy@64.227.124.100 'cd /opt/repas && ./deploy.sh back vX.Y.Z'` avec la version précédente (liste : `gh release list`). Une sauvegarde est faite avant, comme pour tout déploiement.

⚠ Cela remet le **code**, pas le schéma : si la version fautive a appliqué une migration, vérifier qu'elle est compatible avec le code remis (ADR 0017 règle 6).

## 3. Restaurer la base (décision humaine, jamais automatique)

Les sauvegardes sont dans `/opt/repas/backups/` (14 dernières, une avant chaque déploiement du back + la nuit). Restauration dans la base de production : coupe le site pendant l'opération.

1. Sauvegarder l'état actuel : `./backup.sh avant-restauration`.
2. Arrêter l'application : `docker compose stop php`.
3. Restaurer : `docker compose exec -T database sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner' < backups/<fichier>.dump`.
4. Remettre la version de code qui correspond à ce schéma (`./deploy.sh back vX.Y.Z`), qui redémarre `php`.

À répéter d'abord sur une base jetable locale (voir la répétition faite avant le premier déploiement : `docker compose exec -T database createdb …` puis `pg_restore`).

## 4. Revenir à l'ancienne installation (avant la bascule définitive)

Tant que `/root/repas` existe : `cd /opt/repas && docker compose down`, puis `cd /root/repas && docker compose -f compose.yaml -f compose.prod.yaml up -d` (variables : voir sa `.env`/`.env.prod.local`). Sa base est celle d'avant la reprise : les données saisies depuis sont perdues, d'où l'intérêt de la restauration du §3 si le nouveau site a déjà servi.
