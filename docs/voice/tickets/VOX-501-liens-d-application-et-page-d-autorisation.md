# VOX-501 — Application Android : ne pas intercepter la page d'autorisation

- **Dépôt** : front (`android/`)
- **Jalon** : M5
- **Taille** : S
- **Dépend de** : VOX-401
- **Faisable sans téléphone** : oui pour le code et le test ; la recette (liaison depuis l'application Alexa sur un téléphone qui a l'application Repas) se fait avec le propriétaire
- **Statut** : fait côté front (2026-10-26, PR `kcharignon/repas-front` #211, **non publiée** : elle demande une étiquette `android-vX.Y.Z`, poussée seulement à la demande du propriétaire, l'installation de l'APK et l'écriture de `APP_LATEST_VERSION` par son script)
- **Lire d'abord** : `android/app/src/main/AndroidManifest.xml` (filtre `autoVerify`), `android/README.md` (« Liens entrants »), `android/__tests__/shell.spec.ts`, skill `repas-android-dev`.

## Contexte

Le filtre d'intention `https://kantin.eu/…` faisait ouvrir **tous** les liens du site dans l'application Repas (`assetlinks.json` déclare le paquet `eu.kantin.repas.drive`). Constat du 2026-10-26 : sur le téléphone de la compagne du propriétaire (application Repas installée), l'activation du skill depuis l'application Alexa a ouvert l'application Repas (le journal du serveur montre `POST /api/auth/refresh`, `GET /api/v1/me` et `PUT /api/v1/users/me/app-version` d'un Pixel 6, et aucun `POST /api/v1/voice/alexa/authorize`) et l'écran affichait la page « introuvable » du site (« Retour à la liste »).

## Décisions

1. **Le filtre ne capture que les deux liens que Repas envoie par courriel, par chemin exact** (`android:path`) : `/confirmation-email` (confirmation d'adresse, `MailerEmailVerificationNotifier`) et `/mot-de-passe-oublie/confirmation` (mot de passe oublié, `MailerPasswordResetNotifier::CONFIRMATION_PATH`). `/alexa/lier`, la confidentialité, la connexion et les autres pages publiques s'ouvrent dans le navigateur. Aucun autre lien reçu par l'utilisateur n'existe (vérifié dans le back : seuls ces deux courriels portent `FRONT_URL`).
2. **Un chemin de plus à capturer = une ligne du manifeste et du test** (`android/__tests__/shell.spec.ts`, joué par `make check`, échoue si le filtre redevient « tout l'hôte », prend un préfixe, laisse entrer `/alexa/lier` ou nomme un chemin qui n'est pas une route de `src/router/index.ts`).
3. **Piège documenté** : une application déjà installée garde l'ancien filtre jusqu'à sa mise à jour. Contournement sur un téléphone qui a l'ancienne version : Paramètres → Applications → Repas → Ouvrir par défaut (« Liens pris en charge » selon la version d'Android) → désactiver « Ouvrir les liens pris en charge ».
4. **La coquille charge le site en direct** (WebView sur `https://kantin.eu/`, aucune page embarquée) : `/alexa/lier` existe dans toute version de l'application, dont la dernière publiée (`android-v1.0.0`). La page « introuvable » vue sur l'ancien téléphone n'est pas reproduite ; cause probable (hypothèse, non vérifiée) : le site est une application web avec un service worker (`vite-plugin-pwa`, mise à jour automatique) qui sert sa coquille en cache, donc le premier chargement d'une route toute neuve (`/alexa/lier` date du 2026-10-25) peut montrer l'ancienne coquille ; elle se met à jour et se recharge, un second essai suffit.

## Critères d'acceptation

- Depuis l'application Alexa, la liaison aboutit sur un téléphone avec l'application Repas installée (nouvelle version) et sur un téléphone sans.
- Les liens de confirmation d'adresse et de mot de passe oublié s'ouvrent toujours dans l'application.
- Un test de dépôt le garde.

## Pièges connus

- Un changement du manifeste = nouvelle version de l'application (étiquette `android-vX.Y.Z`) ; l'ordre de publication et l'écriture de `APP_LATEST_VERSION` restent à l'utilisateur.
- La recette réelle sur le téléphone de la compagne reste à faire (VOX-602) : ouvrir le lien d'autorisation depuis l'application Alexa et vérifier où il s'ouvre.
