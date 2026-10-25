#!/usr/bin/env bash
# Enveloppe LOCALE de l'accès en lecture seule au serveur (acces-lecture-seule.md) : l'assistant ne lance JAMAIS « ssh … » lui-même, il
# lance cette enveloppe, qui fixe seule la clé, l'hôte et les options. Une règle de permission du type « ssh -i … deploy@hôte * » laisserait
# passer des options placées après l'hôte (-o ProxyCommand=…, -o LocalCommand=…, -i autre clé) : ici, aucun argument ne commence par « - »
# et chacun se réduit à des lettres, chiffres et « _ ».
#
# Installée par l'utilisateur HORS du dépôt, pour que l'assistant ne puisse pas la modifier en passant :
#   mkdir -p ~/repas-outils && install -m 755 .claude/skills/repas-ops/references/lecture.sh ~/repas-outils/lecture.sh
# Règle de permission à n'avoir QUE celle-là pour le serveur :  Bash(~/repas-outils/lecture.sh *)
# Usage : ~/repas-outils/lecture.sh etat | journal php 100 | drapeau ALEXA_ENABLED | rapport comptes | aide | …
set -euo pipefail

for argument in "$@"; do
	[[ $argument =~ ^[A-Za-z0-9_]+$ ]] || {
		echo "Argument refusé (lettres, chiffres et _ seulement, jamais « - ») : $argument" >&2
		exit 2
	}
done

# -F /dev/null : aucun fichier de configuration ne peut ajouter un ProxyCommand ou une autre clé
exec ssh -F /dev/null -i "$HOME/.ssh/repas_lecture_seule" -o BatchMode=yes -o IdentitiesOnly=yes deploy@64.227.124.100 "$*"
