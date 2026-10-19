# AUTH-009 — Bouton « Se connecter avec Google » sur le web (GIS) et ouverture de la CSP

- **Dépôt** : front (`kcharignon/repas-front` : `docker/Caddyfile`, `src/features/auth/`, `src/session/`, `src/views/`, `src/i18n/fr.ts`)
- **Jalon** : M2 Front web
- **Taille** : M
- **Dépend de** : AUTH-007 (contrat fusionné ; `make api-update ref=<sha>`), AUTH-001 (D5)
- **Faisable sans téléphone** : oui (faux Google dans les tests ; le vrai Google se teste à la recette, AUTH-013)
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 5.1 à 5.3 ; [ADR 0024](../../adr/0024-connexion-avec-google.md) décisions 2 et 13 ; skills `repas-front-dev` et `repas-po` ; `docs/drive/principes-produit.md` ; ADR 0005 du front (session) et 0004 (CSP en fumée) ; `CLAUDE.md` du front.

## Contexte

Sur le web, Google rend lui-même le bouton (conforme à sa charte) et renvoie un jeton d'identité à une fonction JavaScript. La page le poste à `POST /api/auth/google`, comme `login` poste un mot de passe. Le script de Google n'est chargé que sur les écrans de connexion et d'inscription, et seulement si le back dit que la fonction est allumée. **Dans l'application Android, ce bouton n'existe pas** (GIS n'y est pas pris en charge) : c'est AUTH-012.

## Portée

1. **CSP** (`docker/Caddyfile`) : ajouter exactement `script-src … https://accounts.google.com/gsi/client`, `frame-src https://accounts.google.com/gsi/`, `connect-src … https://accounts.google.com/gsi/`, `style-src … https://accounts.google.com/gsi/style` ; ne pas toucher `img-src`, ni ajouter de joker. Mettre à jour le test de fumée de la CSP (ADR 0004 du front) et l'ADR 0004/CLAUDE.md du front (« aucun site externe » devient « Google Identity Services, quatre sources »).
2. **Session** (`src/session/session.ts`) : `loginWithGoogle(credential)` → `POST /api/auth/google` (corps `{credential}`), jeton en mémoire, `loadUser()`, indice de session ; jamais de stockage du jeton Google.
3. **Composable** `src/features/auth/useGoogleSignIn.ts` : au montage, `POST /api/auth/google/nonce` ; `404` (ou erreur réseau) → rien n'est affiché ni chargé ; sinon charge `https://accounts.google.com/gsi/client` (balise `script` ajoutée par programme, une seule fois), `google.accounts.id.initialize({ client_id, nonce, callback, ux_mode: 'popup', use_fedcm_for_button: false })`, `renderButton(élément, { type: 'standard', theme (clair/sombre selon le thème), size: 'large', text: 'signin_with' | 'signup_with', locale: 'fr', width })`. **Pas de One Tap.** Le nonce est **renouvelé toutes les 4 minutes** tant que l'écran est visible et **après chaque échec ou fermeture** (il est à usage unique et vit 5 minutes) ; démontage : arrêt du minuteur.
4. **Composant** `GoogleSignInButton.vue` placé en haut de `LoginView.vue` (« Se connecter avec Google ») et de `RegisterView.vue` (« S'inscrire avec Google »), suivi d'un séparateur « ou » puis du formulaire existant, inchangé. Le composant ne rend rien si `findNativeBridge(window)` existe (application) ou si D5 = « hors lot » et que le navigateur est iOS (iPhone, iPad, ou iPadOS en mode ordinateur : `MacIntel` avec écran tactile).
5. **Textes** (`src/i18n/fr.ts`, au tutoiement : DRV-627 est fait) : les textes du § 5.2 de l'analyse (échec, adresse déjà inscrite `409`, trop d'essais `429`) ; erreurs en trois phrases, aucun code visible. Fenêtre fermée par l'utilisateur : aucun message.
6. **Connexion réussie** : `router.push(safeRedirect(route.query.redirect))`, comme la connexion par mot de passe.
7. **Test de glossaire** (`voice-and-glossary.spec.ts`) : échoue si « jeton », « token », « OAuth », « identité », « SSO », « lier » apparaissent dans un texte visible de ces écrans.
8. `docs/auth` du back : l'assistant coche la roadmap après fusion.

