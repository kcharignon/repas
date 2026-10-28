#!/usr/bin/env bash
# Essai de bout en bout de la configuration de production, en local ou en CI : `make deploy-smoke`.
# Démarre deploy/compose.yaml avec de faux secrets et des certificats internes, puis vérifie ce que la production exige :
# démarrage sur base vide (migrations), clés JWT, bordure, API, redirection de l'ancien nom, cookie du SPA, déploiement et retour arrière.
#
# Variables : BACK_IMAGE (obligatoire, image du back déjà construite), FRONT_IMAGE (facultative : sans elle, le SPA n'est pas essayé),
# SMOKE_HTTPS_PORT (défaut 9443), SMOKE_KEEP=1 pour laisser la pile démarrée.
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
back_image=${BACK_IMAGE:?BACK_IMAGE : image du back à essayer (ex. repas-smoke-back)}
front_image=${FRONT_IMAGE:-}
port=${SMOKE_HTTPS_PORT:-9443}
legacy=legacy.localhost
app=app.localhost

work=$(mktemp -d)
export COMPOSE_PROJECT_NAME=repas-smoke
# Ne jamais nettoyer les images de la machine qui fait l essai.
export DEPLOY_PRUNE=0
cp "$root"/deploy/{compose.yaml,Caddyfile,deploy.sh,backup.sh} "$work"/
chmod +x "$work"/deploy.sh "$work"/backup.sh
cd "$work"

# Les images locales n'ont pas de dépôt distant : on les désigne par leur nom et on leur donne une version.
back_repo=${back_image%%:*}
docker tag "$back_image" "$back_repo:v0.0.1"
docker tag "$back_image" "$back_repo:v0.0.2"
if [[ -n $front_image ]]; then
	front_repo=${front_image%%:*}
	docker tag "$front_image" "$front_repo:v0.0.1"
fi

cat > .env << EOF
LEGACY_HOST=$legacy
APP_HOST=$app
ACME_EMAIL=smoke@example.com
CADDY_GLOBAL_OPTIONS=local_certs
HTTP_PORT=$((port - 1))
HTTPS_PORT=$port
BACK_IMAGE=$back_repo
FRONT_IMAGE=${front_repo:-unused}
BACK_TAG=
FRONT_TAG=
APP_SECRET=$(openssl rand -hex 16)
JWT_PASSPHRASE=$(openssl rand -hex 16)
MERCURE_JWT_SECRET=$(openssl rand -hex 16)
MAILER_DSN=null://null
MAILER_FROM="Repas <no-reply@smoke.localhost>"
POSTGRES_DB=repas
POSTGRES_USER=repas
POSTGRES_PASSWORD=$(openssl rand -hex 12)
EOF
chmod 600 .env

failures=0
ok() { echo "  ok    $*"; }
ko() {
	echo "  ECHEC $*"
	failures=$((failures + 1))
}
check() { # check "libellé" commande…
	local label=$1
	shift
	if "$@" > /dev/null 2>&1; then ok "$label"; else ko "$label"; fi
}
curl_app() { curl -sk --max-time 20 --resolve "$app:$port:127.0.0.1" "$@"; }
curl_legacy() { curl -sk --max-time 20 --resolve "$legacy:$port:127.0.0.1" "$@"; }

cleanup() {
	if [[ ${SMOKE_KEEP:-0} != 1 ]]; then
		docker compose --profile front down -v --remove-orphans > /dev/null 2>&1 || true
		rm -rf "$work"
	else
		echo "Pile laissée démarrée dans $work (COMPOSE_PROJECT_NAME=repas-smoke)"
	fi
}
trap cleanup EXIT

