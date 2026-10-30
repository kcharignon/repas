#!/usr/bin/env bash
# Choisit les enseignes de drive allumées (DRV-702, ADR 0027) : écrit DRIVE_RETAILERS dans /opt/repas/.env, puis recrée le conteneur
# php pour qu'il lise la nouvelle valeur. Décision humaine : à lancer par l'utilisateur, avec la liste des identifiants d'enseigne
# séparés par des virgules (leclerc, leclerc,auchan…) ou le mot « aucune » (aucune enseigne : le drive n'a plus rien à servir).
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- leclerc'        < .claude/skills/repas-ops/references/ecrire-drive-retailers.sh
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- leclerc,auchan' < .claude/skills/repas-ops/references/ecrire-drive-retailers.sh
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- aucune'         < .claude/skills/repas-ops/references/ecrire-drive-retailers.sh
# Aucun secret ici : DRIVE_RETAILERS n'est qu'une liste d'identifiants. DRIVE_ENABLED (l'interrupteur global, ecrire-drive-enabled.sh)
# l'emporte sur tout. Variable absente = leclerc seul ; variable vide = aucune enseigne ; un identifiant inconnu du back est ignoré
# (avertissement dans le journal). Les données d'une enseigne coupée restent en base : la rallumer les retrouve.
# Le site coupe quelques secondes (le conteneur php redémarre). REPAS_DIR (défaut /opt/repas) ne sert qu'à essayer ce script sur une copie.
set -euo pipefail

arg=${1:-}
case $arg in
	aucune) value="" ;;
	"")
		echo "ECHEC : donner la liste des enseignes (leclerc ou leclerc,auchan) ou « aucune » : bash -s -- leclerc"
		exit 2
		;;
	*)
		if [[ ! $arg =~ ^[a-z0-9]{1,20}(,[a-z0-9]{1,20})*$ ]]; then
			echo "ECHEC : « $arg » n'est pas une liste d'identifiants séparés par des virgules (minuscules et chiffres, sans espace)."
			exit 2
		fi
		value=$arg
		;;
esac

dir=${REPAS_DIR:-/opt/repas}
env_file=$dir/.env
[[ -f $env_file ]] || {
	echo "ECHEC : $env_file introuvable"
	exit 1
}
cd "$dir"

if grep -q '^DRIVE_RETAILERS=' "$env_file"; then
	current=$(sed -n 's/^DRIVE_RETAILERS=//p' "$env_file" | tail -n 1)
	echo "Avant : DRIVE_RETAILERS=${current:-(vide : aucune enseigne)}"
else
	current=""
	echo "Avant : DRIVE_RETAILERS absente (donc leclerc seul)"
fi

if ! grep -q '^DRIVE_RETAILERS=' "$env_file" || [[ $current != "$value" ]]; then
	cp -p "$env_file" "$env_file.avant-enseignes"
	# Retire la ligne existante (s'il y en a une), puis ajoute la nouvelle : jamais de doublon.
	{ grep -v '^DRIVE_RETAILERS=' "$env_file.avant-enseignes" || true; } > "$env_file.nouveau"
	echo "DRIVE_RETAILERS=$value" >> "$env_file.nouveau"
	chmod 600 "$env_file.nouveau"
	chown --reference="$env_file.avant-enseignes" "$env_file.nouveau"
	mv "$env_file.nouveau" "$env_file"
	echo "Écrit : DRIVE_RETAILERS=$value (copie de l'ancien fichier : $env_file.avant-enseignes)"
else
	echo "Le fichier est déjà à jour ; on vérifie quand même le conteneur."
fi

# Recrée php seulement si sa configuration a changé (sans effet sinon). Même attente que deploy.sh.
docker compose up -d --wait --wait-timeout 240 php < /dev/null

# printenv sort 1 quand la variable n'existe pas : une variable vide (aucune enseigne) est un cas valide.
seen=$(docker compose exec -T php printenv DRIVE_RETAILERS < /dev/null | tr -d '\r' || true)
if [[ $seen != "$value" ]]; then
	echo "ECHEC : le conteneur php voit DRIVE_RETAILERS=$seen au lieu de $value."
	exit 1
fi

# La bordure doit retrouver le back : même contrôle de santé que deploy.sh.
for _ in $(seq 1 30); do
	if docker compose exec -T edge wget -q -O - http://php/api/v1/health < /dev/null > /dev/null 2>&1; then
		echo "OK : DRIVE_RETAILERS=${value:-(aucune)}, le conteneur php le voit et /api/v1/health répond."
		exit 0
	fi
	sleep 2
done
echo "ECHEC : /api/v1/health ne répond pas après le redémarrage de php (voir : docker compose logs --tail 60 php)."
exit 1
