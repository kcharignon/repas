# VOX-002 — Compte développeur Amazon, création du skill, liaison de compte

- **Dépôt** : — (humain)
- **Jalon** : M0
- **Taille** : M
- **Dépend de** : VOX-001, VOX-205 (le paquet à coller)
- **Faisable sans téléphone** : non (application Alexa)
- **Statut** : à faire
- **Lire d'abord** : [`../alexa.md`](../alexa.md) §§ 1, 6 ; `.claude/skills/repas-ops/SKILL.md` (une étape par message, aucun secret dans le chat).

## Contexte

Tout se fait dans la console Alexa avec **le compte Amazon qui possède l'Echo** : un skill en développement ne fonctionne que sur les appareils de ce compte.

## Portée (guidage pas à pas par l'assistant, une étape par message)

1. Créer (ou ouvrir) le compte sur developer.amazon.com avec le compte Amazon de l'Echo.
2. Console Alexa → *Create Skill* → type *Custom*, langue *French (FR)*, hébergement *Provision your own*.
3. *Build → Invocation* : saisir le nom d'invocation décidé (D1).
4. *JSON Editor* : coller le modèle d'interaction `docs/voice/skill/interaction-model.fr-FR.json` (VOX-205), *Save*, *Build*.
5. *Endpoint* : HTTPS `https://kantin.eu/api/voice/alexa`, certificat « d'une autorité reconnue ».
6. *Account Linking* : *Auth Code Grant* ; URI d'autorisation `https://kantin.eu/alexa/lier` ; URI du jeton `https://kantin.eu/api/voice/oauth/token` ; ID client `repas-alexa` ; **secret généré par le script de VOX-601** (jamais dans le chat) ; portée `voice:shopping_list:write` ; PKCE activé ; relever les **URL de redirection** affichées.
7. Relever l'**ID du skill** (`amzn1.ask.skill.…`) ; les deux valeurs vont sur le serveur par le script de VOX-601.
8. Application Alexa → Skills → *Dev* → activer le skill → *lier le compte*.

## Critères d'acceptation

- Le skill est `Build` sans erreur, l'ID du skill et les URL de redirection sont notés pour VOX-601, la liaison aboutit (VOX-602).

## Pièges connus

- Si la console refuse le nom d'invocation, en proposer un second sans refaire le reste.
- Ne jamais coller le secret dans le chat ni dans un fichier du dépôt.