echo "== Premier déploiement du back (base vide)"
check "deploy.sh back v0.0.1" ./deploy.sh back v0.0.1
check "l'API répond par la bordure" test "$(curl_app -o /dev/null -w '%{http_code}' "https://$app:$port/api/v1/health")" = 200
check "l'ancien nom redirige durablement vers le site, chemin conservé" bash -c "curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' --resolve $legacy:$port:127.0.0.1 'https://$legacy:$port/recettes?x=1' | grep -q '^301 https://$app/recettes?x=1$'"
# Le back lui-même (sans la bordure, où /login appartient au front) ne connaît plus que /api.
check "le back ne sert plus de page web (404 sur /login)" test "$(docker compose exec -T php curl -s -o /dev/null -w '%{http_code}' http://localhost/login < /dev/null)" = 404
check "le profileur est coupé en production (pas d'en-tête X-Debug-Token)" bash -c "! curl -sk -D - -o /dev/null --resolve $app:$port:127.0.0.1 https://$app:$port/api/v1/health | grep -qi x-debug-token"
check "les clés JWT existent dans le volume" docker compose exec -T php test -f /app/config/jwt/private.pem
# DRIVE_ENABLED n'est pas dans le .env de cet essai : la valeur par défaut de compose (false) doit gagner sur celle du .env embarqué dans l'image.
check "l'envoi de la liste au drive est coupé par défaut (DRIVE_ENABLED=false)" test "$(docker compose exec -T php printenv DRIVE_ENABLED < /dev/null)" = false
# Même chose pour l'amorçage des ingrédients à l'activation (ING-008) : absente du .env de cet essai, la valeur par défaut de compose (false) doit gagner.
check "l'amorçage des ingrédients à l'activation est coupé par défaut (INGREDIENT_SEEDING_ENABLED=false)" test "$(docker compose exec -T php printenv INGREDIENT_SEEDING_ENABLED < /dev/null)" = false
# Ni APP_LATEST_VERSION ni APP_MIN_VERSION dans le .env de cet essai : compose les transmet vides (DRV-637), personne n'est invité à mettre à jour.
# Le shell du conteneur doit lire les variables : guillemets simples voulus.
# shellcheck disable=SC2016
check "aucune invitation à mettre à jour par défaut (APP_LATEST_VERSION et APP_MIN_VERSION vides)" docker compose exec -T php sh -c 'test "${APP_LATEST_VERSION-absente}" = "" && test "${APP_MIN_VERSION-absente}" = ""'
# La commande vocale Alexa (ADR 0029) : aucune des cinq variables n'est dans le .env de cet essai, les valeurs par défaut de compose doivent
# gagner sur celles du .env embarqué dans l'image — coupée, et aucune valeur propre au déploiement (skill, empreinte du secret, adresses de retour).
check "la commande vocale Alexa est coupée par défaut (ALEXA_ENABLED=false)" test "$(docker compose exec -T php printenv ALEXA_ENABLED < /dev/null)" = false
# Le shell du conteneur doit lire les variables : guillemets simples voulus.
# shellcheck disable=SC2016
check "aucune valeur propre au déploiement par défaut (ALEXA_SKILL_ID, ALEXA_OAUTH_CLIENT_SECRET_HASH et ALEXA_REDIRECT_URIS vides, client repas-alexa)" docker compose exec -T php sh -c 'test "${ALEXA_SKILL_ID-absente}" = "" && test "${ALEXA_OAUTH_CLIENT_SECRET_HASH-absente}" = "" && test "${ALEXA_REDIRECT_URIS-absente}" = "" && test "${ALEXA_OAUTH_CLIENT_ID-absente}" = "repas-alexa"'

echo "== Inscription et connexion (JWT, cookie du SPA)"
email="smoke-$RANDOM@example.com"
password="Un mot de passe 1 solide!"
# Inscription par la seule adresse (ADR 0020, amendement du 2026-10-28) : 204, sans mot de passe. Celui qu'envoie l'ancien formulaire est ignoré.
register_headers=$(curl_app -D - -o /dev/null -X POST -H 'Content-Type: application/json' \
	-d "{\"email\":\"$email\",\"password\":\"un-ancien-mot-de-passe\",\"defaultServing\":4}" "https://$app:$port/api/v1/users" || true)
