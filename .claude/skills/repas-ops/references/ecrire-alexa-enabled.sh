#!/usr/bin/env bash
# Allume ou coupe la commande vocale Alexa (ADR 0029 décision 8, VOX-601) : écrit ALEXA_ENABLED dans /opt/repas/.env, puis recrée le
# conteneur php pour qu'il lise la nouvelle valeur. Décision humaine : à lancer par l'utilisateur, avec true ou false.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true'  < .claude/skills/repas-ops/references/ecrire-alexa-enabled.sh
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- false' < .claude/skills/repas-ops/references/ecrire-alexa-enabled.sh
# Aucun secret ici. Pour allumer, la configuration doit exister (ecrire-alexa-env.sh) : sinon le script refuse. Le site coupe quelques
# secondes (le conteneur php redémarre). Couper ne supprime rien : les liaisons de compte restent.
# REPAS_DIR (défaut /opt/repas) ne sert qu'à essayer ce script sur une copie.
set -euo pipefail
# Le .env porte tous les secrets : rien de ce que ce script crée n'est lisible par un autre compte, même interrompu
umask 077

value=${1:-}
case $value in
	true | false) ;;
	*)
		echo "ECHEC : donner true (allumer) ou false (couper) : bash -s -- true"
		exit 2
		;;
esac

dir=${REPAS_DIR:-/opt/repas}
env_file=$dir/.env
trap 'rm -f "$env_file.nouveau"' EXIT
[[ -f $env_file ]] || {
	echo "ECHEC : $env_file introuvable"
	exit 1
}
cd "$dir"

get() { sed -n "s/^$1=//p" "$env_file" | tail -n 1; }

if [[ $value == true ]]; then
	missing=()
	[[ -n $(get ALEXA_SKILL_ID) ]] || missing+=(ALEXA_SKILL_ID)
	[[ -n $(get ALEXA_REDIRECT_URIS) ]] || missing+=(ALEXA_REDIRECT_URIS)
	[[ $(get ALEXA_OAUTH_CLIENT_SECRET_HASH) =~ ^[0-9a-fA-F]{64}$ ]] || missing+=(ALEXA_OAUTH_CLIENT_SECRET_HASH)
	if ((${#missing[@]} > 0)); then
		echo "ECHEC : configuration incomplète (${missing[*]}). Lancer d'abord ecrire-alexa-env.sh."
		exit 1
	fi
fi

current=$(get ALEXA_ENABLED)
echo "Avant : ALEXA_ENABLED=${current:-(absente, donc false)}"

if [[ ${current:-false} != "$value" || -z $current ]]; then
	cp -p "$env_file" "$env_file.avant-alexa-enabled"
	# Retire la ligne existante (s'il y en a une), puis ajoute la nouvelle : jamais de doublon.
	{ grep -v '^ALEXA_ENABLED=' "$env_file.avant-alexa-enabled" || true; } > "$env_file.nouveau"
	echo "ALEXA_ENABLED=$value" >> "$env_file.nouveau"
	chmod 600 "$env_file.nouveau"
	chown --reference="$env_file.avant-alexa-enabled" "$env_file.nouveau"
	mv "$env_file.nouveau" "$env_file"
	echo "Écrit : ALEXA_ENABLED=$value (copie de l'ancien fichier : $env_file.avant-alexa-enabled)"
else
	echo "Le fichier est déjà à jour ; on vérifie quand même le conteneur."
fi

# Recrée php seulement si sa configuration a changé (sans effet sinon). Même attente que deploy.sh.
docker compose up -d --wait --wait-timeout 240 php < /dev/null

# printenv échoue quand la variable est absente : sans « || true », set -e arrêterait le script sans un mot
seen=$(docker compose exec -T php printenv ALEXA_ENABLED < /dev/null 2> /dev/null | tr -d '\r' || true)
if [[ $seen != "$value" ]]; then
	echo "ECHEC : le conteneur php voit ALEXA_ENABLED=${seen:-(absente)} au lieu de $value."
	exit 1
fi

# La bordure doit retrouver le back : même contrôle de santé que deploy.sh.
for _ in $(seq 1 30); do
	if docker compose exec -T edge wget -q -O - http://php/api/v1/health < /dev/null > /dev/null 2>&1; then
		echo "OK : ALEXA_ENABLED=$value, le conteneur php le voit et /api/v1/health répond."
		exit 0
	fi
	sleep 2
done
echo "ECHEC : /api/v1/health ne répond pas après le redémarrage de php (voir : docker compose logs --tail 60 php)."
exit 1
