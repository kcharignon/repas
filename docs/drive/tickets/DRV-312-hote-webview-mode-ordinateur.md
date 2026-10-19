# DRV-312 — Hôte WebView en mode ordinateur

- **Dépôt** : front (`kcharignon/repas-front`), dossier `android/`
- **Jalon** : M3b Application Android
- **Taille** : M
- **Dépend de** : DRV-311
- **Statut** : fait (PR [repas-front#84](https://github.com/kcharignon/repas-front/pull/84), 2026-10-18)
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), [`../spikes/DRV-005-decision-g1.md`](../spikes/DRV-005-decision-g1.md), [`../spikes/DRV-011-rapport.md`](../spikes/DRV-011-rapport.md), `drive-agent/android-lab/` (le spike, `PROCEDURE.md` compris), `CLAUDE.md` du front

## Contexte

L'application affiche Leclerc Drive dans une WebView **toujours en mode ordinateur** (le site mobile `m-courses` a d'autres routes que le contrat mesuré). L'utilisateur s'y connecte lui-même et y reste connecté.

## Portée

1. Écran principal : une WebView plein écran qui ouvre l'accueil du drive (`https://www.leclercdrive.fr/`), ou, quand l'application est lancée avec un plan (DRV-314), la page du magasin connu.
2. Mode ordinateur permanent : agent utilisateur de bureau cohérent (`X11; Linux x86_64`, version majeure de la WebView lue au démarrage) et indications client cohérentes via `androidx.webkit` (plateforme Linux, mobile faux), fenêtre large, zoom au pincement ; si les indications client ne sont pas disponibles, l'application continue avec la seule chaîne et le note dans le journal de l'application.
3. Cookies conservés d'un lancement à l'autre (cookies tiers acceptés dans la WebView : le défi anti-robot est un cadre), vidage possible par « Se déconnecter et effacer les données ».
4. Navigation limitée à `https` et `*.leclercdrive.fr` (règle déjà testée dans le spike) ; tout autre lien s'ouvre dans le navigateur du téléphone ; les cadres peuvent charger librement (anti-robot) ; `window.open` ouvre dans la même WebView.
5. Écran allumé tant que l'application est au premier plan pendant un passage ; bouton retour du téléphone = retour arrière dans la WebView, puis sortie.
6. Messages d'erreur en français (hors connexion, page indisponible, certificat) avec un bouton « Réessayer ».
7. Menu : « Version et mode d'emploi », « Se déconnecter et effacer les données », « Ouvrir dans le navigateur ».
8. Aucune lecture ni journalisation de cookie, de contenu de page ou d'adresse complète.

## Hors portée

L'agent (DRV-313), le passage du plan (DRV-314).

## Critères d'acceptation

- Étant donné l'application installée, quand l'utilisateur l'ouvre, alors il voit le drive en mode ordinateur (hôte `fd…`) et peut se connecter.
- Étant donné qu'il s'est connecté, quand il ferme et rouvre l'application, alors il est encore connecté.
- Un lien hors `*.leclercdrive.fr` s'ouvre hors de l'application.

## Tests exigés

Tests Kotlin : règle de navigation, chaîne d'agent utilisateur pour plusieurs versions, bascule en cas d'indications client indisponibles. Essais sur téléphone par l'utilisateur pour la connexion et la session (recette).

## Definition of Done

Celle du dépôt front ; PR concise ; fusion si CI verte.

## Pièges connus

- Les caractéristiques de l'appareil (`navigator.platform`, tactile) restent celles d'un téléphone ; le spike n'a pas montré de blocage.
- Ne pas exposer d'objet natif aux pages Leclerc (voir DRV-313).

## Laissé par DRV-311 (2026-10-17)

À faire dans ce ticket : ajouter la permission Internet et `androidx.webkit` (absents du squelette) ; brancher `NavigationPolicy` et `UserAgent` (présents mais pas encore reliés à l'écran) ; gérer les **marges** autour de la WebView (`targetSdk` 36 : affichage bord à bord) ; reprendre le `build.sh` de `android/` tel quel.

## Précision (2026-10-18, DRV-308)

Le menu de l'application propose « Diagnostic de l'agent » : il déclenche le diagnostic par un message de commande sur le pont (DRV-313), pas par un fragment d'adresse.

## Réalisation (2026-10-18, PR repas-front#84)

Hôte WebView plein écran en mode ordinateur (agent utilisateur et indications client cohérents, repli sans indications client), navigation limitée à `*.leclercdrive.fr`, cookies conservés, retour par `OnBackInvokedCallback` (nécessaire en `targetSdk` 36), marges tenant compte des barres, de l'encoche et du clavier, erreurs en français avec « Réessayer », menu. L'écran reste allumé dès que l'application est au premier plan (DRV-314 pourra le restreindre à un passage). Rien n'a été essayé sur appareil.

## Réalisation : corrections de la recette réelle (2026-10-18 et 19)

- [repas-front#126](https://github.com/kcharignon/repas-front/pull/126) : la page du drive est une fenêtre de ~980 px CSS sur ~411 dp d'écran, donc minuscule ; **aucun réglage de zoom de la WebView ne change cela** (fenêtre large, vue d'ensemble, pincement, double toucher : mesurés dans l'émulateur). L'interface de l'agent est agrandie par l'agent lui-même (voir DRV-304) ; le menu du drive gagne « Agrandir la page » et « Réduire la page » (`zoomBy` ×1,5). `textZoom`, `TEXT_AUTOSIZING` et `setInitialScale` sont écartés et documentés dans `android/README.md` du front (« La lisibilité en mode ordinateur »).
- [repas-front#137](https://github.com/kcharignon/repas-front/pull/137) : `NavigationPolicy.isMobileSite` ; `isStorePage` est faux pour le site mobile même avec une adresse `/magasin-N-N…` (bandeau « Leclerc affiche son site mobile »).
- Lignes du jalon : [`roadmap.md`](../roadmap.md) § M3c.
