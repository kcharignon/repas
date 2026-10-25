#!/usr/bin/env bash
# Écrit la configuration de la commande vocale Alexa dans /opt/repas/.env (ADR 0029, VOX-601) : identifiant du skill, adresses de retour
# de la liaison de compte et EMPREINTE du secret du client OAuth. N'allume PAS la fonction (voir ecrire-alexa-enabled.sh).
# À lancer par l'utilisateur, DANS SON PROPRE TERMINAL (pas avec « ! » de Claude Code : la saisie du secret se fait sur le terminal et
# ne doit jamais atterrir dans la conversation) :
#   ssh -t -i ~/.ssh/id_ed25519 root@64.227.124.100 "$(cat .claude/skills/repas-ops/references/ecrire-alexa-env.sh)"
# Le script est passé comme TEXTE de la commande, pas redirigé vers « bash -s » : avec « < script », ssh n'alloue pas de terminal
# (« Pseudo-terminal will not be allocated because stdin is not a terminal ») et la saisie du secret n'aurait nulle part où se faire.
#
# Le secret : l'utilisateur le fabrique lui-même (par exemple `openssl rand -base64 36 | tr -d '\n=+/'` sur son poste), le colle dans la
# console Amazon (Build > Account Linking > Client Secret) puis ici. Il est saisi sans écho, deux fois ; seule son empreinte SHA-256 est
# écrite dans le .env, le secret lui-même n'est écrit nulle part. Laisser une réponse vide garde la valeur actuelle.
# REPAS_DIR (défaut /opt/repas) et REPAS_TTY (défaut /dev/tty) ne servent qu'à essayer ce script sur une copie.
set -euo pipefail
# Le .env porte tous les secrets : rien de ce que ce script crée n'est lisible par un autre compte, même interrompu
umask 077

dir=${REPAS_DIR:-/opt/repas}
tty=${REPAS_TTY:-/dev/tty}
# Un seul descripteur pour toute la saisie : chaque question lit la ligne suivante
exec 3< "$tty"
env_file=$dir/.env
trap 'rm -f "$env_file.nouveau"' EXIT
[[ -f $env_file ]] || {
	echo "ECHEC : $env_file introuvable"
	exit 1
}

get() { sed -n "s/^$1=//p" "$env_file" | tail -n 1; }

current_skill=$(get ALEXA_SKILL_ID)
current_uris=$(get ALEXA_REDIRECT_URIS)
current_hash=$(get ALEXA_OAUTH_CLIENT_SECRET_HASH)
current_client=$(get ALEXA_OAUTH_CLIENT_ID)
client_id=${current_client:-repas-alexa}

echo "Actuellement : skill=${current_skill:-(vide)}  adresses de retour=${current_uris:-(vide)}  empreinte du secret=$([[ -n $current_hash ]] && echo présente || echo absente)"
echo "Identifiant du client OAuth : $client_id (à saisir tel quel dans la console Amazon)"
echo

read -rp "Identifiant du skill (amzn1.ask.skill.…), Entrée pour garder : " skill <&3
skill=${skill:-$current_skill}
[[ $skill =~ ^amzn1\.ask\.skill\.[0-9a-fA-F-]{20,64}$ ]] || {
	echo "ECHEC : un identifiant de skill ressemble à amzn1.ask.skill.xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
	exit 1
}

echo "Adresses de retour que la console affiche (Account Linking > Redirect URLs), séparées par des virgules."
read -rp "Adresses, Entrée pour garder : " uris <&3
uris=${uris:-$current_uris}
uris=${uris//[[:space:]]/}
[[ -n $uris ]] || {
	echo "ECHEC : au moins une adresse de retour est nécessaire"
	exit 1
}
IFS=',' read -ra list <<< "$uris"
for uri in "${list[@]}"; do
	# https seulement, ni espace, ni #, ni $, ni guillemet : le .env est lu par docker compose, qui interprète $
	[[ $uri =~ ^https://[A-Za-z0-9._~:/?\&=%+-]+$ ]] || {
		echo "ECHEC : adresse refusée : $uri (https:// et caractères simples seulement)"
		exit 1
	}
done

secret_hash=$current_hash
read -rsp "Secret du client (rien ne s'affiche), Entrée pour garder l'actuel : " secret <&3
echo
if [[ -n $secret ]]; then
	[[ ${#secret} -ge 32 && ${#secret} -le 256 && $secret =~ ^[[:graph:]]+$ ]] || {
		echo "ECHEC : le secret doit faire au moins 32 caractères visibles, sans espace"
		exit 1
	}
	read -rsp "Le secret une seconde fois : " again <&3
	echo
	[[ $secret == "$again" ]] || {
		echo "ECHEC : les deux saisies diffèrent"
		exit 1
	}
	secret_hash=$(printf '%s' "$secret" | sha256sum | cut -d' ' -f1)
	unset secret again
fi
[[ $secret_hash =~ ^[0-9a-f]{64}$ ]] || {
	echo "ECHEC : aucun secret n'est configuré (saisir le secret)"
	exit 1
}

cp -p "$env_file" "$env_file.avant-alexa"
{ grep -v -E '^(ALEXA_SKILL_ID|ALEXA_REDIRECT_URIS|ALEXA_OAUTH_CLIENT_ID|ALEXA_OAUTH_CLIENT_SECRET_HASH)=' "$env_file.avant-alexa" || true; } > "$env_file.nouveau"
{
	echo "ALEXA_SKILL_ID=$skill"
	echo "ALEXA_REDIRECT_URIS=$uris"
	echo "ALEXA_OAUTH_CLIENT_ID=$client_id"
	echo "ALEXA_OAUTH_CLIENT_SECRET_HASH=$secret_hash"
} >> "$env_file.nouveau"
chmod 600 "$env_file.nouveau"
chown --reference="$env_file.avant-alexa" "$env_file.nouveau"
mv "$env_file.nouveau" "$env_file"
echo "Écrit dans $env_file (copie de l'ancien fichier : $env_file.avant-alexa). Empreinte du secret : ${secret_hash:0:8}…"

# Si la fonction est déjà allumée, php doit relire sa configuration ; sinon rien ne change tant qu'on ne l'allume pas.
if [[ $(get ALEXA_ENABLED) == true ]]; then
	cd "$dir"
	docker compose up -d --wait --wait-timeout 240 php < /dev/null
	# Le service php n'a pas de contrôle de santé propre : on attend que la bordure retrouve le back, comme deploy.sh
	for _ in $(seq 1 30); do
		if docker compose exec -T edge wget -q -O - http://php/api/v1/health < /dev/null > /dev/null 2>&1; then
			echo "La fonction est allumée : php a été recréé pour relire la configuration et /api/v1/health répond."
			exit 0
		fi
		sleep 2
	done
	echo "ECHEC : /api/v1/health ne répond pas après le redémarrage de php (voir : docker compose logs --tail 60 php)."
	exit 1
else
	echo "La fonction est coupée : rien d'autre à faire. Pour l'allumer : ecrire-alexa-enabled.sh true."
fi
