#!/usr/bin/env bash
# Passation du catalogue (ING-009, ADR 0028 amendement du 2026-10-20, docs/ingredients/sans-commun.md §§ 4 à 6) : les 261 anciens communs (les
# ingrédients sans créateur) passent au compte administrateur protégé, le propriétaire du catalogue ; le classement « de base » (151 sur 261) est
# posé ; tout compte actif qui n'a pas ses ingrédients (le compte vide) est amorcé d'abord. UNE commande, des phases dans l'ordre : classer,
# amorcer, contrôler les préconditions P1 à P7 (relues dans la transaction), passer en UNE instruction, vérifier. Les recettes, les listes (dont les
# envoyées au drive) et les choix du drive du propriétaire ne sont pas touchés (mêmes slugs, aucune version ne monte). Rien n'est jamais supprimé.
# Décision humaine : elle se lance avec l'assistant, une étape à la fois, un jour calme, JAMAIS le même jour qu'une release du back.
#
# Sans argument : lecture seule, le rapport « à blanc » (aucune écriture, aucun verrou). À lire avec l'assistant AVANT d'appliquer.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s'                                    < .claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh
# Avec --appliquer : le rapport à blanc (le script s'arrête, sans sauvegarde ni écriture, s'il dit que l'écriture serait refusée : anomalie
# bloquante, précondition qui manque même une fois l'amorçage fait, liste envoyée sans accord), puis la sauvegarde
# `./backup.sh avant-passation-communs`, puis l'écriture, puis la vérification d'après (`--check`). Ni sauvegarde ni écriture s'il n'y a rien à faire.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- --appliquer --jusqu-a-l-amorcage --accepter-listes-envoyees' < …/passer-communs-a-l-administrateur.sh
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- --appliquer --accepter-listes-envoyees'                       < …/passer-communs-a-l-administrateur.sh
# Rejouable : un deuxième --appliquer n'écrit rien et le dit ; --verifier ne fait que lire.
#
# Options (après -- ) :
#   --appliquer                  écrit (sinon lecture seule)
#   --jusqu-a-l-amorcage         ne fait que le classement et l'amorçage (étape 7 du plan) : la passation n'est pas faite
#   --verifier                   lecture seule : le verdict « passation faite et conforme » (avec --jusqu-a-l-amorcage : « classement posé, plus rien à amorcer »)
#   --accepter-listes-envoyees   obligatoire dès qu'une liste déjà envoyée au drive est concernée par l'amorçage (le rapport le dit)
#   --lancement=<nom>            nom du lancement de l'amorçage, écrit dans les alias (défaut rattrapage-AAAA-MM-JJ, 64 caractères au plus)
# La sortie ne contient ni adresse e-mail, ni identifiant de compte, ni nom d'ingrédient personnel : les comptes sont numérotés.
# Code de sortie : 0 tout va bien, 1 refus ou échec (le message dit lequel), 2 argument inconnu.
# REPAS_DIR (défaut /opt/repas) ne sert qu'à essayer ce script sur une copie.
set -euo pipefail

apply=0
check=0
accept=()
stop=()
run=()
for argument in "$@"; do
	case $argument in
		--appliquer) apply=1 ;;
		--verifier) check=1 ;;
		--jusqu-a-l-amorcage) stop=(--stop-before-handover) ;;
		--accepter-listes-envoyees) accept=(--accept-sent) ;;
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

dir=${REPAS_DIR:-/opt/repas}
cd "$dir"

# Le travail est la commande de console du back (bin/console repas:ingredients:hand-over-catalog). Chaque appel porte < /dev/null : dans un script
# lancé par « ssh … bash -s < script », une commande docker qui lit son entrée avalerait la suite du script.
hand_over() {
	docker compose exec -T php php bin/console repas:ingredients:hand-over-catalog "$@" < /dev/null
}

# Un tableau vide se développe sans erreur même avec « set -u » (bash 4.3 et plus anciens)
if [[ $check -eq 1 ]]; then
	echo "== Vérification (lecture seule)"
	hand_over --check ${stop[@]+"${stop[@]}"}
	exit 0
fi

echo "== Rapport à blanc (rien n'est modifié)"
status=0
hand_over ${stop[@]+"${stop[@]}"} ${accept[@]+"${accept[@]}"} || status=$?

if [[ $apply -eq 0 ]]; then
	if [[ $status -ne 0 ]]; then
		echo "Lecture seule : rien n'a été modifié. Le rapport dit que --appliquer, avec ces options, refuserait d'écrire (code $status) : lire la raison ci-dessus avec l'assistant."
	else
		echo "Lecture seule : rien n'a été modifié. Pour appliquer : bash -s -- --appliquer [--jusqu-a-l-amorcage] [--accepter-listes-envoyees]"
	fi
	exit 0
fi
if [[ $status -ne 0 ]]; then
	echo "ECHEC : le rapport à blanc dit que --appliquer refuserait d'écrire (code $status, voir ci-dessus). Aucune sauvegarde, aucune écriture."
	exit 1
fi

# Rien à faire (second lancement) : ni sauvegarde ni écriture inutiles
if hand_over --check ${stop[@]+"${stop[@]}"} > /dev/null 2>&1; then
	echo "Rien à faire : aucune sauvegarde, aucune écriture."
	exit 0
fi

echo "== Sauvegarde avant l'écriture"
./backup.sh avant-passation-communs

if [[ ${#stop[@]} -gt 0 ]]; then
	echo "== Écriture (classement, puis amorçage compte par compte ; arrêt avant la passation)"
else
	echo "== Écriture (classement, amorçage compte par compte, puis passation en une instruction)"
fi
hand_over --apply ${stop[@]+"${stop[@]}"} ${accept[@]+"${accept[@]}"} ${run[@]+"${run[@]}"}

echo "== Vérification d'après (lecture seule)"
hand_over --check ${stop[@]+"${stop[@]}"}
if [[ ${#stop[@]} -gt 0 ]]; then
	echo "OK : classement posé et comptes amorcés. La passation n'est PAS faite (--jusqu-a-l-amorcage) : contrôles « avant », puis l'interrupteur, puis la passation."
else
	echo "OK : passation terminée, plus aucun ingrédient sans créateur, plus rien à faire (le tableau ci-dessus le dit)."
fi
