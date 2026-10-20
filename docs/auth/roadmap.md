# Chantier : connexion avec Google

Suivi vivant du chantier « Se connecter avec Google » (web `kantin.eu` et application Android « Repas »). Cadrage du 2026-10-19 ; décision d'architecture : [ADR 0024](../adr/0024-connexion-avec-google.md) (`Proposed` jusqu'à AUTH-001). **À lire en premier par tout agent qui reprend ce chantier**, dans cet ordre :

1. Ce fichier.
2. [`connexion-google.md`](connexion-google.md) : l'analyse (WebView, back, politique de compte, front, console Google, alternatives, risques, sources datées).
3. L'[ADR 0024](../adr/0024-connexion-avec-google.md), puis le ticket choisi dans [`tickets/`](tickets/) (autonome, il fait foi).
4. [`docs/drive/consignes-agents.md`](../drive/consignes-agents.md) (règles communes de livraison : une branche par ticket, commits courts en français sans `Co-Authored-By`, PR au gabarit Quoi / Vérifié / À relire sans pied d'attribution, fusion en squash seulement si la CI est verte) et les skills `repas-dev` (back), `repas-front-dev` (front), `repas-android-dev` (application), `repas-po` (tout écran, tout texte), `repas-ops` (tout ce qui touche `deploy/`).
5. Le `CLAUDE.md` du dépôt où l'on travaille.

## Le besoin

Un proche touche « Se connecter avec Google », choisit son compte et c'est fini : ni mot de passe à créer ou à retenir, ni lien à chercher dans sa messagerie. Le mot de passe reste possible. Le critère de réussite est celui du produit : **un proche se connecte seul, en deux touches** ([`principes-produit.md`](../drive/principes-produit.md)).

## Règles du chantier

- **Aucun secret dans les dépôts publics ni dans le chat** : pas de *client secret* (on ne fait pas le flux par code), pas de clé de signature, pas de mot de passe de clé ; l'ID client Web est public mais propre au déploiement (`GOOGLE_CLIENT_ID` dans `/opt/repas/.env`) ; l'empreinte SHA-1 du certificat de publication est publique, saisie par l'utilisateur lui-même dans la console. **L'empreinte de la clé de débogage publique n'est jamais enregistrée dans le projet Google de production.**
- **Aucune connexion Google dans une WebView** : web = Google Identity Services ; application = Credential Manager natif, jeton remis à la page par le pont.
- **Aucun agent ne contacte Google** (ni la console, ni les points d'accès) dans ses tests : vraies signatures RSA générées dans les tests, faux client HTTP, faux pont. Le vrai Google ne se voit qu'à la recette, par l'utilisateur.
- Un agent ne déploie pas, ne crée aucune étiquette (`vX.Y.Z`, `android-vX.Y.Z`), ne touche ni aux secrets GitHub ni au serveur, et **n'allume jamais** `GOOGLE_CLIENT_ID` : l'utilisateur lance le script et approuve les déploiements.
- Ordre de publication : back (fonction coupée), site, application, puis allumage et recette. Un ancien front ignore les nouveaux messages du pont ; un front neuf devant une ancienne application masque le bouton.
- Changer une convention = mettre à jour l'ADR 0024, `CLAUDE.md`, le skill concerné et cette roadmap dans le même commit.

## M0 — Décisions et console

- [x] [AUTH-001](tickets/AUTH-001-decisions-du-proprietaire.md) assistant · S · Décisions D1 à D5 du propriétaire, ADR 0024 `Accepted` · dépend de : —
- [ ] [AUTH-002](tickets/AUTH-002-console-google-cloud.md) humain · M · Console Google Cloud : projet, consentement, client Web, client Android · dépend de : 001

## M1 — Back `User`

- [x] [AUTH-003](tickets/AUTH-003-verificateur-de-jeton-google.md) back · M · Vérificateur de jeton d'identité Google (port, adaptateur `lcobucci/jwt`, clés en cache) · dépend de : — · fait (PR #220)
- [ ] [AUTH-004](tickets/AUTH-004-nonce-de-connexion.md) back · S · Nonce à usage unique et `POST /api/auth/google/nonce` (interrupteur `GOOGLE_CLIENT_ID`) · dépend de : 003
- [x] [AUTH-005](tickets/AUTH-005-identite-externe-migration-purge.md) back · M · Identité externe : `UserIdentity`, table `user_identity`, purge du compte · dépend de : — · fait (PR #222)
- [ ] [AUTH-006](tickets/AUTH-006-cas-d-usage-se-connecter-avec-google.md) back · L · Cas d'usage : retrouver, lier, créer ou refuser un compte · dépend de : 003, 004, 005, 001
- [ ] [AUTH-007](tickets/AUTH-007-route-connexion-google.md) back · M · `POST /api/auth/google` : JWT, cookie, plafond, OpenAPI, parcours complet · dépend de : 006
- [ ] [AUTH-008](tickets/AUTH-008-deploiement-et-exploitation.md) back · S · Déploiement : variable, essai de bout en bout, script d'allumage, runbook · dépend de : 007

## M2 — Front web

- [ ] [AUTH-009](tickets/AUTH-009-bouton-google-sur-le-web.md) front · M · Bouton Google (GIS) sur la connexion et l'inscription, ouverture de la CSP · dépend de : 007, 001
- [ ] [AUTH-010](tickets/AUTH-010-conditions-d-utilisation-publiques.md) front · S · Conditions d'utilisation publiques, section « Connexion avec Google » · dépend de : 001

## M3 — Application Android

- [x] [AUTH-015](tickets/AUTH-015-tronc-commun-kotlin.md) front (`android/`) · M · Isoler un tronc commun Kotlin (cœur sans dépendance Android, interfaces de plateforme) pour un iPhone plus tard (décision D5) · dépend de : —
- [ ] [AUTH-011](tickets/AUTH-011-android-pont-google-credential-manager.md) front (`android/`) · M · Pont `google.signIn` et Credential Manager · dépend de : 015 · **code fait, PR front #171 ouverte, CI verte, non fusionnée volontairement** (décision de l'utilisateur à prendre : APK +2,67 Mo, dépendance aux services Play en préversion, permissions retirées du manifeste, `googleid` 1.2.0 au lieu de 1.2.1 à cause de Kotlin)
- [ ] [AUTH-012](tickets/AUTH-012-bouton-google-dans-l-application.md) front · S · Bouton natif dans l'application (côté page) · dépend de : 009, 011

## M4 — Recette et mise en service

- [ ] [AUTH-013](tickets/AUTH-013-recette-web-et-telephone.md) humain · M · Recette : ordinateur, navigateur du téléphone, application, un proche seul · dépend de : 002, 008, 009, 011, 012 (010 si D4 = oui)

## Facultatif

- [ ] [AUTH-014](tickets/AUTH-014-poser-un-premier-mot-de-passe.md) back puis front · S · Poser un premier mot de passe depuis « Moi » (compte créé par Google) · dépend de : 006, 009 · à faire avant AUTH-013 si D3 = « maintenant »

## Prérequis d'ouverture à tous

Décision D3 (2026-10-19) : la fonction `GOOGLE_CLIENT_ID` n'est **allumée pour tous** qu'après « mot de passe oublié » (issue repas#31, à lancer après le redémarrage de session du 2026-10-19). Avant, on peut la tester avec le propriétaire seul.

## Chemin critique et parallélisme

- **Critique** : 001 → 006 → 007 → 008 → (009 ‖ 011 → 012) → 013.
- **En parallèle dès le départ** : 003, 005 et 011 ne dépendent de rien ; 002 (humain) peut avancer pendant le code ; 004 suit 003.
- **Front** : 009 attend la fusion de 007 (contrat figé par `make api-update ref=<sha>`) ; 010 est indépendant du code ; 012 se développe contre le faux pont dès que le contrat de 011 est écrit.
- **Première version utilisable** : M0 à M4 sans AUTH-014.

## Ce qui exige le téléphone

- **Rien dans les tests automatiques.** Les tickets 003 à 012 se font, se testent et se fusionnent sans téléphone ni compte Google (signatures RSA de test, faux client HTTP, faux pont, fournisseur factice dans l'émulateur).
- **AUTH-013** (recette) est le seul ticket qui exige un téléphone réel et un compte Google d'essai ; c'est elle qui prouve les parties Android d'AUTH-011 et d'AUTH-012 et qui lève les inconnues (APK signé hors Google Play accepté, contenu réel du jeton, affichage de Google pour une application non vérifiée, poids de l'APK, téléphone sans services Google).
- **AUTH-002** (console) se fait sur un ordinateur ; seule la lecture de l'empreinte de la clé se fait dans le terminal de l'utilisateur.

## Definition of Ready

Les dépendances du ticket sont fusionnées, ses décisions ouvertes (D1 à D5) sont tranchées ou le ticket dit lesquelles il attend, il tient dans une PR relisible.

## Definition of Done (communes)

- **Back** : celle du skill `repas-dev` § 4 (`make test`, `make quality`, `make audit` si `composer.*` change, `make deploy-smoke` si `deploy/` change, scénario `WriteBudget`, `make openapi`, purge du compte pour toute colonne `user`).
- **Front** : `make check` vert, couverture non abaissée, types de l'API générés, textes dans `fr.ts`, **captures lues** (360 px, clair et sombre), `make android-test` si l'application change.
- **Tous** : commits courts en français sans `Co-Authored-By`, PR au gabarit sans pied d'attribution, jamais de push sur `main` ni de force-push, cette roadmap cochée (le back coche ; pour un ticket du front, l'assistant le fait après la fusion), aucun identifiant ni secret dans le dépôt.
