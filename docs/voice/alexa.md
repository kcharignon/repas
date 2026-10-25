# Commande vocale Alexa — analyse

Cadrage du 2026-10-25 ; dialogue du produit inconnu ajouté le même jour (décision 11 de l'[ADR 0029](../adr/0029-commande-vocale-alexa.md), `Proposed`). Suivi : [`roadmap.md`](roadmap.md).

## 1. Faits vérifiés (2026-10-25) et réserves

| Fait | Source | Réserve |
|---|---|---|
| L'API de gestion des listes d'Alexa (listes de courses et de tâches) n'est plus prise en charge depuis le 2024-07-01. Aucun remplaçant. | [Deprecated features, § Shopping lists](https://developer.amazon.com/en-US/docs/alexa/ask-overviews/deprecated-features.html) ; la page « List Management FAQ » renvoie vers elle | La page ne dit rien des événements de liste (`AlexaHouseholdListEvent`) : ils dépendent de la même API, on les tient pour retirés. |
| Le service Alexa d'IFTTT est fermé depuis le 2023-10-31. | [IFTTT, mise à jour du service Alexa](https://help.ifttt.com/hc/en-us/articles/19823288619419-An-important-update-to-the-Alexa-IFTTT-service) | Zapier et Make n'ont pas de déclencheur de liste (témoignage de forum). |
| Un skill personnalisé peut être servi par un service HTTPS : port 443, certificat d'une autorité reconnue (le domaine dans les SAN), requêtes à vérifier (`SignatureCertChainUrl`, `Signature-256`), horodatage à ±150 s ; une requête qui échoue répond `400`. | [Host a custom skill as a web service](https://developer.amazon.com/en-US/docs/alexa/custom-skills/host-a-custom-skill-as-a-web-service.html) | Délai de réponse et taille : non précisés par la page (compter 8 s). |
| Liaison de compte : *authorization code grant* pris en charge pour les skills personnalisés ; Alexa échange le code contre jeton d'accès + jeton de rafraîchissement ; PKCE (SHA-256) activable. | [Configure authorization code grant](https://developer.amazon.com/en-US/docs/alexa/account-linking/configure-authorization-code-grant.html) | Format exact de la requête de jeton et URL de redirection propres à l'hébergement (France) : lire la page de la console au moment du ticket VOX-202. |

**À vérifier dans la console au moment de la création du skill (VOX-002)** : règles du nom d'invocation en français (au moins deux mots sauf marque), phrases d'appel proposées (« demande à … », « dis à … », « ouvre … »), URL de redirection affichées pour la liaison, comportement d'un skill en développement pour les autres membres du foyer.

## 2. Ce que l'utilisateur dira

- Appel direct : « Alexa, demande à *mes courses* d'ajouter du pain ».
- Séance : « Alexa, ouvre *mes courses* » → « Que veux-tu ajouter ? » → « du pain » → « C'est noté : pain. Autre chose ? » → « des tomates » → … → « non, merci ».
- Aide : « Alexa, demande à *mes courses* de l'aide ».
- Produit inconnu : « ajoute de la ciboulette » → « Je ne connais pas ciboulette. Tu veux dire persil, basilic ou coriandre ? Ou un nouveau produit ? » ; trois façons de répondre :
  - une proposition, par son nom (« le basilic »), son rang (« le deuxième ») ou « oui » s'il n'y en a qu'une → « C'est noté : basilic. Je m'en souviendrai. Autre chose ? » : la phrase est retenue, la prochaine fois « de la ciboulette » donne directement le basilic ;
  - « un nouveau produit » (ou « aucun », « non ») → « Dans quel rayon ? » → « fruits et légumes » → « Quelle unité ? » → « des pièces » → « Je crée ciboulette, fruit et légume, unité. C'est bon ? » → « oui » → « C'est créé et noté : ciboulette. » (« non » : « D'accord, je ne crée rien. ») ;
  - un autre article (« du lait ») : le dialogue est abandonné, « du lait » est traité comme une nouvelle demande.
  Rien de proche : « Je ne connais pas ciboulette. Je le crée ? » (oui → les deux questions). Deux réponses incomprises de suite dans la même étape : « Je n'ai pas compris. On laisse tomber. » ; « stop » et « annule » ferment la séance à tout moment ; « aide » répète la question.
- Annuler (phase 2) : « annule » retire la dernière ligne ajoutée dans la séance.

« Alexa, ajoute du pain à *ma liste de courses* » reste la liste d'Amazon : on ne peut pas la détourner. À écrire dans l'aide du front, une fois, simplement.

## 3. Architecture

```
Echo ──voix──> Amazon ──HTTPS──> kantin.eu  POST /api/voice/alexa          (public, signature vérifiée)
                                      │  jeton d'accès -> liaison -> utilisateur
                                      ▼
                    Voice (Application) AddSpokenItem
                          │ port Shared SpokenIngredientResolver   ──> Repas : SpokenIngredientRanker (pur)
                          │ port Shared VoiceShoppingList          ──> Repas : EnsureActiveShoppingList + AddIngredient…
                          ▼
                    réponse vocale (texte français court)
```

Liaison de compte (une fois) :

```
App Alexa ─> https://kantin.eu/alexa/lier?client_id&redirect_uri&state&code_challenge…   (page du front)
              connexion Repas (si besoin) -> « Autoriser » -> POST /api/v1/voice/alexa/authorize (JWT)
              <- {redirectUrl: redirect_uri?code=…&state=…} -> navigation
Amazon ─> POST /api/voice/oauth/token  (grant authorization_code | refresh_token)  -> {access_token, refresh_token, expires_in}
```

### Contexte `Voice`

```
src/Voice/
  Domain/Model       VoiceLink, AuthorizationCode, AccessGrant (hachés), AlexaRequest, AlexaResponse, AlexaIntent, VoiceReplies (tous les textes), SpokenDialogueState (l'état du dialogue)
  Domain/Interface   VoiceLinkRepository, AuthorizationCodeRepository, AlexaRequestVerifier
  Domain/Service     AlexaDialogue (lancement, aide, stop, repli), SpokenAnswerInterpreter (la réponse selon l'état), SpokenIngredientNamer, LinkRevoker
  Domain/Exception   VoiceException
  Application/Command   IssueAuthorizationCode, ExchangeAuthorizationCode, RefreshAccessGrant, RevokeVoiceLink, AddSpokenItem, AnswerSpokenDialogue, RecordVoiceLinkUse, PurgeUserVoiceData
  Application/Query     ListVoiceLinks, ResolveAccessGrant
  Infrastructure/Http/Api  …ApiController, HandleAlexaRequestApiController (le webhook), VoiceLinkRateLimiter ; listeners d'interrupteur et de plafond
```

Ports de `Shared` (la seule couture avec `Repas`), implémentés dans `Repas\Infrastructure\Voice` :

- `SpokenIngredientResolver::resolve(userId, phrase): ?ResolvedIngredient{slug, name, departmentName, confidence}` (mémoire de phrase d'abord, puis classement) ; `suggest(userId, phrase): list<IngredientSuggestion{slug, name, departmentName}>` (trois au plus) ; `remember(userId, phrase, slug): void` (commande `RememberSpokenPhrase`) ;
- `VoiceShoppingList::addItem(userId, ingredientSlug): AddedItem{listCreated, previousListSent}` ;
- `SpokenIngredientCreator` : `departments()` et `units()` (le catalogue commun, listes `SpokenChoice{slug, name}`), `create(userId, name, departmentSlug, unitSlug): CreatedIngredient{slug, name, departmentName}` (par le `CreateIngredientHandler` existant, une seule unité pour l'achat et la cuisine, aucun coefficient).

Le dialogue d'un produit inconnu (décision 11) : `AddSpokenItem` rend, quand rien n'est reconnu, la question et le **prochain état** (`SpokenDialogueState`, dans `AddSpokenItemResult`) ; l'état voyage dans `sessionAttributes.dialogue` (réponse) puis `session.attributes.dialogue` (requête suivante) ; `AnswerSpokenDialogue` interprète la réponse d'après l'ÉTAT (`SpokenAnswerInterpreter`, pur : Alexa choisit l'intention sans connaître notre état) et lit/écrit par les ports. Étapes : `choose` (propositions, ou « Je le crée ? »), `department`, `unit`, `confirm`.

## 4. Choisir l'ingrédient le plus probable

Entrée : la phrase reconnue (« des tomates », « du pain de mie »). Sortie : un ingrédient de l'utilisateur, une confiance (`SURE`, `PROBABLE`, `UNSURE`) ou rien.

1. **Normalisation** : `StringTool::slugify`, mots vides retirés (`IngredientNameMatcher::STOP_WORDS` + « du », « de la », « des », « un », « une »), pluriels repliés (`IngredientNameMatcher::tokens`).
2. **Candidats** : les ingrédients de l'utilisateur (`IngredientVisibility::visibleBy`), avec pour chacun `uses12m` = lignes de ses listes + lignes de ses recettes sur douze mois (une requête agrégée, comme `search()`).
3. **Mémoire de phrase** : une ligne `spoken_phrase_choice(user_id, phrase, ingredient_slug, times, updated_at)` pour la **clé** de la phrase (`SpokenIngredientRanker::phraseKey` : les mots du classeur joints par « - », 100 caractères au plus, la même clé à l'écriture et à la lecture) → cet ingrédient, `SURE`. Écrite quand l'utilisateur choisit l'une des propositions d'Alexa (VOX-303, `RememberSpokenPhrase`, le dernier choix l'emporte et `times` compte les mêmes choix de suite) ; la correction depuis l'application (VOX-301) l'écrira aussi. Lue AVANT le classement par l'adaptateur du port ; ignorée si l'ingrédient n'existe plus. **Limite connue** : si l'utilisateur crée plus tard un ingrédient au nom exact d'une phrase retenue, la mémoire gagne encore (la règle 3 passe avant le nom identique de la règle 4).
4. **Sinon** pour chaque candidat avec `SearchRelevance::score ≥ 0,85` : `score = pertinence + 0,1 × n/(n+3)`, `n = uses12m`. **Un nom identique à la phrase (une fois normalisés) passe avant tout nom seulement proche** : le bonus d'habitude (jusqu'à 0,1) dépasserait sinon l'écart de pertinence (« tomate » 1,0 contre *tomates pelées* 0,95 : 0,95 + 0,093 > 1,0). L'habitude ne départage donc que des noms de même rang (VOX-101, constatée à l'écriture des tests).
5. **Confiance** : `SURE` pour le seul nom identique (ou la mémoire de phrase) ; `PROBABLE` si le premier devance le suivant d'au moins 0,03, ou n'a pas de suivant (un seul candidat proche) ; sinon `UNSURE` (deux noms qui se touchent : on ajoute le premier et on le dit).
6. **Ordre déterministe** : à score égal, nom puis slug (comme `search()`).

### Les propositions (rien de choisi tout seul)

`SpokenIngredientRanker::suggest` : quand `rank` ne choisit rien, au plus trois ingrédients de l'utilisateur, classés comme `rank` (pertinence + bonus d'habitude, ordre déterministe), dont la pertinence est **sous** `SearchRelevance::MINIMUM` (0,85 : au-dessus ils auraient été choisis) et **au moins** `SUGGESTION_FLOOR` (0,80), ET qui partagent un début de mot (trois lettres) avec la phrase. Table de cas figée (`SpokenIngredientRankerTest`) : « sirop de menthe » propose *sirop fruit rouge* (0,827) ; « zébulon » ne propose rien (*melon* 0,79) ; « fenouil » ne propose pas *nouille* (0,81, aucun début en commun) ; « basilic » ne propose pas *ail* ; « crevettes » ne propose ni *courgette* (0,825) ni *croquette*. Sans le début en commun, Jaro-Winkler seul proposerait ces noms sans rapport. Aucune proposition : « Je le crée ? ». **Réserve** : un nom très proche d'une faute de frappe (« ciboulete » contre *ciboulette*, 0,98) est au-dessus de 0,85 : il est choisi tout seul (`PROBABLE`, Alexa le dit : « J'ai mis ciboulette, … »), il n'y a pas de question.

Pourquoi ce n'est pas `IngredientNameMatcher` : lui sert à dire « c'est le même ingrédient » avec certitude, en se trompant vers le doublon (ADR 0028). Ici l'utilisateur veut le plus probable, et l'erreur se répare d'un geste dans l'application.

Table de cas figée par VOX-101 (exemples à confirmer sur les données réelles de l'auteur) : « tomate » → *tomate* même si *tomates pelées* est acheté plus souvent ; « tomates pelées » → *tomates pelées* ; « farine » → l'ingrédient *farine …* le plus acheté ; « pâtes » → *pâtes* ; « lait » → *lait* acheté le plus souvent ; « pomme de terre » / « patates » (alias) ; « xyz » → rien.

## 5. Risques

| Risque | Parade |
|---|---|
| Amazon change ou retire ce qu'il reste (skills personnalisés) | Le contexte `Voice` est isolé ; couper `ALEXA_ENABLED` ne casse rien d'autre. |
| Reconnaissance vocale approximative (« Jarko Winkler » est notre algorithme, pas le leur) | Seuil de pertinence, mémoire de phrase, correction en un geste dans la liste ; modèle d'interaction avec les noms d'ingrédients du catalogue de base pour orienter la reconnaissance. |
| Faille dans la vérification des requêtes = n'importe qui écrit dans une liste | Tests avec de vraies signatures générées (certificat de test), `applicationId` contrôlé, liaison obligatoire, jeton haché à portée unique. |
| L'application Android ouvre `/alexa/lier` au lieu du navigateur d'Alexa | VOX-501 : vérifier à la recette, restreindre les chemins de l'App Link si besoin. |
| Un compte créé par Google n'a pas de mot de passe ; Google refuse sa connexion dans une WebView | La page d'autorisation accepte le mot de passe ; sinon passer par « poser un premier mot de passe » (AUTH-014) ; ne pas bloquer le chantier dessus. |
| Plusieurs personnes derrière un même Echo | Un Echo = un compte Amazon = une liaison = un compte Repas. Les profils vocaux (`personId`) sont hors périmètre. |
| Vie privée (voix) | Aucun audio ni transcription conservés ; voir ADR 0029 décision 10 ; ajouter une phrase à `docs/drive/note-vie-privee.md` si publié. |
| Un état de dialogue forgé (slug d'un ingrédient d'un autre, rayon inventé) | L'état est une entrée non fiable : lecture tolérante et bornée (`SpokenDialogueState::fromArray`), slugs jamais crus (l'ajout, la mémoire et la création les vérifient auprès de `Repas`), tout échec annule la commande entière et Alexa répond « Je n'ai pas pu faire ça. » sans état. |
| Boucle sans fin dans un dialogue | Deux réponses incomprises de suite dans la même étape abandonnent poliment ; « stop » ferme la séance dans tout état. |
| Alexa choisit l'intention sans connaître l'état (« persil » reçu comme un article, « kilos » comme une unité) | L'interprétation part de l'état et des textes entendus, pas de l'intention seule (`SpokenAnswerInterpreter`, table de cas). |

## 6. Ce qui exige l'humain

Compte développeur Amazon, création du skill dans la console, collage du manifeste et du modèle d'interaction, saisie de l'ID client et du secret de liaison, copie des URL de redirection, activation du skill dans l'application Alexa, allumage de `ALEXA_ENABLED`, essai sur l'Echo, approbation des déploiements. Tout le reste est faisable sans Echo : signatures de test, faux client HTTP, faux webhook.
