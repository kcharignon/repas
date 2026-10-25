# 0029 — Commande vocale Alexa : un skill personnalisé, un contexte `Voice`, la liaison de compte OAuth, l'ingrédient le plus probable d'après les habitudes

- **Statut** : Proposed
- **Date** : 2026-10-25
- **Liens** : analyse [`docs/voice/alexa.md`](../voice/alexa.md), tickets [`docs/voice/roadmap.md`](../voice/roadmap.md) ; [0004](0004-repository-vs-loader.md) ; [0007](0007-api-rest-v1.md) §14 ; [0008](0008-auth-session-et-jwt.md) ; [0013](0013-agregats-references-par-identifiant.md) règle 8 ; [0019](0019-recherche-d-ingredients-par-pertinence.md) ; [0021](0021-gestion-des-comptes-par-l-administrateur.md) §10 ; [0023](0023-liste-envoyee-au-drive.md) ; [0028](0028-ingredients-propres-a-chaque-utilisateur.md) ; [0017](0017-deploiement-et-releases.md) règle 6

## Contexte

L'auteur veut dire « Alexa, ajoute du pain à la liste » et voir *pain* arriver dans sa liste de courses Repas : le bon ingrédient (le plus probable, selon ses habitudes : « tomate » veut dire la tomate fraîche, pas la conserve), dans la liste en cours, qu'on crée si elle n'existe pas.

Faits vérifiés le 2026-10-25 (sources et réserves dans l'analyse) :

- **La liste de courses native d'Alexa n'est plus accessible aux tiers** : l'API de gestion des listes et les événements de liste sont retirés depuis le 1er juillet 2024 ; le service IFTTT d'Alexa est fermé depuis le 31 octobre 2023. Dire « ajoute du pain à *ma liste de courses* » remplit la liste d'Amazon, que Repas ne peut ni lire ni recevoir.
- **Seul un skill personnalisé** (nom d'invocation, intention, modèle d'interaction) peut recevoir une phrase d'Alexa et la transmettre à Repas. La phrase prononcée contient donc le nom du skill : « Alexa, demande à *<nom>* d'ajouter du pain ».
- Un skill peut être servi par un **point d'accès HTTPS** (port 443, certificat d'une autorité reconnue, requêtes signées par Amazon que le service doit vérifier, horodatage à ±150 s). Un skill en *développement* n'a pas besoin de certification et fonctionne sur les appareils du compte Amazon du développeur.
- La **liaison de compte** (quel utilisateur Repas parle ?) se fait par OAuth 2.0 *authorization code* ; Alexa échange le code contre un jeton d'accès et un jeton de rafraîchissement, et envoie le jeton d'accès dans chaque requête.
- Le back est un contexte par métier (`Repas`, `User`, `Drive`, `Shared`) ; rien ne circule entre contextes que par `Shared` ([0013](0013-agregats-references-par-identifiant.md) règle 8, Deptrac sans baseline).

## Décision

