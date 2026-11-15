#!/usr/bin/env bash
# Allume ou coupe la trace des échanges avec Alexa (ADR 0029 décision 14, phase de test) : écrit ALEXA_TRACE_ENABLED dans /opt/repas/.env, puis recrée le
# conteneur php pour qu'il lise la nouvelle valeur. Décision humaine : à lancer par l'utilisateur, avec true ou false.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true'  < .claude/skills/repas-ops/references/ecrire-alexa-trace.sh
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- false' < .claude/skills/repas-ops/references/ecrire-alexa-trace.sh
# Aucun secret ici. Allumée, la table voice_trace garde les 1 000 derniers échanges avec Alexa (requête assainie : jamais le jeton ; réponse ; issue ; panne) ;
# elle se lit par `php bin/console repas:voice:traces` (docs/voice/alexa.md § 7). Coupée, plus rien n'est écrit (aucune requête de plus) ; la table et ce
# qu'elle contient restent, et la commande de lecture marche toujours. Indépendante de ALEXA_ENABLED. Le site coupe quelques secondes (le conteneur php
# redémarre). À couper puis à retirer à la fin de la phase de test.
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

current=$(get ALEXA_TRACE_ENABLED)
echo "Avant : ALEXA_TRACE_ENABLED=${current:-(absente, donc false)}"

if [[ ${current:-false} != "$value" || -z $current ]]; then
	cp -p "$env_file" "$env_file.avant-alexa-trace"
	# Retire la ligne existante (s'il y en a une), puis ajoute la nouvelle : jamais de doublon.
	{ grep -v '^ALEXA_TRACE_ENABLED=' "$env_file.avant-alexa-trace" || true; } > "$env_file.nouveau"
	echo "ALEXA_TRACE_ENABLED=$value" >> "$env_file.nouveau"
	chmod 600 "$env_file.nouveau"
	chown --reference="$env_file.avant-alexa-trace" "$env_file.nouveau"
	mv "$env_file.nouveau" "$env_file"
	echo "Écrit : ALEXA_TRACE_ENABLED=$value (copie de l'ancien fichier : $env_file.avant-alexa-trace)"
else
	echo "Le fichier est déjà à jour ; on vérifie quand même le conteneur."
fi

# Recrée php seulement si sa configuration a changé (sans effet sinon). Même attente que deploy.sh.
docker compose up -d --wait --wait-timeout 240 php < /dev/null

# printenv échoue quand la variable est absente : sans « || true », set -e arrêterait le script sans un mot
seen=$(docker compose exec -T php printenv ALEXA_TRACE_ENABLED < /dev/null 2> /dev/null | tr -d '\r' || true)
if [[ $seen != "$value" ]]; then
	echo "ECHEC : le conteneur php voit ALEXA_TRACE_ENABLED=${seen:-(absente)} au lieu de $value."
	exit 1
fi

# La bordure doit retrouver le back : même contrôle de santé que deploy.sh.
for _ in $(seq 1 30); do
	if docker compose exec -T edge wget -q -O - http://php/api/v1/health < /dev/null > /dev/null 2>&1; then
		echo "OK : ALEXA_TRACE_ENABLED=$value, le conteneur php le voit et /api/v1/health répond."
		exit 0
	fi
	sleep 2
done
echo "ECHEC : /api/v1/health ne répond pas après le redémarrage de php (voir : docker compose logs --tail 60 php)."
exit 1
