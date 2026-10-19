#!/usr/bin/env bash
# Écrit la dernière version publiée de l'application Android (APP_LATEST_VERSION) ou la plus ancienne encore attendue
# (APP_MIN_VERSION) dans /opt/repas/.env, puis recrée le conteneur php pour qu'il lise la nouvelle valeur (DRV-637). À lancer par
# l'utilisateur après chaque publication de l'application (`android-vX.Y.Z`), avec la version publiée :
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- latest 0.1.10'  < .claude/skills/repas-ops/references/ecrire-app-version.sh
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- minimum 0.1.9'  < .claude/skills/repas-ops/references/ecrire-app-version.sh
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 "bash -s -- latest ''"      < .claude/skills/repas-ops/references/ecrire-app-version.sh   # vider
# Vide : personne n'est invité à mettre à jour. Aucun secret ici. Le site coupe quelques secondes (le conteneur php redémarre).
# REPAS_DIR (défaut /opt/repas) ne sert qu'à essayer ce script sur une copie.
set -euo pipefail

case ${1:-} in
	latest) name=APP_LATEST_VERSION ;;
	minimum) name=APP_MIN_VERSION ;;
	*)
		echo "ECHEC : donner latest ou minimum, puis la version : bash -s -- latest 0.1.10"
		exit 2
		;;
esac
if [[ $# -ne 2 ]]; then
	echo "ECHEC : donner la version (ou '' pour vider) : bash -s -- $1 0.1.10"
	exit 2
fi
value=$2
# Même règle que le back (AppVersion) : trois nombres séparés par des points, 20 caractères au plus ; ou vide.
if [[ -n $value && (${#value} -gt 20 || ! $value =~ ^[0-9]+\.[0-9]+\.[0-9]+$) ]]; then
	echo "ECHEC : « $value » n'est pas une version (trois nombres séparés par des points, comme 0.1.10)."
	exit 2
fi

dir=${REPAS_DIR:-/opt/repas}
env_file=$dir/.env
[[ -f $env_file ]] || {
	echo "ECHEC : $env_file introuvable"
	exit 1
}
cd "$dir"

current=$(sed -n "s/^$name=//p" "$env_file" | tail -n 1)
echo "Avant : $name=${current:-(vide ou absente)}"

if [[ $current != "$value" ]] || ! grep -q "^$name=" "$env_file"; then
	cp -p "$env_file" "$env_file.avant-version"
	# Retire la ligne existante (s'il y en a une), puis ajoute la nouvelle : jamais de doublon.
	{ grep -v "^$name=" "$env_file.avant-version" || true; } > "$env_file.nouveau"
	echo "$name=$value" >> "$env_file.nouveau"
	chmod 600 "$env_file.nouveau"
	chown --reference="$env_file.avant-version" "$env_file.nouveau"
	mv "$env_file.nouveau" "$env_file"
	echo "Écrit : $name=$value (copie de l'ancien fichier : $env_file.avant-version)"
else
	echo "Le fichier est déjà à jour ; on vérifie quand même le conteneur."
fi

# Recrée php seulement si sa configuration a changé (sans effet sinon). Même attente que deploy.sh.
docker compose up -d --wait --wait-timeout 240 php < /dev/null

seen=$(docker compose exec -T php printenv "$name" < /dev/null | tr -d '\r' || true)
if [[ $seen != "$value" ]]; then
	echo "ECHEC : le conteneur php voit $name=$seen au lieu de $value."
	exit 1
fi

# La bordure doit retrouver le back : même contrôle de santé que deploy.sh.
for _ in $(seq 1 30); do
	if docker compose exec -T edge wget -q -O - http://php/api/v1/health < /dev/null > /dev/null 2>&1; then
		echo "OK : $name=${value:-(vide)}, le conteneur php le voit et /api/v1/health répond."
		exit 0
	fi
	sleep 2
done
echo "ECHEC : /api/v1/health ne répond pas après le redémarrage de php (voir : docker compose logs --tail 60 php)."
exit 1