check "inscription par l'adresse seule : 204" grep -q "^HTTP/[0-9.]* 204" <<< "$register_headers"
# Sans cela, tous les visiteurs auraient l adresse de la bordure : plafonds de débit par IP faussés, https non reconnu.
# Le PHP est volontairement dans des guillemets simples.
# shellcheck disable=SC2016
check "Symfony fait confiance à la bordure (proxys privés, en-têtes X-Forwarded-*)" docker compose exec -T php php -r 'require "/app/vendor/autoload.php"; $k = new Repas\Kernel("prod", false); $k->boot(); $r = Symfony\Component\HttpFoundation\Request::getTrustedProxies(); exit(in_array("10.0.0.0/8", $r, true) && (Symfony\Component\HttpFoundation\Request::getTrustedHeaderSet() & Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR) ? 0 : 1);'
# Le compte n'a pas de mot de passe : ni celui de l'ancien formulaire ni aucun autre n'ouvre la connexion (ADR 0020 amendé).
refused=$(curl_app -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' \
	-d "{\"email\":\"$email\",\"password\":\"un-ancien-mot-de-passe\"}" "https://$app:$port/api/login_check" || true)
check "connexion avant le choix du mot de passe : 401" bash -c "tail -n 1 <<< \"\$1\" | grep -q '^401$'" _ "$refused"
# Le message part par `null://null` (MAILER_DSN de l'essai) : on pose en base le lien de bienvenue, comme le ferait l'inscription, et on choisit le mot de passe avec.
secret=$(openssl rand -hex 32)
secret_hash=$(printf '%s' "$secret" | sha256sum | cut -d' ' -f1)
docker compose exec -T database psql -q -U repas -d repas -c "INSERT INTO password_reset_token (id, user_id, token_hash, issued_at, expires_at) SELECT gen_random_uuid()::text, id, '$secret_hash', NOW(), NOW() + INTERVAL '24 hours' FROM \"user\" WHERE email = '$email'" < /dev/null
chosen=$(curl_app -D - -o /dev/null -X POST -H 'Content-Type: application/json' \
	-d "{\"token\":\"$secret\",\"password\":\"$password\"}" "https://$app:$port/api/v1/users/password-reset/confirm" || true)
check "choix du mot de passe avec le lien de bienvenue : 204" grep -q '^HTTP/[0-9.]* 204' <<< "$chosen"
headers=$(curl_app -D - -o /dev/null -X POST -H 'Content-Type: application/json' \
	-d "{\"email\":\"$email\",\"password\":\"$password\"}" "https://$app:$port/api/login_check" || true)
check "connexion : 200" grep -q '^HTTP/[0-9.]* 200' <<< "$headers"
check "cookie de rafraîchissement HttpOnly" grep -i -q "^set-cookie: repas_refresh=.*HttpOnly.*" <<< "$headers"
check "cookie Secure" bash -c "grep -i \"^set-cookie: repas_refresh\" <<< \"\$1\" | grep -i -q 'secure'" _ "$headers"
check "cookie SameSite=Strict" bash -c "grep -i \"^set-cookie: repas_refresh\" <<< \"\$1\" | grep -i -q 'samesite=strict'" _ "$headers"

echo "== Images et restes"
check "/images servi par le back" test "$(curl_app -o /dev/null -w '%{http_code}' "https://$app:$port/images/logo.png")" = 200

echo "== Bordure : en-têtes communs, origine autorisée, taille et durée de lecture des corps"
header_once() { # header_once "<en-têtes>" nom : l'en-tête figure une seule fois (jamais doublé entre la bordure et le conteneur relayé)
	[[ $(grep -ci "^$2:" <<< "$1") -eq 1 ]]
}
header_is() { # header_is "<en-têtes>" nom valeur : l'en-tête figure une seule fois, avec exactement cette valeur
	header_once "$1" "$2" && [[ $(grep -i "^$2:" <<< "$1" | sed -e 's/^[^:]*: *//' -e 's/\r$//') == "$3" ]]
}
edge_headers() { # edge_headers "<en-têtes>" : les quatre en-têtes communs de la bordure, chacun une seule fois
	header_is "$1" strict-transport-security "max-age=31536000" && header_is "$1" x-content-type-options nosniff \
		&& header_is "$1" referrer-policy strict-origin-when-cross-origin && header_once "$1" permissions-policy
}
api_headers=$(curl_app -D - -o /dev/null "https://$app:$port/api/v1/health" || true)
image_headers=$(curl_app -D - -o /dev/null "https://$app:$port/images/logo.png" || true)
legacy_headers=$(curl_legacy -D - -o /dev/null "https://$legacy:$port/recettes" || true)
check "en-têtes de la bordure sur l'API : HSTS d'un an sans includeSubDomains ni preload, nosniff, Referrer-Policy, Permissions-Policy, chacun une fois" edge_headers "$api_headers"
check "mêmes en-têtes sur /images" edge_headers "$image_headers"
check "mêmes en-têtes sur la redirection de l'ancien nom" edge_headers "$legacy_headers"
# L'expression de CORS_ALLOW_ORIGIN cite le nom de domaine tel quel : ses points ne valent pas « n'importe quel caractère ».
check "origine du site autorisée (CORS)" bash -c "curl -sk -D - -o /dev/null --resolve $app:$port:127.0.0.1 -H 'Origin: https://$app' https://$app:$port/api/v1/health | tr -d '\r' | grep -qi '^access-control-allow-origin: https://$app\$'"
check "origine au nom voisin refusée (CORS, points non joker)" bash -c "! curl -sk -D - -o /dev/null --resolve $app:$port:127.0.0.1 -H 'Origin: https://${app//./-}' https://$app:$port/api/v1/health | grep -qi '^access-control-allow-origin:'"
check "origine en http refusée (CORS, expression ancrée)" bash -c "! curl -sk -D - -o /dev/null --resolve $app:$port:127.0.0.1 -H 'Origin: http://$app' https://$app:$port/api/v1/health | grep -qi '^access-control-allow-origin:'"

# Corps de requête : 1 Mo partout, 1 Mio + 1 octet pour le webhook d'Alexa (son contrôleur lit autant avant de refuser le reste).
make_body() { head -c "$1" /dev/zero | tr '\0' 'a' > "body-$1"; }
post_code() { # post_code <chemin> <taille> [options curl…] : code HTTP du POST d'un corps de cette taille (000 si la connexion tombe)
	local path=$1 size=$2
	shift 2
	curl_app -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data-binary "@body-$size" "$@" "https://$app:$port$path" || true
}
reaches_app() { # reaches_app <code> : une réponse de l'application (4xx), pas un refus de la bordure
	[[ $1 =~ ^4[0-9][0-9]$ && $1 != 413 ]]
}
for size in 1000000 1000001 1048577 1048578; do make_body "$size"; done
check "un corps de 1 000 000 octets atteint l'API" reaches_app "$(post_code /api/login_check 1000000)"
check "un corps de 1 000 001 octets est refusé en 413 (connexion)" test "$(post_code /api/login_check 1000001)" = 413
check "un corps de 1 000 001 octets est refusé en 413 (inscription)" test "$(post_code /api/v1/users 1000001)" = 413
check "un corps envoyé en morceaux de 1 000 001 octets est refusé en 413" test "$(post_code /api/login_check 1000001 --http1.1 -H 'Transfer-Encoding: chunked')" = 413
check "un corps de 1 Mio + 1 octet atteint le webhook d'Alexa" reaches_app "$(post_code /api/voice/alexa 1048577)"
check "un corps de 1 Mio + 1 octet en morceaux atteint le webhook d'Alexa" reaches_app "$(post_code /api/voice/alexa 1048577 --http1.1 -H 'Transfer-Encoding: chunked')"
check "un corps de 1 Mio + 2 octets est refusé en 413 (webhook d'Alexa)" test "$(post_code /api/voice/alexa 1048578)" = 413

# Délais de lecture : réglage lu dans la configuration vivante de la bordure, puis essai réel (une requête dont l'en-tête n'arrive
# pas, un corps qui arrive au compte-gouttes), les deux en parallèle pour ne pas allonger l'essai.
servers_config=$(docker compose exec -T edge wget -qO- http://127.0.0.1:2019/config/apps/http/servers/ < /dev/null || true)
check "délai de lecture de l'en-tête : 10 s" grep -q '"read_header_timeout":10000000000' <<< "$servers_config"
check "délai de lecture du corps : 30 s" grep -q '"read_timeout":30000000000' <<< "$servers_config"
head -c 4000 /dev/zero | tr '\0' 'a' > body-slow
(
	start=$SECONDS
	openssl s_client -quiet -connect "127.0.0.1:$port" -servername "$app" < <(printf 'GET /api/v1/health HTTP/1.1\r\nHost: %s\r\n' "$app"; sleep 25) > /dev/null 2>&1 || true
	echo $((SECONDS - start)) > slow-header.seconds
) &
(
	# 4 000 octets à 80 octets par seconde dureraient 50 s : la bordure coupe à 30 s.
	start=$SECONDS
	curl_app --limit-rate 80 --max-time 60 -o /dev/null -X POST -H 'Content-Type: application/json' --data-binary @body-slow "https://$app:$port/api/login_check" || true
	echo $((SECONDS - start)) > slow-body.seconds
) &
wait
check "un en-tête qui n'arrive pas est abandonné avant 20 s" test "$(cat slow-header.seconds)" -lt 20
check "un corps qui arrive au compte-gouttes est abandonné avant 45 s" test "$(cat slow-body.seconds)" -lt 45

if [[ -n $front_image ]]; then
	echo "== Déploiement du front"
	check "deploy.sh front v0.0.1" ./deploy.sh front v0.0.1
	check "le SPA est servi sur le domaine de l'application" bash -c "curl -sk --resolve $app:$port:127.0.0.1 https://$app:$port/ | grep -q '<div id=\"app\">'"
	check "repli du SPA sur une route inconnue" bash -c "curl -sk --resolve $app:$port:127.0.0.1 https://$app:$port/recettes/inconnue | grep -q '<div id=\"app\">'"
	check "l'API reste servie par le back" test "$(curl_app -o /dev/null -w '%{http_code}' "https://$app:$port/api/v1/health")" = 200
	# Le conteneur du front pose ses propres en-têtes : la bordure y ajoute seulement HSTS, sans rien doubler.
	front_headers=$(curl_app -D - -o /dev/null "https://$app:$port/" || true)
	check "mêmes en-têtes sur le SPA, chacun une seule fois (ceux du conteneur du front ne sont pas doublés)" edge_headers "$front_headers"
	check "la politique de contenu du front est posée une seule fois" header_once "$front_headers" content-security-policy
	check "mêmes en-têtes sur une route du SPA" edge_headers "$(curl_app -D - -o /dev/null "https://$app:$port/recettes/inconnue" || true)"
fi

echo "== Nouvelle version du back (sauvegarde puis déploiement)"
check "deploy.sh back v0.0.2" ./deploy.sh back v0.0.2
check "une sauvegarde lisible a été créée" bash -c 'ls backups/repas-*-avant-v0.0.2.dump'
check "BACK_TAG vaut v0.0.2" grep -q '^BACK_TAG=v0.0.2$' .env

echo "== Retour arrière automatique (version qui ne démarre pas)"
# Une version qui ne démarre pas : même image, mais sa commande échoue aussitôt (le conteneur ne devient jamais sain).
docker build -q -t "$back_repo:v0.0.3" - > /dev/null << EOF
FROM $back_image
CMD ["false"]
EOF
if ./deploy.sh back v0.0.3 > deploy-rollback.log 2>&1; then
	ko "le déploiement cassé aurait dû échouer"
else
	ok "le déploiement cassé échoue"
fi
check "BACK_TAG est revenu à v0.0.2" grep -q '^BACK_TAG=v0.0.2$' .env
# shellcheck disable=SC2016
check "le site répond après le retour arrière" bash -c 'for i in $(seq 1 30); do docker compose exec -T edge wget -q -O - http://php/api/v1/health >/dev/null 2>&1 && exit 0; sleep 2; done; exit 1'

echo
if [[ $failures -gt 0 ]]; then
	echo "$failures vérification(s) en échec."
	docker compose --profile front logs --tail 30 php edge 2>&1 | tail -60 || true
	exit 1
fi
echo "Toutes les vérifications passent."
