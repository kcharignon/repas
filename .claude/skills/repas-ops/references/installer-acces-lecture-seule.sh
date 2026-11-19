#!/usr/bin/env bash
# Installe sur le serveur un accès SSH en LECTURE SEULE pour l'assistant (remplace la règle « ssh * root@… », trop large).
#
# Une clé SSH dédiée ne peut lancer qu'UN programme : /usr/local/sbin/repas-lecture-seule, qui n'accepte qu'une liste
# fermée de demandes (état, journal d'un service, disque, drapeaux non secrets, sauvegardes, trois rapports SQL en
# lecture seule, rapport de santé de l'amorçage). Même si la règle de permission du poste est trop large, le serveur refuse
# tout le reste : pas de shell, pas de redirection, pas de transfert de port.
#
# Lancé PAR L'UTILISATEUR, une seule fois (root, écriture sur le serveur) :
#   1. sur le poste : ssh-keygen -t ed25519 -N '' -C repas-lecture-seule -f ~/.ssh/repas_lecture_seule
#   2. ! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 "bash -s -- '$(cat ~/.ssh/repas_lecture_seule.pub)'" < .claude/skills/repas-ops/references/installer-acces-lecture-seule.sh
# Idempotent : relancé, il remplace le programme et ne duplique pas la clé. Retirer l'accès : supprimer la ligne
# « repas-lecture-seule » de /home/deploy/.ssh/authorized_keys.
set -euo pipefail

PUBKEY=${1:-}
[[ $PUBKEY != *$'\n'* && $PUBKEY =~ ^ssh-ed25519\ [A-Za-z0-9+/=]+(\ [^\"\']*)?$ ]] || {
	echo "Clé publique ed25519 attendue en argument (contenu de ~/.ssh/repas_lecture_seule.pub), sur une seule ligne." >&2
	exit 2
}
# Une clé tronquée passerait la forme ci-dessus et rendrait l'accès muet : ssh-keygen la relit vraiment
if command -v ssh-keygen > /dev/null; then
	ssh-keygen -l -f /dev/stdin <<< "$PUBKEY" > /dev/null 2>&1 || {
		echo "Cette clé publique n'est pas valide (ssh-keygen la refuse)." >&2
		exit 2
	}
fi
KEYBODY=$(awk '{print $2}' <<< "$PUBKEY")

id deploy > /dev/null 2>&1 || { echo "L'utilisateur « deploy » n'existe pas sur ce serveur." >&2; exit 2; }

# Le programme appartient à root : le compte « deploy » ne peut pas le modifier.
cat > /usr/local/sbin/repas-lecture-seule.new <<'LECTURE_SEULE'
#!/usr/bin/env bash
# Programme imposé à la clé « repas-lecture-seule » (authorized_keys : command=…). Lit la demande dans SSH_ORIGINAL_COMMAND,
# la découpe sur les espaces SANS l'interpréter (ni eval, ni globbing) et ne connaît que les verbes ci-dessous.
set -euo pipefail
set -f
# Environnement connu : aucun chemin ni script de démarrage hérité de la connexion
export PATH=/usr/local/bin:/usr/bin:/bin
unset BASH_ENV ENV
cd /opt/repas

refuse() { echo "Demande refusée : $*" >&2; exit 2; }
# Une demande tient sur une seule ligne : le reste serait ignoré sans bruit
[[ ${SSH_ORIGINAL_COMMAND:-} != *$'\n'* && ${SSH_ORIGINAL_COMMAND:-} != *$'\r'* ]] || refuse "une seule ligne"

read -r -a ARGS <<< "${SSH_ORIGINAL_COMMAND:-}"
VERB=${ARGS[0]:-aide}

# Chaque demande a un nombre d'arguments fixe : tout ce qui dépasse est refusé, jamais ignoré
arity() { ((${#ARGS[@]} <= $1)) || refuse "trop d'arguments"; }

case $VERB in
	aide)
		arity 1
		cat <<'AIDE'
Demandes permises (lecture seule) :
  etat                       conteneurs, versions déployées, derniers déploiements réussis
  journal SERVICE [N]        N dernières lignes (1 à 500, 100 par défaut) de php | edge | front | database
  disque                     espace disque et mémoire
  drapeau NOM                valeur d'un drapeau non secret : DRIVE_ENABLED INGREDIENT_SEEDING_ENABLED ALEXA_ENABLED APP_LATEST_VERSION APP_MIN_VERSION
  sauvegardes                dernières sauvegardes et fin du journal du cron
  rapport comptes|listes|migrations   comptes par statut, listes par statut, 5 dernières migrations
  rapport conversions|unites          conversions universelles et décomptes des conversions propres, unités et leur mesure (données de référence, aucun contenu d'utilisateur)
  rapport journal                     journal des listes de courses (ADR 0041) : lignes cochées par origine, issues du drive, archives par cause (décomptes seulement)
  graine                     amorçage des ingrédients : rapport de santé (--check, lecture seule)
  conversions                santé des conversions : universelles de référence, conversions détournées, ingrédients et lignes de recettes non convertibles (lecture seule)
AIDE
		;;
	etat)
		arity 1
		docker compose ps
		echo
		grep -E '^(BACK|FRONT)_TAG=' .env || true
		for f in state/ok-back state/ok-front; do
			if [[ -f $f ]]; then
				printf '%s : ' "$f"
				cat "$f"
			fi
		done
		;;
	journal)
		arity 3
		SERVICE=${ARGS[1]:-}
		N=${ARGS[2]:-100}
		[[ $SERVICE =~ ^(php|edge|front|database)$ ]] || refuse "service inconnu (php, edge, front, database)"
		[[ $N =~ ^[0-9]{1,3}$ ]] || refuse "nombre de lignes de 1 à 500"
		# 10# : « 08 » et « 010 » sont des nombres décimaux, pas de l'octal
		N=$((10#$N))
		((N >= 1 && N <= 500)) || refuse "nombre de lignes de 1 à 500"
		docker compose logs --no-color --tail "$N" "$SERVICE" < /dev/null
		;;
	disque)
		arity 1
		df -h /
		free -m
		;;
	drapeau)
		arity 2
		NAME=${ARGS[1]:-}
		[[ $NAME =~ ^(DRIVE_ENABLED|INGREDIENT_SEEDING_ENABLED|ALEXA_ENABLED|APP_LATEST_VERSION|APP_MIN_VERSION)$ ]] \
			|| refuse "drapeau non permis"
		# Un conteneur arrêté n'est pas un drapeau absent : on le dit, au lieu de laisser croire que l'interrupteur est coupé
		docker compose ps --status running --services < /dev/null | grep -qx php || refuse "le conteneur php ne tourne pas"
		printf '%s=' "$NAME"
		docker compose exec -T php printenv "$NAME" < /dev/null || echo '(absent)'
		;;
	sauvegardes)
		arity 1
		ls -lh backups | tail -n 8
		echo
		tail -n 15 backups/cron.log 2> /dev/null || echo '(pas de journal du cron)'
		;;
	rapport)
		arity 2
		case ${ARGS[1]:-} in
			comptes) SQL='SELECT status, count(*) FROM "user" GROUP BY 1 ORDER BY 1' ;;
			listes) SQL='SELECT status, count(*) FROM shopping_list GROUP BY 1 ORDER BY 1' ;;
			migrations) SQL='SELECT version, executed_at FROM doctrine_migration_versions ORDER BY executed_at DESC LIMIT 5' ;;
			# Les conversions universelles (sans ingrédient) sont des données de référence ; le reste n'est que des décomptes
			conversions) SQL="SELECT 'universelle' AS rubrique, start_unit || ' > ' || end_unit || ' x ' || coefficient::text AS valeur FROM conversion WHERE ingredient IS NULL
