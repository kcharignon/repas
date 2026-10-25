# Commande vocale Alexa : mise en service, allumage, retour arrière

Décision : [ADR 0029](../../../../docs/adr/0029-commande-vocale-alexa.md). Analyse et tickets : `docs/voice/`. Ici : ce que l'on fait **sur le serveur**. La console Amazon (skill, liaison de compte) est le ticket VOX-002 ; la recette sur l'Echo, VOX-602.

**Règles** : une étape par message, le but en une phrase, attendre le résultat (l'utilisateur n'est pas devops, voir `feedback-devops-guidage-pas-a-pas`). **Aucun secret dans le chat.** Fonction coupée par défaut ; seul l'utilisateur l'allume.

## Les variables (`/opt/repas/.env`)

| Variable | Valeur | Qui l'écrit |
|---|---|---|
| `ALEXA_ENABLED` | `true` / `false` (absente = `false`) | `ecrire-alexa-enabled.sh` |
| `ALEXA_SKILL_ID` | `amzn1.ask.skill.…` (console Amazon) : les requêtes d'un autre skill sont refusées | `ecrire-alexa-env.sh` |
| `ALEXA_REDIRECT_URIS` | les adresses de retour affichées par la console, séparées par des virgules, comparées exactement | `ecrire-alexa-env.sh` |
| `ALEXA_OAUTH_CLIENT_ID` | `repas-alexa` (le même que dans la console) | `ecrire-alexa-env.sh` |
| `ALEXA_OAUTH_CLIENT_SECRET_HASH` | SHA-256 hexadécimal du secret du client ; **le secret n'est écrit nulle part** | `ecrire-alexa-env.sh` |

`deploy/compose.yaml` les liste avec leur défaut : sans cela, le `.env` embarqué dans l'image s'appliquerait en silence.

## A. Mettre en place (une fois, avant le premier allumage)

Prérequis : le back de la version qui contient la commande vocale est déployé et stable (la migration `voice_*` est appliquée) ; le skill existe dans la console Amazon (VOX-002) et l'utilisateur en a relevé l'identifiant et les adresses de retour.

1. **Fabriquer le secret** sur le poste de l'utilisateur, dans son terminal : `openssl rand -base64 36 | tr -d '\n=+/'`. Il le colle dans la console Amazon (*Build → Account Linking → Client Secret*), avec l'identifiant de client `repas-alexa`. Il ne me le montre jamais.
2. **Écrire la configuration** (DANS SON PROPRE TERMINAL, pas avec `!` : la saisie du secret ne doit pas atterrir dans la conversation) :
   `ssh -t -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s' < .claude/skills/repas-ops/references/ecrire-alexa-env.sh`
   Le script demande l'identifiant du skill, les adresses de retour, puis le secret (sans écho, deux fois), n'écrit que l'**empreinte**, garde une copie `.env.avant-alexa` et ne touche pas à l'interrupteur. Attendu : `Écrit dans /opt/repas/.env … Empreinte du secret : xxxxxxxx…`.
3. **Contrôle en lecture seule** (par moi) : `docker compose exec -T php printenv ALEXA_ENABLED` répond `false`, `/api/v1/health` répond `200`, et `POST https://kantin.eu/api/voice/alexa` répond `404` (fonction coupée).

## B. Allumer (décision de l'utilisateur)

`! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-alexa-enabled.sh`
Attendu : `OK : ALEXA_ENABLED=true, le conteneur php le voit et /api/v1/health répond.` Le site coupe quelques secondes. Le script **refuse** d'allumer si la configuration est incomplète.
Puis la recette VOX-602 avec lui, un scénario par message.

## C. Couper (retour arrière, sans perte)

`! ssh … 'bash -s -- false' < .claude/skills/repas-ops/references/ecrire-alexa-enabled.sh`
Coupé : le webhook, l'autorisation et l'échange des jetons répondent `404 VOICE_DISABLED`. **Les liaisons de compte restent** : rallumer suffit, personne n'a à relier Alexa. Retirer une liaison d'un utilisateur : lui-même, dans « Moi », ou la suppression de son compte (tout part avec lui).

## D. Regarder si ça se passe mal (lecture seule)

- `docker compose logs --tail 100 php | grep -i "voice\|alexa"` : les refus de requêtes Amazon écrivent un seul message `warning` avec une **catégorie** (`configuration`, `body`, `signature`, `chain_url`, `request`, `timestamp`, `application`, `chain`, `certificate`), jamais le corps ni la signature. `configuration` = `ALEXA_SKILL_ID` vide.
- La liaison échoue : vérifier que les adresses de retour du `.env` sont **exactement** celles de la console (sans espace, `https://`) ; `invalid_client` = secret ou identifiant de client différents entre la console et le `.env`.
- Recréer le secret : refaire A.1 et A.2 (nouveau secret dans la console **et** ici) ; les jetons déjà émis restent valables.

## E. Ce que la fonction ne fait pas

Aucune donnée vocale n'est conservée (ni audio, ni transcription complète) ; l'identifiant d'utilisateur d'Alexa reste dans `voice_link`. Les tables `voice_*` partent avec le compte (`PurgeUserVoiceData`).
