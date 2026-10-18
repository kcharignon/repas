# DRV-318 — Test automatique en un geste dans l'application de débogage

- **Dépôt** : front (`kcharignon/repas-front`), dossiers `android/` et `drive-agent/`
- **Jalon** : M3b Application Android
- **Taille** : M
- **Dépend de** : DRV-313 (fait)
- **Statut** : fait (PR [repas-front#89](https://github.com/kcharignon/repas-front/pull/89), 2026-10-18 ; pré-version `android-dev-2026-10-18b`, essai réel par l'utilisateur à faire ; essai réel du 2026-10-18 : verdict OK, voir le rapport DRV-011)
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), `android/PROCEDURE.md` (36 étapes : trop longues pour l'utilisateur), [`../spikes/DRV-011-rapport.md`](../spikes/DRV-011-rapport.md), `drive-agent/README.md`, `CLAUDE.md` du front.

## Contexte

La procédure manuelle de l'APK de débogage fait faire environ douze essais à l'utilisateur : trop long. Il veut **un seul geste et une seule sortie** à copier (ou à partager). Le test remplace donc la procédure.

## Portée

1. **Menu « Test automatique »** (variante de débogage seulement, `BuildConfig.DEBUG`). Il vérifie qu'on est sur une page de magasin connectée, puis lance **sans autre action de l'utilisateur** :
   1. diagnostic de l'agent (les huit contrôles de DRV-308) ;
   2. lecture du magasin (`setup`) ;
   3. un lot de recherches en série (12 mots courants dont farine, sucre, sel, lait, œufs, tomates, bananes, huile d'olive…), avec cadence lente, en comptant produits trouvés, contenu net lisible, prix lus, temps de réponse ;
   4. un passage d'essai : pour **3 ingrédients**, l'agent choisit lui-même le premier candidat non exclu (jamais un produit grisé), l'ajoute en quantité 1 (HTTP et quantité obtenue lus dans la réponse du panier), fixe la quantité à 3 puis à 2 sur le premier produit, puis **retire tout ce qu'il a ajouté** (quantité 0) et relit le panier ;
   5. comparaison du panier avant et après (nombre d'articles, total) : il doit être revenu à l'état initial ; sinon le rapport liste les produits à retirer à la main.
2. **Une seule sortie** : un écran de rapport (OK / KO par étape, durées, codes HTTP, quantités obtenues, nombre de produits trouvés par mot, lot de recherches complet ou arrêt sur blocage, état final du panier), **copié automatiquement dans le presse-papiers** à la fin, avec les boutons « Copier » et « Partager » (feuille de partage d'Android). Aucune donnée personnelle : numéro de magasin réduit aux trois derniers chiffres, hôte réduit à `fdN`, aucun cookie, aucune adresse, pas de nom de ville.
3. **Côté agent** : une commande de protocole `{"v":1,"type":"command","command":"selftest"}` (schéma, validateur, exemples valides et invalides, tests) ; l'agent ne l'honore **que si l'application a annoncé `debug: true`** dans son message d'accueil (le script d'injection ne l'annonce que dans la variante de débogage) ; elle est ignorée pendant un passage. Le choix automatique du candidat vit dans l'agent, derrière cette commande, et n'est jamais déclenché par un plan.
4. Garde-fous : au plus 3 produits ajoutés ; quantité maximale 3 ; arrêt immédiat sur blocage (403/429, défi) ; en cas d'interruption ou d'erreur, nettoyage tenté puis rapport indiquant ce qui reste ; un seul test à la fois.
5. `android/PROCEDURE.md` : réduit à quelques étapes (installer, se connecter à Leclerc, aller sur une page de magasin, toucher « Test automatique », coller le rapport à l'assistant).
6. Publier une nouvelle pré-version de débogage `android-dev-2026-10-18b` (ou date du jour avec suffixe), empreinte SHA-256 dans la description.

## Hors portée

Le lot complet de 30 recherches (facultatif, option « test long » à ajouter plus tard), tout envoi automatique du rapport hors du téléphone.

## Critères d'acceptation

- Un seul geste lance tout le test ; le rapport complet se copie d'un seul geste.
- Le rapport ne contient aucune donnée personnelle (test de motifs).
- Le panier est revenu à son état initial à la fin (ou le rapport l'explique).
- La commande `selftest` est refusée si l'application n'a pas annoncé le mode débogage.

## Tests exigés

Tests Kotlin et JavaScript (rapport, bornes, refus hors débogage, motifs de données personnelles) ; essai de bout en bout dans jsdom avec un faux site ; essai réel par l'utilisateur.

## Definition of Done

Celle du dépôt front ; PR concise ; fusion si CI verte ; issue fermée.

## Pièges connus

- Respecter la cadence (800 ms + gigue) même en test : ne pas accélérer au détriment de l'anti-robot.
- Le premier candidat peut être un produit cher : quantité 1 puis retrait ; ne pas dépasser 3 produits.

## Réalisation (2026-10-18, PR repas-front#89)

Menu « Test automatique (débogage) » : diagnostic, lecture du magasin, lot de 12 recherches (farine, sucre, sel, lait, oeufs sans ligature…), passage d'essai sur farine, sucre et sel (produit éligible : en stock pour 3 exemplaires, hors panier, prix lu, jamais déjà pris pour un autre ingrédient ; quantités 1, 3, 2 sur le premier, puis retrait de tout), comparaison du panier avant et après (`non lu` quand le site ne donne pas l'information), un seul rapport copié automatiquement (« Verdict : OK » en tête), boutons « Copier » et « Partager ». Commande `selftest` honorée seulement si l'application a annoncé `debug: true` ; message `selftest-result` ; l'application n'accepte un rapport que si elle vient de le demander. Arrêt après 3 recherches de suite sans résultat. `agent.js` 0.6.0 (68,3 Ko). Pré-version `android-dev-2026-10-18b` (version 12, empreinte SHA-256 vérifiée). Le workflow `android.yml` accepte une étiquette `android-dev-AAAA-MM-JJ[lettre]`.
