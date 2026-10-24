#!/usr/bin/env bash
# Allume ou coupe l'amorçage AUTOMATIQUE des ingrédients à l'activation d'un compte (ING-008, ADR 0028 amendement du 2026-10-20) : écrit
# INGREDIENT_SEEDING_ENABLED dans /opt/repas/.env, puis recrée le conteneur php pour qu'il lise la nouvelle valeur. Décision humaine : à lancer
# par l'utilisateur, avec true ou false, APRÈS le classement et l'amorçage du compte vide (docs/ingredients/sans-commun.md § 4, étapes 7 à 9).
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true'  < .claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- false' < .claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh
# Coupé (ou ligne absente) : un compte qui devient actif ne reçoit rien et l'activation réussit ; la commande de rattrapage
# (repas:ingredients:seed, script amorcer-ingredients.sh) marche dans les deux cas. Allumé : chaque activation copie les ingrédients « de base »
# dans sa propre transaction ; si la copie échoue, l'activation échoue avec elle et la personne réessaie.
# Aucun secret ici : INGREDIENT_SEEDING_ENABLED n'est qu'un interrupteur. Le site coupe quelques secondes (le conteneur php redémarre).
# REPAS_DIR (défaut /opt/repas) ne sert qu'à essayer ce script sur une copie.
set -euo pipefail

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
[[ -f $env_file ]] || {
	echo "ECHEC : $env_file introuvable"
	exit 1
}
cd "$dir"

current=$(sed -n 's/^INGREDIENT_SEEDING_ENABLED=//p' "$env_file" | tail -n 1)
echo "Avant : INGREDIENT_SEEDING_ENABLED=${current:-(absente, donc false)}"

if [[ ${current:-false} != "$value" || -z $current ]]; then
	cp -p "$env_file" "$env_file.avant-amorcage"
	# Retire la ligne existante (s'il y en a une), puis ajoute la nouvelle : jamais de doublon.
	{ grep -v '^INGREDIENT_SEEDING_ENABLED=' "$env_file.avant-amorcage" || true; } > "$env_file.nouveau"
	echo "INGREDIENT_SEEDING_ENABLED=$value" >> "$env_file.nouveau"
	chmod 600 "$env_file.nouveau"
	chown --reference="$env_file.avant-amorcage" "$env_file.nouveau"
	mv "$env_file.nouveau" "$env_file"
	echo "Écrit : INGREDIENT_SEEDING_ENABLED=$value (copie de l'ancien fichier : $env_file.avant-amorcage)"
else
	echo "Le fichier est déjà à jour ; on vérifie quand même le conteneur."
fi

# Recrée php seulement si sa configuration a changé (sans effet sinon). Même attente que deploy.sh.
docker compose up -d --wait --wait-timeout 240 php < /dev/null

seen=$(docker compose exec -T php printenv INGREDIENT_SEEDING_ENABLED < /dev/null | tr -d '\r' || true)
if [[ $seen != "$value" ]]; then
	echo "ECHEC : le conteneur php voit INGREDIENT_SEEDING_ENABLED=${seen:-(absente)} au lieu de $value."
	exit 1
fi

# La bordure doit retrouver le back : même contrôle de santé que deploy.sh.
for _ in $(seq 1 30); do
	if docker compose exec -T edge wget -q -O - http://php/api/v1/health < /dev/null > /dev/null 2>&1; then
		echo "OK : INGREDIENT_SEEDING_ENABLED=$value, le conteneur php le voit et /api/v1/health répond."
		exit 0
	fi
	sleep 2
done
echo "ECHEC : /api/v1/health ne répond pas après le redémarrage de php (voir : docker compose logs --tail 60 php)."
exit 1
