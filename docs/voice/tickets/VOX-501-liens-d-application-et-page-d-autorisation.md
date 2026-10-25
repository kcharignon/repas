# VOX-501 — Application Android : ne pas intercepter la page d'autorisation

- **Dépôt** : front (`android/`)
- **Jalon** : M5
- **Taille** : S
- **Dépend de** : VOX-401
- **Faisable sans téléphone** : partiellement (la recette le confirme sur un téléphone)
- **Statut** : à faire
- **Lire d'abord** : `android/app/src/main/AndroidManifest.xml` (filtre `autoVerify` sur `${repasHost}`), `android/README.md`, skill `repas-android-dev`.

## Contexte

Le filtre d'intention `https://kantin.eu/…` fait ouvrir **tous** les liens du site dans l'application Repas. Si l'application Alexa ouvre `https://kantin.eu/alexa/lier` par un lien système (onglet Chrome, pas une WebView), Android peut donner la page à l'application Repas au lieu du navigateur : l'utilisateur voit l'application Repas, pas la fin de la liaison.

## Portée

1. **Vérifier** sur un téléphone (VOX-602) où s'ouvre la page. Si l'application Repas l'intercepte, restreindre le filtre aux chemins réellement attendus (`/confirmation-email`, `/mot-de-passe-oublie`, …) par `pathPrefix`, ou servir la page d'autorisation depuis un hôte que l'application ne réclame pas (changement d'hôte : DNS et Caddy, à faire avec l'utilisateur).
2. Si l'interception est **voulue** (l'utilisateur est déjà connecté dans l'application), vérifier que la fin de la redirection (adresse d'Amazon) s'ouvre bien et que l'application Alexa détecte la liaison.
3. Documenter le résultat dans `android/README.md`.

## Critères d'acceptation

- Depuis l'application Alexa, la liaison aboutit sur un téléphone avec l'application Repas installée et sur un téléphone sans.
- Les liens de confirmation d'adresse et de mot de passe oublié s'ouvrent toujours dans l'application.

## Pièges connus

- Un changement du manifeste = nouvelle version de l'application (étiquette `android-vX.Y.Z`) ; l'ordre de publication et l'écriture de `APP_LATEST_VERSION` restent à l'utilisateur.
