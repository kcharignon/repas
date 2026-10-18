# DRV-317 — Coquille de l'application Repas (WebView sur le front Repas)

- **Dépôt** : front (`kcharignon/repas-front`), dossier `android/`
- **Jalon** : M3b Application Android
- **Taille** : L
- **Dépend de** : DRV-312, DRV-313
- **Statut** : à faire
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md) (précision du 2026-10-18), `android/README.md`, `android/PROCEDURE.md`, `CLAUDE.md` du front.

## Contexte

L'utilisateur veut **une seule application** pour tout Repas, sans passer d'une application à l'autre. L'application charge donc le front Repas dans une WebView et lance le drive comme second écran (D28).

## Portée

1. **Activité principale** : WebView plein écran sur l'origine du front (`REPAS_ORIGIN`, constante de construction : production `https://kantin.eu/` ; variante de débogage configurable pour un autre hôte). JavaScript, stockage du DOM, service worker (par défaut), cookies conservés (le cookie `HttpOnly` de rafraîchissement du jeton doit survivre à la fermeture de l'application), contenu mixte refusé, `SafeBrowsing` actif.
2. **Navigation** : seule l'origine du front reste dans la WebView ; tout autre lien s'ouvre dans le navigateur du téléphone (`ACTION_VIEW`) ; aucun lien vers `*.leclercdrive.fr` ne s'ouvre dans cette WebView (le drive a son écran dédié).
3. **Confort** : bouton retour = retour arrière dans le front puis sortie ; page d'erreur en français avec « Réessayer » (hors connexion) ; marges sous les barres (`targetSdk` 36) ; couleur de la barre d'état issue du thème ; icône adaptative et écran de lancement aux couleurs de Repas (Basilic & safran, sans image externe) ; sélecteur de fichiers de la WebView (`onShowFileChooser`) si le front envoie des fichiers ou des photos (vérifier dans le front : sinon ne pas demander de permission).
4. **Pont natif** : `WebViewCompat.addWebMessageListener` (nom `repasNative`, origine autorisée `REPAS_ORIGIN` seulement) ; messages JSON bornés à 64 Ko : `app.info` → `{version, versionCode, drive: true}` ; `drive.start` et `drive.cancel` (traités par DRV-314) ; refus de toute autre origine ou forme. Aucun `addJavascriptInterface`.
5. **Liens entrants** : filtre d'intention `https://kantin.eu/…` avec `autoVerify` pour ouvrir les liens reçus par e-mail (confirmation d'adresse) dans l'application ; l'étape de publication de `/.well-known/assetlinks.json` (empreinte du certificat de signature) est **humaine** et notée dans `android/README.md`.
6. **Écran drive** : l'hôte WebView en mode ordinateur de DRV-312/313 devient `DriveActivity` (lancée par la coquille, retour vers elle) ; ses règles ne changent pas.
7. Menu minimal (version, « Se déconnecter de Leclerc et effacer les données du drive », mode d'emploi) et lien vers les réglages du drive du front.

## Hors portée

Le contenu des écrans du front (inchangé), la publication signée (DRV-315), le passage du plan (DRV-314).

## Critères d'acceptation

- Étant donné l'application installée, quand l'utilisateur l'ouvre, alors le front Repas s'affiche et il peut se connecter, consulter ses recettes et ses listes ; fermée et rouverte, il reste connecté tant que le rafraîchissement le permet.
- Un lien externe s'ouvre hors de l'application ; un lien `https://kantin.eu/…` ouvert depuis une autre application arrive dans l'application (après publication de `assetlinks.json`).
- Le pont refuse une origine autre que l'origine du front.

## Tests exigés

Tests Kotlin (règle de navigation, bornes et origines du pont, `app.info`) ; essais sur téléphone par l'utilisateur ; aucune clé ni secret dans le dépôt.

## Definition of Done

Celle du dépôt front (`make check`, `make android-test`, job APK de la CI) ; PR concise ; fusion si CI verte.

## Pièges connus

- La CSP de production du front peut bloquer des scripts de la WebView : ne rien relâcher sans ADR.
- Le service worker de production recharge la page à chaque nouvelle version : le brouillon de passage (DRV-404) doit survivre.
- La WebView du front ne doit PAS recevoir l'agent Leclerc ni les cookies de Leclerc (deux WebView séparées).
