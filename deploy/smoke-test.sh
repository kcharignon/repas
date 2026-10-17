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

echo "== Inscription et connexion (JWT, cookie du SPA)"
email="smoke-$RANDOM@example.com"
password="Un mot de passe 1 solide!"
register_headers=$(curl_app -D - -o /dev/null -X POST -H 'Content-Type: application/json' \
	-d "{\"email\":\"$email\",\"password\":\"$password\",\"defaultServing\":4}" "https://$app:$port/api/v1/users" || true)
check "inscription : 201" grep -q "^HTTP/[0-9.]* 201" <<< "$register_headers"
# Sans cela, tous les visiteurs auraient l adresse de la bordure : plafonds de débit par IP faussés, https non reconnu.
# Le PHP est volontairement dans des guillemets simples.
# shellcheck disable=SC2016
check "Symfony fait confiance à la bordure (proxys privés, en-têtes X-Forwarded-*)" docker compose exec -T php php -r 'require "/app/vendor/autoload.php"; $k = new Repas\Kernel("prod", false); $k->boot(); $r = Symfony\Component\HttpFoundation\Request::getTrustedProxies(); exit(in_array("10.0.0.0/8", $r, true) && (Symfony\Component\HttpFoundation\Request::getTrustedHeaderSet() & Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR) ? 0 : 1);'
# Un compte inscrit attend la confirmation de son adresse (ADR 0020) : la connexion est refusée avec le bon mot de passe.
refused=$(curl_app -w '\n%{http_code}' -X POST -H 'Content-Type: application/json' \
	-d "{\"email\":\"$email\",\"password\":\"$password\"}" "https://$app:$port/api/login_check" || true)
check "connexion avant confirmation de l'adresse : 403 EMAIL_NOT_VERIFIED" bash -c "grep -q EMAIL_NOT_VERIFIED <<< \"\$1\" && tail -n 1 <<< \"\$1\" | grep -q '^403$'" _ "$refused"
# Le message part par `null://null` (MAILER_DSN de l'essai) : on active le compte en base, comme le ferait le lien reçu.
docker compose exec -T database psql -q -U repas -d repas -c "UPDATE \"user\" SET status = 'ACTIVE' WHERE email = '$email'" < /dev/null
headers=$(curl_app -D - -o /dev/null -X POST -H 'Content-Type: application/json' \
	-d "{\"email\":\"$email\",\"password\":\"$password\"}" "https://$app:$port/api/login_check" || true)
check "connexion : 200" grep -q '^HTTP/[0-9.]* 200' <<< "$headers"
check "cookie de rafraîchissement HttpOnly" grep -i -q "^set-cookie: repas_refresh=.*HttpOnly.*" <<< "$headers"
check "cookie Secure" bash -c "grep -i \"^set-cookie: repas_refresh\" <<< \"\$1\" | grep -i -q 'secure'" _ "$headers"
check "cookie SameSite=Strict" bash -c "grep -i \"^set-cookie: repas_refresh\" <<< \"\$1\" | grep -i -q 'samesite=strict'" _ "$headers"

echo "== Images et restes"
check "/images servi par le back" test "$(curl_app -o /dev/null -w '%{http_code}' "https://$app:$port/images/logo.png")" = 200

if [[ -n $front_image ]]; then
	echo "== Déploiement du front"
	check "deploy.sh front v0.0.1" ./deploy.sh front v0.0.1
	check "le SPA est servi sur le domaine de l'application" bash -c "curl -sk --resolve $app:$port:127.0.0.1 https://$app:$port/ | grep -q '<div id=\"app\">'"
	check "repli du SPA sur une route inconnue" bash -c "curl -sk --resolve $app:$port:127.0.0.1 https://$app:$port/recettes/inconnue | grep -q '<div id=\"app\">'"
	check "l'API reste servie par le back" test "$(curl_app -o /dev/null -w '%{http_code}' "https://$app:$port/api/v1/health")" = 200
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
