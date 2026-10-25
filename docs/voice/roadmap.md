# Chantier : commande vocale Alexa

Suivi vivant du chantier « Alexa, demande à *mes courses* d'ajouter du pain ». Cadrage du 2026-10-25 ; décision d'architecture : [ADR 0029](../adr/0029-commande-vocale-alexa.md) (`Proposed` jusqu'à VOX-001, amendée le 2026-10-25 : décision 11). **À lire en premier par tout agent qui reprend ce chantier**, dans cet ordre :

1. Ce fichier.
2. [`alexa.md`](alexa.md) : faits vérifiés (avec leurs réserves), architecture, algorithme de choix de l'ingrédient, risques.
3. L'[ADR 0029](../adr/0029-commande-vocale-alexa.md), puis le ticket choisi dans [`tickets/`](tickets/) (autonome, il fait foi).
4. [`docs/drive/consignes-agents.md`](../drive/consignes-agents.md) (règles communes de livraison) et les skills `repas-dev` (back), `repas-front-dev` (front), `repas-android-dev` (application), `repas-po` (tout écran, tout texte, **toute réponse d'Alexa**), `repas-ops` (variables, scripts).
5. Le `CLAUDE.md` du dépôt où l'on travaille.

> **État au 2026-10-25 (soir).** Tout le code est fusionné : back (VOX-101 à 103, 201 à 205, 303, 601), front (VOX-401, 402) ; une phrase signée par Amazon ajoute un article à la liste, et un produit inconnu ouvre un dialogue (trois propositions, mémoire de phrase, création guidée : VOX-303, PR #270 et #271). Rien n'est encore déployé ni allumé. Reste, avec le propriétaire, **en direct** : la mise en production, le compte Amazon (VOX-002), la configuration du serveur, l'allumage et la recette sur l'Echo (VOX-602), menés par l'assistant d'après [`seance-en-direct.md`](seance-en-direct.md). Code qui attend la recette : VOX-301 (corriger un ajout depuis l'application) et VOX-403. Décisions D1, D2, D4 à D6 de l'ADR 0029 en attente (défauts retenus) ; D3 décidée par le propriétaire le 2026-10-25. La liste d'Amazon n'est plus accessible aux tiers : la phrase contient donc le nom du skill.

## Règles du chantier

- **L'interrupteur `ALEXA_ENABLED` est coupé par défaut** ; coupé, `/api/voice/*` et les routes d'autorisation répondent `404` avant tout traitement. Seul l'humain l'allume (script de `repas-ops`).
- **Aucun agent ne contacte Amazon** (ni la console, ni S3 pour les certificats, ni Login with Amazon) : signatures RSA et certificat de test générés dans les tests, faux client HTTP, faux webhook. Le vrai Alexa ne se voit qu'à la recette, par l'utilisateur.
- **Aucun secret dans les dépôts ni dans le chat** : `ALEXA_OAUTH_CLIENT_SECRET` et `ALEXA_SKILL_ID` naissent dans la console Amazon et vont dans `/opt/repas/.env` par un script que l'utilisateur lance (modèle : `ecrire-mailer-env.sh`).
- Le contexte `Voice` ne dépend que de `Shared` (Deptrac, aucune baseline) ; ce qui doit circuler vers `Repas` passe par un port de `Shared`.
- Un agent ne déploie pas, ne crée aucune étiquette, ne touche ni aux secrets GitHub ni au serveur.
- Les réponses d'Alexa suivent `repas-po` : tutoiement, une à deux phrases, aucun avertissement. Seule exception : la question « de quoi tu parles ? » d'un produit inconnu, trois phrases (ADR 0029 décision 11).
- Changer une convention = mettre à jour l'ADR 0029, `CLAUDE.md`, le skill concerné et cette roadmap dans le même commit.

## M0 — Décisions et compte Amazon

- [ ] [VOX-001](tickets/VOX-001-decisions-du-proprietaire.md) assistant · S · Décisions D1 à D6, ADR 0029 `Accepted` · dépend de : —
- [ ] [VOX-002](tickets/VOX-002-compte-amazon-et-skill.md) humain · M · Compte développeur Amazon, création du skill, liaison de compte (avec le paquet de VOX-205) · dépend de : 001, 205

## M1 — Back `Repas` : trouver le bon ingrédient (sans Amazon)

- [x] [VOX-101](tickets/VOX-101-classeur-d-ingredients-parles.md) back · M · Service de domaine pur `SpokenIngredientRanker` + table de cas · dépend de : —
- [x] [VOX-102](tickets/VOX-102-habitudes-et-port-de-resolution.md) back · M · Chargeur des habitudes (une requête), port `Shared\SpokenIngredientResolver` · dépend de : 101
- [x] [VOX-103](tickets/VOX-103-ajouter-a-la-liste-par-la-voix.md) back · M · Port `Shared\VoiceShoppingList` : liste active ou nouvelle liste, ligne ajoutée · dépend de : —

## M2 — Back `Voice` : liaison et webhook

- [x] [VOX-201](tickets/VOX-201-squelette-voice-interrupteur-purge.md) back · M · Contexte `Voice`, interrupteur, Deptrac, migration, purge du compte · dépend de : —
- [x] [VOX-202](tickets/VOX-202-liaison-de-compte-oauth.md) back · L · Serveur d'autorisation : code, jetons hachés, PKCE, `authorize`, `token`, liaisons listées et supprimées · dépend de : 201
- [x] [VOX-203](tickets/VOX-203-verification-des-requetes-alexa.md) back · L · Vérificateur de requêtes Alexa (certificat, signature, horodatage, `applicationId`) · dépend de : 201
- [x] [VOX-204](tickets/VOX-204-webhook-alexa-ajouter-un-article.md) back · L · `POST /api/voice/alexa` : lancement, ajout, aide, arrêt, repli ; réponses françaises · dépend de : 102, 103, 202, 203
- [x] [VOX-205](tickets/VOX-205-paquet-du-skill.md) assistant · S · Manifeste et modèle d'interaction `fr-FR` (types de valeurs d'après le catalogue de base) · dépend de : 204

## M3 — Habitudes qui apprennent (après la première recette)

- [x] [VOX-303](tickets/VOX-303-produit-inconnu-propositions-et-creation.md) back · L · Produit inconnu : trois propositions, mémoire de phrase (`spoken_phrase_choice`), création guidée (rayon, unité, confirmation), état du dialogue dans la séance · dépend de : 204, 205
- [ ] [VOX-301](tickets/VOX-301-corriger-un-ajout-vocal.md) back puis front · M · Origine « voix » des lignes, correction « Pas le bon ? » depuis l'application (la mémoire de phrase existe, VOX-303) · dépend de : 204
- [ ] [VOX-302](tickets/VOX-302-boite-a-trier-et-quantites.md) back puis front · M · Facultatif : produits introuvables mis de côté, quantité dite · dépend de : 301

## M4 — Front web

- [ ] [VOX-401](tickets/VOX-401-page-d-autorisation-alexa.md) front · M · Page `/alexa/lier` (retour après connexion, « Autoriser ») · dépend de : 202 (contrat figé)
- [ ] [VOX-402](tickets/VOX-402-moi-assistant-vocal.md) front · S · « Moi » : assistant vocal (état, phrase à dire, délier) · dépend de : 202
- [ ] [VOX-403](tickets/VOX-403-pas-le-bon-sur-la-liste.md) front · S · « Ajouté par Alexa — pas le bon ? » sur la liste · dépend de : 301

## M5 — Application Android

- [ ] [VOX-501](tickets/VOX-501-liens-d-application-et-page-d-autorisation.md) front (`android/`) · S · L'application ne doit pas intercepter la page d'autorisation · dépend de : 401

## M6 — Mise en service

- [x] [VOX-601](tickets/VOX-601-variables-script-et-runbook.md) back · S · Variables, script d'écriture, `docs/production.md`, runbook · dépend de : 204
- [ ] [VOX-602](tickets/VOX-602-recette-sur-l-echo.md) humain · M · Recette : liaison depuis le téléphone, phrases, listes vide / envoyée, un proche · dépend de : 002, 204, 401, 601

## Chemin critique et parallélisme

- **Critique** : 001 → (201 → 202 ‖ 203) + (101 → 102 ‖ 103) → 204 → 205 → 002 → 601 → 602 ; 401 se développe dès que 202 fige le contrat.
- **En parallèle dès le départ** : 101, 103, 201 ne dépendent de rien. Un seul agent sur le back à la fois (règle des chantiers précédents) : ordre conseillé 101, 103, 102, 201, 203, 202, 204.
- **Première version utilisable** : M0 à M2, M4 (401), M6. M3 vient après la première recette sur l'Echo.

## Ce qui exige l'Echo ou le téléphone

Rien dans les tickets 101 à 204 et 4xx (tests automatiques, faux Amazon). **VOX-602** est le seul qui exige l'Echo, l'application Alexa et un téléphone : c'est elle qui lève les inconnues (URL de redirection réelles, comportement du lien dans l'application Alexa, qualité de la reconnaissance, conflit avec l'application Repas).

## Definition of Ready / Done

- Ready : les dépendances sont fusionnées, les décisions du ticket sont tranchées, il tient dans une PR relisible.
- Done back : `repas-dev` § 4 (`make test`, `make quality`, `make audit` si `composer.*` change, scénario `WriteBudget` pour toute écriture, `make openapi`, purge pour toute colonne `user_id`). Front : `make check`, types générés, textes dans `fr.ts`, captures lues (360 px, clair et sombre). Tous : commits courts en français sans `Co-Authored-By`, PR au gabarit sans pied d'attribution, jamais de push sur `main`, cette roadmap cochée.
