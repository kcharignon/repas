#!/usr/bin/env bash
# Amorçage des ingrédients (ING-008, ADR 0028 amendement du 2026-10-20, docs/ingredients/sans-commun.md § 6) : chaque compte ACTIF qui n'a pas
# encore ses ingrédients (le compte vide d'avant la passation, un compte activé quand l'interrupteur INGREDIENT_SEEDING_ENABLED était coupé)
# reçoit une copie des ingrédients « de base » du propriétaire du catalogue, plus ceux de ce catalogue que ses données référencent, avec leurs
# conversions propres ; tout ce qui les référençait chez lui (lignes de recettes et de listes, listes envoyées au drive comprises, choix du
# drive) pointe vers sa copie. Le catalogue reste, rien n'est jamais supprimé, un compte déjà amorcé n'est jamais touché. Décision humaine : elle
# se lance avec l'assistant, une étape à la fois, JAMAIS le même jour qu'une autre release du back. Elle marche interrupteur coupé.
#
# Sans argument : lecture seule, le rapport « à blanc » (aucune écriture). À lire avec l'assistant AVANT d'appliquer.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s'                                    < .claude/skills/repas-ops/references/amorcer-ingredients.sh
# Avec --appliquer : le rapport à blanc, puis la sauvegarde `./backup.sh avant-amorcage-ingredients`, puis l'écriture (une transaction par
# compte), puis la vérification d'après (`--check`, la requête de santé). S'arrête avant la sauvegarde si le rapport à blanc signale une
# anomalie bloquante, et ne fait ni sauvegarde ni écriture s'il n'y a rien à faire.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- --appliquer --accepter-listes-envoyees' < .claude/skills/repas-ops/references/amorcer-ingredients.sh
# Rejouable : un deuxième --appliquer n'écrit rien et le dit ; --verifier ne fait que lire et dit s'il reste quelque chose à faire ou si la
# santé est rouge.
#
# Options (après -- ) :
#   --appliquer                  écrit (sinon lecture seule)
#   --verifier                   lecture seule : le verdict et la requête de santé (à lancer après --appliquer, et chaque semaine)
#   --accepter-listes-envoyees   obligatoire dès qu'une liste déjà envoyée au drive est concernée (le rapport le dit)
#   --compte=<référence>         se limite à ce compte (la référence courte du rapport), de n'importe quel statut, répétable : l'essai sur un
#                                seul compte d'abord, ou un compte en attente / désactivé qui a des lignes sur le catalogue
#   --sauf=<référence>           exclut ce compte, répétable
#   --complet                    complète aussi un compte DÉJÀ amorcé (exige --compte= ; jamais sur tous les comptes)
#   --lancement=<nom>            nom du lancement écrit dans les alias (défaut rattrapage-AAAA-MM-JJ, 64 caractères au plus)
# La sortie ne contient ni adresse e-mail, ni identifiant de compte, ni nom d'ingrédient personnel : les comptes sont numérotés.
# Code de sortie de la vérification : 0 tout est conforme, 1 il reste à faire, 3 rien à écrire mais la santé est rouge (par exemple un compte
# en attente ou désactivé qui a des lignes sur le catalogue : il ne se sert que sur demande, --compte=).
# REPAS_DIR (défaut /opt/repas) ne sert qu'à essayer ce script sur une copie.
set -euo pipefail

apply=0
check=0
accept=()
accounts=()
run=()
complete=()
for argument in "$@"; do
	case $argument in
		--appliquer) apply=1 ;;
		--verifier) check=1 ;;
		--accepter-listes-envoyees) accept=(--accept-sent) ;;
		--compte=?*) accounts+=(--user="${argument#--compte=}") ;;
		--sauf=?*) accounts+=(--except="${argument#--sauf=}") ;;
		--complet) complete=(--complete) ;;
		--lancement=?*) run=(--source="${argument#--lancement=}") ;;
		*)
			echo "ECHEC : argument inconnu « $argument » (voir l'en-tête du script)"
			exit 2
			;;
	esac
done
if [[ $apply -eq 1 && $check -eq 1 ]]; then
	echo "ECHEC : --appliquer et --verifier s'excluent"
	exit 2
fi
if [[ ${#complete[@]} -gt 0 && ${#accounts[@]} -eq 0 ]]; then
	echo "ECHEC : --complet exige --compte=<référence> : on ne complète jamais tous les comptes"
	exit 2
fi

dir=${REPAS_DIR:-/opt/repas}
cd "$dir"

# Le travail est la commande de console du back (bin/console repas:ingredients:seed). Chaque appel porte < /dev/null : dans un script lancé par
# « ssh … bash -s < script », une commande docker qui lit son entrée avalerait la suite du script.
seed() {
	docker compose exec -T php php bin/console repas:ingredients:seed "$@" < /dev/null
}

# Un tableau vide se développe sans erreur même avec « set -u » (bash 4.3 et plus anciens)
if [[ $check -eq 1 ]]; then
	echo "== Vérification (lecture seule)"
	seed --check ${complete[@]+"${complete[@]}"} ${accounts[@]+"${accounts[@]}"}
	exit 0
fi

echo "== Rapport à blanc (rien n'est modifié)"
seed ${complete[@]+"${complete[@]}"} ${accounts[@]+"${accounts[@]}"}

if [[ $apply -eq 0 ]]; then
	echo "Lecture seule : rien n'a été modifié. Pour appliquer : bash -s -- --appliquer [--accepter-listes-envoyees]"
	exit 0
fi

# Rien à écrire (second lancement, ou seule la santé est rouge) : ni sauvegarde ni écriture inutiles
status=0
seed --check ${complete[@]+"${complete[@]}"} ${accounts[@]+"${accounts[@]}"} > /dev/null 2>&1 || status=$?
if [[ $status -eq 0 ]]; then
	echo "Rien à faire : aucune sauvegarde, aucune écriture."
	exit 0
fi
if [[ $status -eq 3 ]]; then
	echo "Rien à écrire pour cette sélection : aucune sauvegarde, aucune écriture. Mais la requête de santé n'est pas verte :"
	seed --check ${complete[@]+"${complete[@]}"} ${accounts[@]+"${accounts[@]}"} || true
	echo "ECHEC : relire la santé ci-dessus avec l'assistant (un compte en attente ou désactivé qui a des lignes sur le catalogue se sert avec --compte=<référence>)."
	exit 1
fi

echo "== Sauvegarde avant l'écriture"
./backup.sh avant-amorcage-ingredients

echo "== Écriture (une transaction par compte)"
seed --apply ${accept[@]+"${accept[@]}"} ${run[@]+"${run[@]}"} ${complete[@]+"${complete[@]}"} ${accounts[@]+"${accounts[@]}"}

echo "== Vérification d'après (lecture seule)"
seed --check ${complete[@]+"${complete[@]}"} ${accounts[@]+"${accounts[@]}"}
echo "OK : amorçage terminé, plus rien à faire et la requête de santé est verte (le tableau ci-dessus le dit)."