UNION ALL SELECT 'ingredient vide (texte)', count(*)::text FROM conversion WHERE ingredient = ''
UNION ALL SELECT 'conversions propres', count(*)::text FROM conversion WHERE ingredient IS NOT NULL AND ingredient <> ''
UNION ALL SELECT 'ingredients sans conversion propre', count(*)::text FROM ingredient i WHERE NOT EXISTS (SELECT 1 FROM conversion v WHERE v.ingredient = i.slug)
UNION ALL SELECT 'copies avec moins de conversions que leur original', count(*)::text FROM ingredient o JOIN ingredient c ON c.slug = o.adopted_from WHERE (SELECT count(*) FROM conversion v WHERE v.ingredient = o.slug) < (SELECT count(*) FROM conversion v WHERE v.ingredient = c.slug)
UNION ALL SELECT 'ingredients dont les unites different', count(*)::text FROM ingredient WHERE default_cooking_unit <> default_purchase_unit" ;;
			unites) SQL="SELECT 'unite' AS rubrique, slug || ' ' || coalesce(dimension, '-') || ' ' || coalesce(base_factor::text, '-') AS valeur FROM unit ORDER BY slug" ;;
			# Journal des listes de courses (ADR 0041, docs/listes/journal.md) : des DÉCOMPTES seulement, jamais un identifiant, un nom, une adresse ni un
			# libellé d'article. Le texte ne contient ni guillemet double, ni dollar, ni apostrophe inverse : il vit dans une chaîne entre guillemets doubles.
			journal) SQL="SELECT rubrique, valeur FROM (
