#!/usr/bin/env bash
# Migration ING-003 (ADR 0028, docs/ingredients/migration.md) : chaque compte concerné reçoit sa propre copie des ingrédients communs, et
# tout ce qui référençait un commun chez lui (recettes, listes dont les envoyées, choix du drive) pointe vers sa copie. Les communs restent.
# Rien n'est jamais supprimé. Décision humaine : elle se lance avec l'assistant, une étape à la fois, JAMAIS le même jour qu'une autre
# release du back, et pas pendant qu'un envoi de liste est en cours sur un téléphone.
#
# Sans argument : lecture seule, le rapport « à blanc » (aucune écriture). À lire avec l'assistant AVANT d'appliquer.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s'                                    < .claude/skills/repas-ops/references/adopter-ingredients.sh
# Avec --appliquer : le rapport à blanc, puis la sauvegarde `./backup.sh avant-adoption-ingredients`, puis l'écriture (une transaction par
# compte), puis la vérification d'après (`--check`). S'arrête avant la sauvegarde si le rapport à blanc signale une anomalie bloquante, et
# ne fait ni sauvegarde ni écriture s'il n'y a rien à faire.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- --appliquer --accepter-listes-envoyees' < .claude/skills/repas-ops/references/adopter-ingredients.sh
# Rejouable : un deuxième --appliquer n'écrit rien et le dit ; --verifier ne fait que lire et dit s'il reste quelque chose à faire.
#
# Options (après -- ) :
#   --appliquer                  écrit (sinon lecture seule)
#   --verifier                   lecture seule : le verdict « rien à faire » (à lancer après --appliquer)
#   --accepter-listes-envoyees   obligatoire dès qu'une liste déjà envoyée au drive est concernée (le rapport le dit)
#   --compte=<référence>         se limite à ce compte (la référence courte du rapport), répétable : l'essai sur un seul compte d'abord
#   --sauf=<référence>           exclut ce compte, répétable
#   --lancement=<nom>            nom du lancement écrit dans les alias (défaut migration-AAAA-MM-JJ, 64 caractères au plus)
#   --annuler=<lancement>        retour arrière des DONNÉES d'un lancement (avec --appliquer) : repointe vers les communs, efface ses alias,
#                                laisse les copies. Dernier recours, voir docs/ingredients/migration.md § 10
# La sortie ne contient ni adresse e-mail, ni identifiant de compte, ni nom d'ingrédient personnel : les comptes sont numérotés.
# REPAS_DIR (défaut /opt/repas) ne sert qu'à essayer ce script sur une copie.
set -euo pipefail

apply=0
check=0
accept=()
accounts=()
run=()
undo=()
for argument in "$@"; do
	case $argument in
		--appliquer) apply=1 ;;
		--verifier) check=1 ;;
		--accepter-listes-envoyees) accept=(--accept-sent) ;;
		--compte=?*) accounts+=(--user="${argument#--compte=}") ;;
		--sauf=?*) accounts+=(--except="${argument#--sauf=}") ;;
		--lancement=?*) run=(--source="${argument#--lancement=}") ;;
		--annuler=?*) undo=(--undo="${argument#--annuler=}") ;;
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
if [[ ${#undo[@]} -gt 0 && $apply -eq 0 ]]; then
	echo "ECHEC : --annuler écrit, ajoute --appliquer"
	exit 2
fi

dir=${REPAS_DIR:-/opt/repas}
cd "$dir"

# Le travail est la commande de console du back (bin/console repas:ingredients:adopt-commons). Chaque appel porte < /dev/null : dans un
# script lancé par « ssh … bash -s < script », une commande docker qui lit son entrée avalerait la suite du script.
adopt() {
	docker compose exec -T php php bin/console repas:ingredients:adopt-commons "$@" < /dev/null
}

# Un tableau vide se développe sans erreur même avec « set -u » (bash 4.3 et plus anciens)
if [[ ${#undo[@]} -gt 0 ]]; then
	echo "== Retour arrière des données du lancement (sauvegarde d'abord)"
	./backup.sh avant-annulation-adoption-ingredients
	adopt --apply "${undo[@]}" ${accept[@]+"${accept[@]}"} ${accounts[@]+"${accounts[@]}"}
	echo "OK : retour arrière terminé. Les copies restent en place (voir le rapport ci-dessus)."
	exit 0
fi

if [[ $check -eq 1 ]]; then
	echo "== Vérification (lecture seule)"
	adopt --check ${accounts[@]+"${accounts[@]}"}
	exit 0
fi

echo "== Rapport à blanc (rien n'est modifié)"
adopt ${accounts[@]+"${accounts[@]}"}

if [[ $apply -eq 0 ]]; then
	echo "Lecture seule : rien n'a été modifié. Pour appliquer : bash -s -- --appliquer [--accepter-listes-envoyees]"
	exit 0
fi

# Rien à faire (second lancement) : ni sauvegarde ni écriture inutiles
if adopt --check ${accounts[@]+"${accounts[@]}"} > /dev/null 2>&1; then
	echo "Rien à faire : aucune sauvegarde, aucune écriture."
	exit 0
fi

echo "== Sauvegarde avant l'écriture"
./backup.sh avant-adoption-ingredients

echo "== Écriture (une transaction par compte)"
adopt --apply ${accept[@]+"${accept[@]}"} ${run[@]+"${run[@]}"} ${accounts[@]+"${accounts[@]}"}

echo "== Vérification d'après (lecture seule)"
adopt --check ${accounts[@]+"${accounts[@]}"}
echo "OK : migration terminée, plus rien à faire (le tableau ci-dessus le dit)."