1. **Un skill Alexa personnalisé, en français (`fr-FR`), hébergé par le back** : `POST /api/voice/alexa` sur `kantin.eu`. Pas de fonction Lambda, pas de code chez Amazon : le skill n'est qu'un manifeste et un modèle d'interaction (versionnés dans le dépôt, collés dans la console par l'utilisateur).
2. **Un nouveau contexte `Voice`** (`src/Voice/`, même structure en trois couches) : protocole Alexa, vérification des requêtes, liaison de compte, textes des réponses. Il ne dépend que de `Shared` ; il parle à `Repas` par deux ports de `Shared` : `SpokenIngredientResolver` (phrase → ingrédient le plus probable de l'utilisateur) et `VoiceShoppingList` (ajouter à la liste en cours, la créer si besoin).
3. **Le choix de l'ingrédient vit dans `Repas`** : service de domaine pur `SpokenIngredientRanker`. Il part des ingrédients **de l'utilisateur seulement** (`IngredientVisibility::visibleBy`, ADR 0028), note chacun par `SearchRelevance` (pertinence, seuil 0,85, pluriels repliés comme `IngredientNameMatcher::tokens`) puis départage par les **habitudes** : nombre de fois où l'ingrédient est dans les listes et les recettes de l'utilisateur, sur douze mois, et **mémoire de phrase** (« tomate » → *tomate* parce qu'il l'a confirmé ou corrigé). Une mémoire de phrase l'emporte sur tout ; ensuite un nom identique à la phrase passe avant tout nom seulement proche (« tomate » donne *tomate*, même si *tomates pelées* est acheté bien plus souvent) ; entre noms de même rang, `pertinence + 0,1 × habitude` (habitude = `n / (n + 3)`, de 0 à 1). Les constantes sont figées par une table de cas réels et ne se changent que sur relecture.
4. **Une phrase sans ingrédient plausible n'invente rien** : Alexa répond qu'elle ne trouve pas le produit. Aucun ingrédient n'est créé par la voix.
5. **La liste** : la liste `ACTIVE` reçoit la ligne (une unité d'achat, comme `AddIngredientToShoppingList`). Sans liste `ACTIVE` (aucune, ou envoyée au drive : [0023](0023-liste-envoyee-au-drive.md)), une nouvelle est créée par `EnsureActiveShoppingList` ; Alexa le dit en une phrase. Une liste `SENT` n'est jamais modifiée.
6. **Liaison de compte OAuth 2.0 *authorization code* avec PKCE S256**, serveur d'autorisation tenu par Repas : la page d'autorisation est une page du front (`/alexa/lier`, connexion puis un bouton « Autoriser »), le code est émis par `POST /api/v1/voice/alexa/authorize` (JWT de l'utilisateur), l'échange est `POST /api/voice/oauth/token` (public, client authentifié par `ALEXA_OAUTH_CLIENT_ID` / secret). Les jetons sont **opaques, de portée unique** (`voice:shopping_list:write`), stockés **hachés** (SHA-256) ; ils n'ouvrent *rien* d'autre que le webhook (le pare-feu JWT ne les connaît pas). Un utilisateur voit ses liaisons et les supprime (`GET`/`DELETE /api/v1/voice/links`).
7. **Chaque requête du webhook est vérifiée avant tout traitement** : chaîne de certificats (`s3.amazonaws.com/echo.api/`, SAN `echo-api.amazon.com`), signature SHA-256, horodatage à ±150 s, `applicationId` égal à `ALEXA_SKILL_ID`. Échec : `400`, rien d'autre. Deux plafonds : 60 requêtes par minute et par adresse IP, posé AVANT la vérification (chaque adresse de chaîne inventée coûte un téléchargement de certificat ; dépassement : `429`) ; 60 par minute et par liaison, posé une fois le jeton résolu (dépassement : réponse vocale « un instant », jamais d'exception visible). Une fois la requête authentique, la réponse est toujours `200` avec une réponse vocale.
8. **Interrupteur `ALEXA_ENABLED`**, coupé par défaut, allumé par l'humain (script de `repas-ops`) : coupé, le webhook, l'autorisation et l'échange répondent `404` avant tout traitement. Un compte qui n'est pas actif ne peut rien par la voix ([0021](0021-gestion-des-comptes-par-l-administrateur.md)).
9. **Purge** : les tables `voice_*` portent `user_id` et partent avec le compte (`PurgeUserVoiceData`, `UserOwnedDataCoverageTest`).
10. **Rien ne s'enregistre de ce que l'utilisateur dit** hors de ce qui est nécessaire : la phrase ajoutée à la liste (pour la mémoire de phrase) et l'ingrédient choisi. Pas d'audio, pas de transcription complète, pas d'identifiant d'appareil Alexa. L'identifiant d'utilisateur Alexa n'est gardé que pour reconnaître une liaison existante.

## Conséquences

- Positives : l'utilisateur dicte ses courses sans toucher le téléphone ; le moteur de choix (habitudes) sert aussi à d'autres entrées (futur « ajout rapide » du front) ; aucun nouveau service à exploiter (pas de Lambda) ; interrupteur et purge comme les chantiers précédents.
- Négatives / coûts : la phrase n'est pas « Alexa, ajoute du pain à la liste » mais « Alexa, demande à *<nom>* d'ajouter du pain » (limite d'Amazon, pas de Repas) ; un serveur d'autorisation OAuth à tenir (surface de sécurité) ; la vérification des requêtes Alexa est du code critique (tests avec de vraies signatures générées) ; un skill en développement ne sert que le compte Amazon de l'auteur (les proches : test bêta Amazon ou publication, décision D4) ; l'application Android réclame les liens de `kantin.eu` (App Links) et peut intercepter la page d'autorisation (ticket VOX-501).
- Garde-fous : Deptrac (aucun import de `Repas`/`User`/`Drive` dans `Voice`), `UserOwnedDataCoverageTest`, test d'énumération des routes `/api/voice/*` (interrupteur, plafond, OpenAPI), `WriteBudget` pour chaque écriture.

## Alternatives écartées

- **Liste de courses native d'Alexa** (API des listes, événements `AlexaHouseholdListEvent`) : retirée par Amazon le 2024-07-01.
- **IFTTT / Zapier / Make** : le service Alexa d'IFTTT est fermé (2023-10-31) ; Zapier et Make n'ont pas de déclencheur « article ajouté à la liste ».
- **Lire la liste d'Amazon avec les cookies de l'utilisateur** (API non officielle) : fragile, contraire aux conditions d'Amazon, secret de compte stocké chez nous.
- **Skill hébergé par Alexa (Lambda Node)** qui rappelle l'API de Repas : un second endroit à déployer et à surveiller, pour une logique que le back sait déjà faire.
- **Routines Alexa** : une routine ne transmet pas un texte libre.
- **Créer un ingrédient quand rien ne correspond** : crée des doublons à partir d'erreurs de reconnaissance ; une « boîte à trier » pourra venir plus tard (VOX-302, facultatif).

## Décisions à prendre par le propriétaire (défauts retenus en attendant)

| | Question | Défaut |
|---|---|---|
| D1 | Nom d'invocation du skill (au moins deux mots, sans « Alexa », sans « liste de courses » déjà pris par Amazon) | « mes courses » |
| D2 | Réponse d'Alexa | courte : « C'est noté : tomate. » ; si le choix est incertain, elle le dit : « J'ai mis tomate, fruits et légumes. » |
| D3 | Produit introuvable | ne rien créer, le dire |
| D4 | Qui peut s'en servir | le compte Amazon de l'auteur (skill en développement, privé) ; les proches plus tard |
| D5 | Aucune liste active | en créer une et le dire |
| D6 | Quantité | une unité d'achat ; « trois tomates » viendra ensuite |
