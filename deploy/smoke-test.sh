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
# DRIVE_RETAILERS (DRV-702) : absente du .env de cet essai, la valeur par défaut de compose (leclerc, la production d'aujourd'hui) doit gagner sur celle du .env embarqué.
check "les enseignes du drive allumées par défaut ne sont que celles d'aujourd'hui (DRIVE_RETAILERS=leclerc)" test "$(docker compose exec -T php printenv DRIVE_RETAILERS < /dev/null)" = leclerc
# Vide, elle reste vide (aucune enseigne) : `${DRIVE_RETAILERS-leclerc}` ne remplace que l'absence, pas une valeur vide (`:-` le ferait).
# La valeur est lue par `docker compose config`, pas par ce shell : guillemets simples voulus.
# shellcheck disable=SC2016
check "une variable DRIVE_RETAILERS vide n'allume aucune enseigne (elle n'est pas remplacée par la valeur par défaut)" bash -c 'DRIVE_RETAILERS= docker compose config 2>/dev/null | grep -qE "DRIVE_RETAILERS: (\"\")?$"'
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
# Le compte n'a pas encore de mot de passe : la connexion est refusée, quel que soit le mot de passe saisi (ADR 0020 amendé).
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
# L'envoi d'une image (ADR 0039) a sa propre limite de corps, 3 Mio + 256 Kio (l'application refuse le fichier au-delà de 3 Mio, avec un message) ;
# l'envoi par lien est un corps JSON de quelques octets, il garde la limite de 1 Mo.
for size in 3407872 3407873; do make_body "$size"; done
check "un corps de 3 Mio + 256 Kio atteint l'envoi d'image" reaches_app "$(post_code /api/v1/images 3407872)"
check "un corps d'un octet de plus est refusé en 413 (envoi d'image)" test "$(post_code /api/v1/images 3407873)" = 413
check "l'envoi par lien garde la limite de 1 Mo (413 au-delà)" test "$(post_code /api/v1/images/from-url 1000001)" = 413

