# AUTH-012 — Bouton « Se connecter avec Google » dans l'application (côté page)

- **Dépôt** : front (`src/features/drive/bridge/` ou module voisin, `src/features/auth/`, `src/views/`, `src/i18n/fr.ts`)
- **Jalon** : M3 Application Android
- **Taille** : S
- **Dépend de** : AUTH-009, AUTH-011 (le contrat du pont est écrit dans AUTH-011 ; développer contre `fakeNative.ts`)
- **Faisable sans téléphone** : oui (faux pont) ; le geste réel est prouvé à la recette
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 5.1, 5.2, 5.5 ; [ADR 0024](../../adr/0024-connexion-avec-google.md) décision 3 ; skills `repas-front-dev` et `repas-po` ; `src/features/drive/bridge/` (`native.ts`, `driveBridge.ts`, `__tests__/fakeNative.ts`) ; [charte Google](https://developers.google.com/identity/branding-guidelines) (2026-07-07).

## Contexte

Dans l'application, la page de Repas n'a pas le script de Google (non pris en charge dans une WebView) : elle affiche **son propre bouton**, aux couleurs exigées par Google, qui demande le jeton à l'application par le pont. Le reste est identique au web : le jeton part à `POST /api/auth/google`.

## Portée

1. **Pont** : étendre `parseInbound` (`native.ts`) avec `google.result` (`idToken` : texte de 1 à 4 096 caractères) et `google.error` (`code` parmi `CANCELLED`, `NO_ACCOUNT`, `UNAVAILABLE`, `BUSY`, `INVALID_REQUEST`, `FAILED`) ; `AppInfo` gagne `google?: boolean` (absent = faux) ; un module `googleSignIn.ts` voisin de `driveBridge` (seul endroit qui connaît `window.repasNative` pour la connexion) expose `signIn({ clientId, nonce }): Promise<string>` qui écoute **avant** d'envoyer, rejette avec un code d'erreur, et expire après 120 s. Double de test : `fakeNative.ts` gagne `google.signIn`.
2. **Bouton natif** `GoogleSignInNativeButton.vue` : dessiné selon la charte (fond blanc `#FFFFFF`, contour `#747775`, texte `#1F1F1F` ; thème sombre `#131314` / `#8E918F` / `#E3E3E3` ; « G » officiel en couleurs, jamais redessiné ni recoloré ; libellé « Se connecter avec Google » ; cible tactile d'au moins 44 px ; au moins aussi visible que le formulaire). Le « G » vient du jeu d'icônes officiel de Google, en ressource locale (CSP : `img-src 'self' data:`).
3. **Choix du bouton** : `GoogleSignInButton.vue` (AUTH-009) rend le bouton de Google sur le web, le bouton natif quand `appInfo.google === true`, **rien** sinon (application plus ancienne, fonction coupée, pas de pont).
4. **Parcours** : toucher → `POST /api/auth/google/nonce` → `signIn({ clientId, nonce })` → `session.loginWithGoogle(idToken)` → liste. Codes : `CANCELLED` : rien ; `UNAVAILABLE` : « Google n'est pas disponible sur ce téléphone. Votre compte Repas n'est pas concerné. Utilisez votre adresse et votre mot de passe. » ; `NO_ACCOUNT` : « Aucun compte Google n'est enregistré sur ce téléphone. Votre compte Repas n'est pas concerné. Utilisez votre adresse et votre mot de passe. » ; `BUSY`, `INVALID_REQUEST`, `FAILED` et les `401` du back : « La connexion avec Google n'a pas abouti. Rien n'a été modifié. Réessayez, ou utilisez votre mot de passe. » ; `409` : texte de l'adresse déjà inscrite ; aucun code visible.
5. Le nonce est demandé **à chaque toucher** (pas avant), donc jamais périmé.
6. Tests de glossaire et de voix mis à jour.

## Hors portée

Le côté Kotlin (AUTH-011) ; la publication de l'application ; FedCM, One Tap, mode `redirect`.

## Critères d'acceptation

- Étant donné l'application avec `app.info.google` vrai et la fonction allumée, quand l'écran de connexion s'ouvre, alors le bouton natif apparaît au-dessus du formulaire, visible sans défiler à 360 × 640, et **aucune requête** part vers `accounts.google.com`.
- Étant donné un toucher et un faux jeton remis par le pont, alors `POST /api/auth/google` part avec ce jeton et la liste s'affiche : **2 touches** (bouton, compte).
- Étant donné `app.info` sans `google` ou faux, alors aucun bouton Google ; le formulaire seul.
- Étant donné `CANCELLED`, alors aucun message ; étant donné `UNAVAILABLE` ou `NO_ACCOUNT`, le texte en trois phrases correspondant et le formulaire utilisable.
- Étant donné un navigateur ordinaire, alors le bouton de Google (AUTH-009) et jamais le natif.
- Étant donné un message de pont inconnu ou mal formé, alors ignoré sans erreur (comportement existant).

## Tests exigés

Vitest : `parseInbound` (nouveaux types, champs en trop, `google` absent), `googleSignIn.ts` (écoute avant envoi, expiration, rejet par code), choix du bouton selon `appInfo`, textes ; Playwright avec le faux pont (Pixel 7) ; **captures lues** (clair/sombre, 360 px) ; contrôle que le bouton natif respecte les couleurs de la charte (test sur les jetons CSS).

## Definition of Done

`make check` vert, couverture non abaissée, captures lues, `CLAUDE.md` du front (une ligne : module `googleSignIn`, règle « jamais `window.repasNative` dans un écran ») et `android/README.md` cohérents avec AUTH-011 ; touches avant/après dans « À relire ».

## Pièges connus

- L'écouteur du pont doit être enregistré **avant** tout envoi (règle existante de `driveBridge.listen()`).
- Ne pas montrer deux boutons Google en même temps ; ne pas charger le script GIS dans l'application.
- Le jeton ne doit pas rester dans l'état de l'application Vue après l'envoi.
- Le « G » est une marque : utiliser l'icône officielle fournie par Google, pas un dessin maison ; ne pas modifier ses proportions.
- Un proche qui n'a aucun compte Google sur le téléphone : la feuille native propose d'en ajouter un (`NO_ACCOUNT` seulement si la configuration l'interdit) ; le formulaire reste là.
- Le nom de pont `repasNative` n'existe pas dans un navigateur : `findNativeBridge` renvoie `null`, ne pas lever.
- Publier le site avant l'application (règle du chantier Drive, ici sans danger dans les deux sens).