SELECT 1 AS o, 'lignes de listes en cours ou envoyees' AS rubrique, count(*)::text AS valeur FROM shopping_list_row
UNION ALL SELECT 2, 'lignes cochees, origine ' || coalesce(checked_by, 'inconnue (avant le journal)'), count(*)::text FROM shopping_list_row WHERE checked GROUP BY checked_by
UNION ALL SELECT 3, 'issue du dernier passage au drive : ' || drive_outcome, count(*)::text FROM shopping_list_row WHERE drive_outcome IS NOT NULL GROUP BY drive_outcome
UNION ALL SELECT 4, 'raison donnee par l agent : ' || drive_outcome || ' ' || drive_reason, count(*)::text FROM shopping_list_row WHERE drive_reason IS NOT NULL GROUP BY drive_outcome, drive_reason
UNION ALL SELECT 5, 'refusees par le drive et encore a acheter (listes non terminees)', count(*)::text FROM shopping_list_row r JOIN shopping_list l ON l.id = r.shopping_list WHERE r.drive_outcome = 'NOT_IN_CART' AND NOT r.checked AND l.status <> 'COMPLETED'
UNION ALL SELECT 5, 'refusees par le drive puis cochees a la main', count(*)::text FROM shopping_list_row WHERE drive_outcome = 'NOT_IN_CART' AND checked_by = 'MANUAL'
UNION ALL SELECT 5, 'au panier alors qu elles etaient deja cochees a la main', count(*)::text FROM shopping_list_row WHERE drive_outcome = 'IN_CART' AND checked_by = 'MANUAL'
UNION ALL SELECT 6, 'dernier passage au drive enregistre', coalesce(max(drive_attempted_at)::text, 'aucun') FROM shopping_list_row
UNION ALL SELECT 7, 'archives, cause ' || cause, count(*)::text FROM shopping_list_archive GROUP BY cause
UNION ALL SELECT 8, 'archives : lignes au total', coalesce(sum(line_count), 0)::text FROM shopping_list_archive
UNION ALL SELECT 8, 'archives qui portent une ligne refusee par le drive', count(*)::text FROM shopping_list_archive a WHERE EXISTS (SELECT 1 FROM jsonb_array_elements(a.lines) AS line WHERE line->>'drive_outcome' = 'NOT_IN_CART')
UNION ALL SELECT 9, 'derniere archive', coalesce(max(archived_at)::text, 'aucune') FROM shopping_list_archive
) AS rapport ORDER BY o, rubrique" ;;
			*) refuse "rapport inconnu (comptes, listes, migrations, conversions, unites, journal)" ;;
		esac
		# Transaction en lecture seule imposée côté serveur, quel que soit le texte de la requête
		docker compose exec -T -e PGOPTIONS='-c default_transaction_read_only=on' database \
			sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -X -v ON_ERROR_STOP=1 -c "$1"' sh "$SQL" < /dev/null
		;;
	graine)
		arity 1
		docker compose exec -T php bin/console repas:ingredients:seed --check < /dev/null
		;;
	conversions)
		arity 1
		# Rapport en lecture seule : ni e-mail, ni identifiant, ni nom d'ingrédient ou de recette (comptes numérotés, référence courte)
		docker compose exec -T php bin/console repas:conversions:check < /dev/null
		;;
	*)
		refuse "« $VERB » n'existe pas (essaie : aide)"
		;;
esac
LECTURE_SEULE

chown root:root /usr/local/sbin/repas-lecture-seule.new
chmod 755 /usr/local/sbin/repas-lecture-seule.new
mv /usr/local/sbin/repas-lecture-seule.new /usr/local/sbin/repas-lecture-seule

# La clé : « restrict » coupe terminal, redirections de port, agent, X11 ; « command » impose le programme.
SSH_DIR=$(getent passwd deploy | cut -d: -f6)/.ssh
AUTH=$SSH_DIR/authorized_keys
install -d -m 700 -o deploy -g deploy "$SSH_DIR"
touch "$AUTH"
chown deploy:deploy "$AUTH"
chmod 600 "$AUTH"
# Retire toute ancienne ligne de cette clé (rejouable), ajoute la nouvelle. grep rend 1 quand il ne reste aucune ligne (normal), 2 sur
# une vraie erreur (disque plein, droits) : dans ce cas on s'arrête avant de remplacer authorized_keys, qui porte aussi la clé de la CI.
rc=0
grep -vF " repas-lecture-seule" "$AUTH" > "$AUTH.tmp" || rc=$?
((rc <= 1)) || { rm -f "$AUTH.tmp"; echo "ECHEC : lecture de $AUTH impossible, rien n'est modifié." >&2; exit 1; }
printf 'restrict,command="/usr/local/sbin/repas-lecture-seule" ssh-ed25519 %s repas-lecture-seule\n' "$KEYBODY" >> "$AUTH.tmp"
chown deploy:deploy "$AUTH.tmp"
chmod 600 "$AUTH.tmp"
mv "$AUTH.tmp" "$AUTH"

echo "OK : accès en lecture seule installé pour deploy@ (programme /usr/local/sbin/repas-lecture-seule)."
echo "Essai depuis le poste : ssh -i ~/.ssh/repas_lecture_seule -o BatchMode=yes -o IdentitiesOnly=yes deploy@64.227.124.100 aide"
