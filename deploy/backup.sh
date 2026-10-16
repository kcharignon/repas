#!/usr/bin/env bash
# Sauvegarde la base de production : ./backup.sh [étiquette]
# Appelé par deploy.sh avant chaque déploiement du back, et par cron chaque nuit (voir .claude/skills/repas-ops).
# Les 14 dernières sauvegardes sont conservées dans backups/.
set -euo pipefail
cd "$(dirname "$0")"

label=${1:-}
keep=14
mkdir -p backups
chmod 700 backups
umask 077

file="backups/repas-$(date +%Y%m%d-%H%M%S)${label:+-$label}.dump"

# Écrit dans un fichier temporaire : une sauvegarde interrompue ne remplace jamais une bonne.
docker compose exec -T database sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' < /dev/null > "$file.partial"

# Un dump vide ou illisible n'est pas une sauvegarde.
if ! docker compose exec -T database pg_restore --list < "$file.partial" > /dev/null; then
	rm -f "$file.partial"
	echo "Sauvegarde illisible, abandon." >&2
	exit 1
fi
mv "$file.partial" "$file"
echo "Sauvegarde : $file ($(du -h "$file" | cut -f1))"

# Rotation : on garde les $keep plus récentes.
# shellcheck disable=SC2012
ls -1t backups/repas-*.dump 2> /dev/null | tail -n +"$((keep + 1))" | while read -r old; do
	rm -f -- "$old"
done
