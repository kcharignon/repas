# VOX-204 — Webhook `POST /api/voice/alexa` : ajouter un article

- **Dépôt** : back
- **Jalon** : M2 Back `Voice`
- **Taille** : L
- **Dépend de** : VOX-102, VOX-103, VOX-202, VOX-203
- **Faisable sans téléphone** : oui
- **Statut** : à faire
- **Lire d'abord** : [ADR 0029](../../adr/0029-commande-vocale-alexa.md) ; [`../alexa.md`](../alexa.md) §§ 2, 3 ; `.claude/skills/repas-po/SKILL.md` + `docs/drive/principes-produit.md` (**toute réponse d'Alexa**) ; documentation Amazon du format requête/réponse (relire : `LaunchRequest`, `IntentRequest`, `SessionEndedRequest`, `shouldEndSession`).

## Contexte

Le cœur de la fonction : une phrase arrive, un ingrédient est choisi, la liste est mise à jour, Alexa répond en une phrase.

## Portée

1. Contrôleur `Infrastructure/Http/Api/HandleAlexaRequest/HandleAlexaRequestApiController` (`POST /api/voice/alexa`, `#[RequiresVoice]`, plafond 60/minute et par liaison, OpenAPI) : vérifie (VOX-203), décode, résout le jeton (`session.user.accessToken` → VOX-202) ; **toujours `200` avec une réponse vocale** une fois la requête authentique (jamais de trace d'exception à l'utilisateur).
2. Requêtes gérées : `LaunchRequest` (« Que veux-tu ajouter ? », séance ouverte), `AddItemIntent` (créneau `item`), `AMAZON.HelpIntent`, `AMAZON.StopIntent`/`CancelIntent` (« À bientôt. »), `AMAZON.FallbackIntent` (« Dis par exemple : ajoute du pain. »), `SessionEndedRequest` (réponse vide). Sans jeton : carte `LinkAccount` + « Ouvre l'application Alexa pour lier ton compte Repas. »
3. Commande `AddSpokenItem` (`Voice\Application`) : `SpokenIngredientResolver` puis `VoiceShoppingList`. Textes (catalogue de constantes de `Voice\Domain`, tutoiement, une ou deux phrases) :
   - sûr : « C'est noté : tomate. » ; probable ou incertain : « J'ai mis tomate, fruits et légumes. » ;
   - liste créée : « J'ai commencé une nouvelle liste avec pain. » (+ « Ta liste précédente est partie au drive. » si `previousListSent`) ;
   - introuvable : « Je ne trouve pas xyz dans tes ingrédients. » ;
   - compte désactivé : « Ton compte Repas est désactivé. » ;
   - en séance, ajouter « Autre chose ? » et laisser la séance ouverte ; en appel direct, la fermer.
4. Mise à jour de `last_used_at` de la liaison (au plus toutes les 10 minutes, comme `shouldRecordLogin`).
5. Scénario `WriteBudget`, `make openapi`.

## Critères d'acceptation

- Requête signée de test « ajoute du pain », liste existante → la ligne est dans la liste, la réponse dit « C'est noté : pain. ». Sans liste → liste créée et dite. Liste `SENT` seule → nouvelle liste, la `SENT` intacte.
- « xyz » → rien d'écrit, phrase d'introuvable. Jeton révoqué/absent → carte de liaison, rien d'écrit. Interrupteur coupé → `404`. Requête non signée → `400`.
- Aucune réponse ne contient de jargon (« slug », « ingrédient » seulement dans « tes ingrédients »), aucune ne dépasse deux phrases (test de longueur sur le catalogue).

## Tests exigés

Handler en unitaire (ports en mémoire) ; contrôleur avec les doubles de bus et un faux vérificateur (statut, clés JSON de la réponse Alexa) ; un test de bout en bout du contrôleur avec le vrai vérificateur et la signature de test de VOX-203.

## Pièges connus

- Le créneau `item` arrive avec la forme reconnue par Alexa (« des tomates ») : le nettoyage est celui du ranker (VOX-101), pas ici.
- `SessionEndedRequest` n'accepte aucune réponse vocale.
- Réponse en moins de ~8 s : un seul passage en base pour résoudre, une écriture.