echo "== Images envoyées par l'administrateur (volume uploads, ADR 0039)"
# Le volume `uploads` est monté sur public/images/uploads : il appartient à l'utilisateur sous lequel tourne le serveur (un volume prend les droits du
# dossier de l'image à son premier montage). Les guillemets simples sont voulus : le shell du conteneur lit les variables.
# shellcheck disable=SC2016
check "le volume des images envoyées appartient à l'utilisateur du serveur" docker compose exec -T php sh -c 'test "$(stat -c %u /app/public/images/uploads)" = "$(awk "/^Uid:/ {print \$2}" /proc/1/status)"'
check "l'extension d'images (GD) sait lire et écrire du WebP et du PNG" docker compose exec -T php php -r 'exit(!empty(gd_info()["WebP Support"]) && !empty(gd_info()["PNG Support"]) && function_exists("imagewebp") && function_exists("imagepng") ? 0 : 1);'
check "l'extension DOM (assainissement des SVG) est présente" docker compose exec -T php php -r 'exit(class_exists("DOMDocument") && class_exists("XMLReader") && class_exists("XMLWriter") ? 0 : 1);'
# Un compte administrateur : le compte d'essai plus haut, promu en base (le rôle ne s'obtient par aucune route publique).
docker compose exec -T database psql -q -U repas -d repas -c "UPDATE \"user\" SET roles = '[\"ROLE_USER\",\"ROLE_ADMIN\"]' WHERE email = '$email'" < /dev/null
admin_token=$(curl_app -X POST -H 'Content-Type: application/json' -d "{\"email\":\"$email\",\"password\":\"$password\"}" "https://$app:$port/api/login_check" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p' || true)
check "le compte d'essai promu administrateur se connecte" test -n "$admin_token"
upload_file() { # upload_file <fichier> [type] : le corps de la réponse de POST /api/v1/images
	curl_app -X POST -H "Authorization: Bearer $admin_token" -F "file=@$1;type=${2:-application/octet-stream}" "https://$app:$port/api/v1/images" || true
}
# La réponse JSON écrit les barres obliques échappées (images\/uploads\/…) : on les rétablit.
path_of() { sed -n 's/.*"path":"\([^"]*\)".*/\1/p' <<< "$1" | sed 's#\\/#/#g'; }
# Une image de 1 pixel (PNG), envoyée à travers toute la pile : bordure, back, GD, volume, puis servie par la bordure. Un PNG reste un PNG.
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' | base64 -d > smoke.png
upload_body=$(upload_file smoke.png image/png)
uploaded_path=$(path_of "$upload_body")
[[ -n $uploaded_path ]] || echo "    (réponse du serveur : ${upload_body:0:300})"
check "l'envoi d'un PNG rend le chemin d'un PNG nommé par son empreinte" bash -c "[[ '$uploaded_path' =~ ^images/uploads/[0-9a-f]{64}\.png\$ ]]"
upload_headers=$(curl_app -D - -o upload.png "https://$app:$port/$uploaded_path" || true)
check "l'image envoyée est servie par la bordure sous /images/uploads (200)" grep -q '^HTTP/[0-9.]* 200' <<< "$upload_headers"
check "elle est servie en image/png" grep -qi '^content-type: image/png' <<< "$upload_headers"
check "avec un cache d'un an sans revalidation" bash -c "grep -i '^cache-control:' <<< \"\$1\" | grep -qi 'max-age=31536000, immutable'" _ "$upload_headers"
check "et nosniff une seule fois" header_is "$upload_headers" x-content-type-options nosniff
check "c'est bien un fichier PNG" bash -c "head -c 4 upload.png | tail -c 3 | grep -q PNG"
second_body=$(upload_file smoke.png image/png)
check "la même image rend le même chemin" test "$(path_of "$second_body")" = "$uploaded_path"
# Un WebP (fabriqué par l'extension GD du conteneur) reste un WebP.
docker compose exec -T php php -r '$i = imagecreatetruecolor(8, 8); imagewebp($i);' < /dev/null > smoke.webp
webp_path=$(path_of "$(upload_file smoke.webp image/webp)")
check "l'envoi d'un WebP rend le chemin d'un WebP" bash -c "[[ '$webp_path' =~ \.webp\$ ]]"
check "il est servi en image/webp" bash -c "curl -sk -D - -o /dev/null --resolve $app:$port:127.0.0.1 https://$app:$port/$webp_path | tr -d '\r' | grep -qi '^content-type: image/webp'"
# Un JPEG est refusé (il ne gère pas la transparence).
docker compose exec -T php php -r '$i = imagecreatetruecolor(8, 8); imagejpeg($i);' < /dev/null > smoke.jpg
jpeg_response=$(curl_app -w '\n%{http_code}' -X POST -H "Authorization: Bearer $admin_token" -F 'file=@smoke.jpg;type=image/jpeg' "https://$app:$port/api/v1/images" || true)
check "un JPEG est refusé en 422 (IMAGE_UNSUPPORTED)" bash -c "tail -n 1 <<< \"\$1\" | grep -q '^422\$' && grep -q IMAGE_UNSUPPORTED <<< \"\$1\"" _ "$jpeg_response"
# Un SVG piégé : il est assaini (jamais gardé tel quel), puis servi avec la politique qui l'empêche de rien exécuter même ouvert directement.
cat > smoke.svg << 'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><rect width="10" height="10" fill="#0a0" onclick="alert(3)"/></svg>
SVG
svg_body=$(upload_file smoke.svg image/svg+xml)
svg_path=$(path_of "$svg_body")
check "l'envoi d'un SVG rend le chemin d'un fichier .svg" bash -c "[[ '$svg_path' =~ ^images/uploads/[0-9a-f]{64}\.svg\$ ]]"
svg_headers=$(curl_app -D - -o served.svg "https://$app:$port/$svg_path" || true)
check "le SVG est servi en image/svg+xml" grep -qi '^content-type: image/svg+xml' <<< "$svg_headers"
check "avec nosniff, une seule fois" header_is "$svg_headers" x-content-type-options nosniff
check "et la politique qui l'empêche de rien exécuter, une seule fois" header_is "$svg_headers" content-security-policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
check "le SVG gardé est assaini : ni script, ni gestionnaire d'événement, mais sa forme" bash -c "! grep -qi -E 'script|onload|onclick|alert' served.svg && grep -q '<rect' served.svg"
printf 'ceci est du texte' > smoke.txt
check "un fichier qui n'est pas une image est refusé en 422" test "$(curl_app -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $admin_token" -F 'file=@smoke.txt;type=image/png' "https://$app:$port/api/v1/images")" = 422
check "un lien vers une adresse IP est refusé en 422, sans aucun appel sortant" test "$(curl_app -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $admin_token" -H 'Content-Type: application/json' -d '{"url":"https://127.0.0.1/a.png"}' "https://$app:$port/api/v1/images/from-url")" = 422
check "sans jeton, l'envoi d'une image est refusé (401)" test "$(curl_app -o /dev/null -w '%{http_code}' -X POST -F 'file=@smoke.png;type=image/png' "https://$app:$port/api/v1/images")" = 401
check "une image inconnue sous /images/uploads est un 404 sans cache d'un an" bash -c "curl -sk -D - -o /dev/null --resolve $app:$port:127.0.0.1 https://$app:$port/images/uploads/inconnue.svg | tr -d '\r' | { head -n 1 | grep -q ' 404' && ! grep -qi 'immutable'; }"
check "les autres images de /images n'ont pas le cache d'un an" bash -c "! curl -sk -D - -o /dev/null --resolve $app:$port:127.0.0.1 https://$app:$port/images/logo.png | grep -qi 'immutable'"

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
# Les images envoyées survivent au déploiement (le conteneur est remplacé, le volume reste) et sont dans la sauvegarde d'avant ce déploiement.
check "l'image envoyée est toujours servie après le déploiement (volume conservé)" test "$(curl_app -o /dev/null -w '%{http_code}' "https://$app:$port/$uploaded_path")" = 200
check "la sauvegarde d'avant le déploiement contient les images envoyées" bash -c "tar tzf backups/uploads-*-avant-v0.0.2.tar.gz | grep -q '${uploaded_path##*/}'"

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
