#!/usr/bin/env bash
# Déploie une version d'un service : ./deploy.sh back|front vX.Y.Z
# Lancé sur le serveur par la CI (.github/workflows/release.yml des deux dépôts), ou à la main pour un retour arrière.
#
# 1. sauvegarde de la base (back seulement)   2. nouvelle version écrite dans .env   3. images tirées, service relancé
# 4. contrôle de santé                        5. en cas d'échec : version précédente remise en place.
# Le retour arrière remet le code, pas le schéma de la base : une migration appliquée le reste (ADR 0017).
set -euo pipefail
cd "$(dirname "$0")"

usage() {
	echo "Usage : $0 back|front vX.Y.Z" >&2
	exit 2
}

service=${1:-}
tag=${2:-}
case "$service" in
	back) var=BACK_TAG ;;
	front) var=FRONT_TAG ;;
	*) usage ;;
esac
[[ $tag =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || {
	echo "Version invalide : « $tag » (attendu : vX.Y.Z)" >&2
	exit 2
}
[[ -f .env ]] || {
	echo "Fichier .env absent dans $PWD (modèle : .env.example)" >&2
	exit 2
}

get_var() { sed -n "s/^$1=//p" .env | tail -n 1; }

set_var() {
	local tmp
	tmp=$(mktemp .env.XXXXXX)
	chmod 600 "$tmp"
	{ grep -v "^$1=" .env || true; } > "$tmp"
	echo "$1=$2" >> "$tmp"
	mv "$tmp" .env
}

# Le service front n'existe pour compose que si une version du front est demandée.
compose() {
	if [[ -n $(get_var FRONT_TAG) ]]; then
		docker compose --profile front "$@"
	else
		docker compose "$@"
	fi
}

# Répond « ok » quand le service déployé sert réellement, pas seulement quand son conteneur tourne.
healthy() {
	if [[ $service == back ]]; then
		compose exec -T edge wget -q -O - http://php/api/v1/health < /dev/null > /dev/null 2>&1
	else
		compose exec -T edge wget -q -O - http://front/ < /dev/null 2> /dev/null | grep -q '<div id="app">'
	fi
}

wait_healthy() {
	for _ in $(seq 1 30); do
		healthy && return 0
		sleep 2
	done
	return 1
}

apply() {
	compose pull --quiet --ignore-pull-failures "$@" || return 1
	compose up -d --wait --wait-timeout 240 --remove-orphans || return 1
	# La bordure relit le Caddyfile copié avec cette version, sans couper les connexions.
	compose exec -T edge caddy reload --config /etc/caddy/Caddyfile --force < /dev/null > /dev/null 2>&1 || true
}

previous=$(get_var "$var")
echo "== $service : ${previous:-aucune version} -> $tag"

if [[ $service == back ]] && docker compose ps --status running --services 2> /dev/null | grep -qx database; then
	./backup.sh "avant-$tag"
fi

set_var "$var" "$tag"

if apply "$([[ $service == back ]] && echo php || echo front)" && wait_healthy; then
	mkdir -p state
	echo "$tag" > "state/ok-$service"
	# Images inutilisées depuis plus de 14 jours (le disque est petit) ; DEPLOY_PRUNE=0 pour l'éviter (essais locaux).
	if [[ ${DEPLOY_PRUNE:-1} == 1 ]]; then
		docker image prune -af --filter "until=336h" > /dev/null 2>&1 || true
	fi
	echo "== $service $tag déployé"
	compose ps
	exit 0
fi

echo "== ÉCHEC du déploiement de $service $tag" >&2
compose logs --tail 40 "$([[ $service == back ]] && echo php || echo front)" >&2 || true

if [[ -n $previous && $previous != "$tag" ]]; then
	echo "== Retour arrière vers $previous" >&2
	set_var "$var" "$previous"
	apply "$([[ $service == back ]] && echo php || echo front)" || true
	if wait_healthy; then
		echo "== $service est revenu en $previous. Le schéma de la base n'a PAS été remis en arrière." >&2
	else
		echo "== ATTENTION : $service ne répond pas non plus en $previous." >&2
	fi
else
	set_var "$var" "$previous"
	echo "== Pas de version précédente à remettre." >&2
fi
exit 1
