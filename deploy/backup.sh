#!/usr/bin/env bash
# Sauvegarde la base de production et les images envoyées par l'administrateur : ./backup.sh [étiquette]
# Appelé par deploy.sh avant chaque déploiement du back, et par cron chaque nuit (voir .claude/skills/repas-ops).
# Les 14 dernières sauvegardes de chaque sorte sont conservées dans backups/ : repas-*.dump (la base) et uploads-*.tar.gz (le volume des images
# envoyées, ADR 0039). Restauration : docs/production.md.
set -euo pipefail
cd "$(dirname "$0")"

label=${1:-}
keep=14
mkdir -p backups
chmod 700 backups
umask 077

stamp=$(date +%Y%m%d-%H%M%S)
file="backups/repas-$stamp${label:+-$label}.dump"
uploads_file="backups/uploads-$stamp${label:+-$label}.tar.gz"

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

# Les images envoyées (rayons, types de recette) : le volume `uploads` du conteneur php, en archive. Sans conteneur php en marche (premier
# déploiement) ou si la version en place n'a pas encore ce dossier (celle d'avant l'ADR 0039), il n'y a rien à garder : la base est sauvée.
if docker compose ps --status running --services 2> /dev/null | grep -qx php \
	&& docker compose exec -T php test -d /app/public/images/uploads < /dev/null; then
	docker compose exec -T php tar czf - -C /app/public/images/uploads . < /dev/null > "$uploads_file.partial"

	# Une archive illisible n'est pas une sauvegarde.
	if ! tar tzf "$uploads_file.partial" > /dev/null; then
		rm -f "$uploads_file.partial"
		echo "Sauvegarde des images illisible, abandon." >&2
		exit 1
	fi
	mv "$uploads_file.partial" "$uploads_file"
	echo "Sauvegarde des images : $uploads_file ($(du -h "$uploads_file" | cut -f1))"
else
	echo "Pas de dossier d'images envoyées à sauvegarder (aucun conteneur php, ou une version d'avant l'ADR 0039)."
fi

# Rotation : on garde les $keep plus récentes de chaque sorte.
# shellcheck disable=SC2012
{ ls -1t backups/repas-*.dump 2> /dev/null || true; } | tail -n +"$((keep + 1))" | while read -r old; do
	rm -f -- "$old"
done
# shellcheck disable=SC2012
{ ls -1t backups/uploads-*.tar.gz 2> /dev/null || true; } | tail -n +"$((keep + 1))" | while read -r old; do
	rm -f -- "$old"
done
