#!/usr/bin/env bash
# Remet en état les listes de courses « envoyées au drive » (statut SENT) : retour arrière du code vers une version qui ne connaît pas
# SENT, ou fonction coupée alors que des listes sont restées verrouillées (ADR 0023 §9, DRV-502). Décision humaine.
#
# Sans argument : lecture seule (nombre de listes par statut, propriétaires concernés). Rien n'est modifié.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s'               < .claude/skills/repas-ops/references/rouvrir-listes-envoyees.sh
# Avec --appliquer : sauvegarde de la base, puis modification en une transaction.
#   ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- --appliquer' < .claude/skills/repas-ops/references/rouvrir-listes-envoyees.sh
#
# Règle (plus prudente que le simple « UPDATE … SET status = 'ACTIVE' WHERE status = 'SENT' » de l'ADR 0023, qui laisserait deux listes
# actives à un propriétaire ayant déjà une liste en cours ou plusieurs listes envoyées) : par propriétaire, la liste envoyée la plus
# récente redevient ACTIVE s'il n'a aucune liste active ; toutes les autres repassent en PAUSED (le propriétaire les active quand il veut).
# La date d'envoi est effacée et la version de la liste monte d'un cran (un If-Match périmé est refusé en 412).
# Avant d'appliquer : couper la fonction (ecrire-drive-enabled.sh false) pour qu'aucune liste ne soit envoyée pendant l'opération.
# REPAS_DIR (défaut /opt/repas) ne sert qu'à essayer ce script sur une copie.
set -euo pipefail

mode=${1:-lecture}
case $mode in
	lecture | --appliquer) ;;
	*)
		echo "ECHEC : argument inconnu « $mode » (aucun argument, ou --appliquer)"
		exit 2
		;;
esac

dir=${REPAS_DIR:-/opt/repas}
cd "$dir"

# Le SQL passe sur l'entrée standard de psql : la ligne de commande ne contient que le nom du conteneur et la connexion.
psql_run() {
	docker compose exec -T database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -q'
}

echo "== Listes de courses par statut"
psql_run << 'SQL'
SELECT status, count(*) AS listes FROM shopping_list GROUP BY status ORDER BY status;
SELECT count(DISTINCT s.owner) AS proprietaires_avec_liste_envoyee,
       count(DISTINCT s.owner) FILTER (WHERE EXISTS (SELECT 1 FROM shopping_list a WHERE a.owner = s.owner AND a.status = 'ACTIVE')) AS dont_deja_une_liste_active
  FROM shopping_list s WHERE s.status = 'SENT';
SQL

if [[ $mode == lecture ]]; then
	echo "Lecture seule : rien n'a été modifié. Pour appliquer : bash -s -- --appliquer"
	exit 0
fi

echo "== Sauvegarde avant modification"
./backup.sh avant-reouverture-listes

echo "== Remise en état des listes envoyées"
psql_run << 'SQL'
BEGIN;
UPDATE shopping_list s
   SET status = CASE
         WHEN NOT EXISTS (SELECT 1 FROM shopping_list a WHERE a.owner = s.owner AND a.status = 'ACTIVE')
          AND s.id = (SELECT x.id FROM shopping_list x WHERE x.owner = s.owner AND x.status = 'SENT' ORDER BY x.created_at DESC, x.id DESC LIMIT 1)
         THEN 'ACTIVE' ELSE 'PAUSED' END,
       sent_at = NULL,
       version = version + 1
 WHERE s.status = 'SENT';
COMMIT;
SELECT status, count(*) AS listes FROM shopping_list GROUP BY status ORDER BY status;
SQL
echo "OK : plus aucune liste au statut SENT (le tableau ci-dessus le montre)."
