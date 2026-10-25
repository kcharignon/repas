# VOX-401 — Front : page d'autorisation `/alexa/lier`

- **Dépôt** : front (`repas-front`)
- **Jalon** : M4
- **Taille** : M
- **Dépend de** : VOX-202 (contrat figé par `npm run api:update`)
- **Faisable sans téléphone** : oui (faux back et e2e)
- **Statut** : à faire
- **Lire d'abord** : `CLAUDE.md` du front ; skills `repas-front-dev`, `repas-po` ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décision 6 ; `src/views/LoginView.vue` et `src/session/guard.ts` (`redirect`, `safeRedirect`).

## Contexte

C'est la page que l'application Alexa ouvre pour lier le compte. L'utilisateur doit être connecté, comprendre ce qu'il autorise, et revenir à Alexa en un geste.

## Portée

1. Route `/alexa/lier` (authentifiée) : lit `client_id`, `redirect_uri`, `state`, `code_challenge`, `code_challenge_method` ; si la session manque, **redirection vers la connexion avec `?redirect=` portant l'adresse complète** (requête comprise) et retour ici après connexion (vérifier que `safeRedirect` conserve la requête).
2. Écran : « **Alexa veut ajouter des produits à ta liste de courses.** » deux boutons « Autoriser » / « Annuler » ; aucun autre texte (voix `repas-po`, tutoiement).
3. « Autoriser » → `POST /api/v1/voice/alexa/authorize` ; réponse `redirectUrl` → `window.location.assign`. « Annuler » → page d'accueil (ou `redirect_uri?error=access_denied&state=…` si l'API le permet).
4. Paramètres manquants ou refusés (`422`) : un message en une phrase et un lien vers l'accueil ; **jamais** de redirection vers une adresse non renvoyée par le back.
5. Fonction éteinte (`404 VOICE_DISABLED`) : page « Cette fonction n'est pas encore disponible. ».
6. Textes dans `fr.ts` ; tests unitaires + e2e (faux back) ; captures 360 px clair et sombre lues.

## Critères d'acceptation

- Déconnecté, on arrive sur la connexion, puis sur l'écran d'autorisation avec les mêmes paramètres.
- « Autoriser » navigue vers l'adresse reçue du back et nulle part ailleurs.
- Aucune requête n'est envoyée avant le geste de l'utilisateur.

## Pièges connus

- La page peut s'ouvrir dans une WebView/onglet d'Alexa sans cookie : prévoir la connexion complète dedans (pas de connexion Google dans une WebView : le mot de passe doit rester possible).
- Ne rien écrire des paramètres dans les journaux ni dans l'URL de retour au-delà du nécessaire.