## Hors portée

Le bouton dans l'application (AUTH-012) ; la page de conditions (AUTH-010) ; le mode `redirect` de GIS (iPhone, D5) ; FedCM (à essayer à la recette, pas activé) ; One Tap ; une confirmation ou un écran de plus.

## Critères d'acceptation

- Étant donné la fonction coupée (`404`), quand l'écran de connexion s'ouvre, alors aucun bouton, **aucune requête vers `accounts.google.com`**, aucune erreur en console.
- Étant donné la fonction allumée, quand l'écran de connexion s'ouvre dans un navigateur ordinaire, alors le bouton de Google apparaît au-dessus du formulaire, visible sans défiler à 360 × 640.
- Étant donné un jeton remis par Google, quand le rappel s'exécute, alors `POST /api/auth/google` part avec `{credential}`, la session s'ouvre et l'écran d'accueil de la liste s'affiche.
- Étant donné une réponse `401`, alors le message d'échec en trois phrases s'affiche et un nouveau nonce est demandé ; étant donné `409`, le message de l'adresse déjà inscrite s'affiche et le formulaire reste utilisable ; étant donné `429`, comme la connexion par mot de passe.
- Étant donné l'utilisateur qui laisse l'écran ouvert six minutes puis utilise le bouton, alors la connexion réussit (nonce renouvelé).
- Étant donné l'application Android (pont présent), alors le composant ne rend rien et ne charge pas le script de Google.
- Étant donné la CSP de production (`make prod`), alors la page de connexion se charge sans violation de CSP ni message en console.

## Tests exigés

Vitest : composable avec un faux `google.accounts.id` (nonce demandé avant `initialize`, renouvellement, rappel, nettoyage), `session.loginWithGoogle`, textes et glossaire ; composant (présence/absence selon `404`, pont, iOS) ; Playwright (Pixel 7 et bureau) : `page.route` sert un faux script `gsi/client` qui expose un faux `google.accounts.id` et un faux back ; **captures lues** à 360 px en clair et en sombre ; `make prod` + vérification de la CSP et de l'absence de requête externe quand la fonction est coupée. Aucun appel au vrai Google dans les tests.

## Definition of Done

Celle du dépôt front : `make check` vert (lint, format, types, tests), seuil de couverture non abaissé, types de l'API générés, textes dans `fr.ts`, test visuel Chromium mobile et bureau, `CLAUDE.md` du front à jour, parcours (touches avant/après) et captures dans « À relire ».

## Pièges connus

- `initialize` ne se rappelle pas proprement : ne l'appeler qu'une fois par chargement du script ; pour changer de nonce, ré-appeler `initialize` puis `renderButton` (vérifier le comportement avec le vrai script à la recette).
- Le nonce doit être obtenu **avant** `initialize` et recopié tel quel ; le serveur le lit dans le jeton, la page ne le renvoie pas.
- Ne jamais mettre le jeton Google (`credential`) en `localStorage`, en URL, ni dans un journal.
- GIS n'est pas pris en charge dans une WebView (page officielle) : ne pas charger le script quand le pont natif existe.
- Sur iOS, Google impose le mode `redirect` ; en mode fenêtre, le bouton échoue. D5 décide (masquer ou ajouter le mode).
- Pas de COOP à poser ; si un jour un `Cross-Origin-Opener-Policy` est ajouté, il doit être `same-origin-allow-popups` (page « get-google-api-clientid »).
- Le service worker ne touche pas aux requêtes externes ; le vérifier dans le test de fumée (aucune réponse servie par le worker pour `accounts.google.com`).
- Le bouton rendu par Google a sa propre largeur maximale (400 px) ; le centrer, ne pas l'étirer.
- Charte : ne jamais redessiner le « G » ni changer ses couleurs ; sur le web, c'est Google qui rend le bouton, donc rien à vérifier.
- `setup.ts` fait échouer un test sur le moindre `console.warn` : le faux Google doit être propre.
