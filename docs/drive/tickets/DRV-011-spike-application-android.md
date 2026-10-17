# DRV-011 — Spike : application Android jetable (WebView + banc d'essai)

- **Dépôt** : front (`kcharignon/repas-front`), dossier `drive-agent/android-lab/` (outil jetable, supprimé après la décision G1)
- **Jalon** : M0 Découverte
- **Taille** : M
- **Dépend de** : DRV-009 (fait) ; accord de l'utilisateur pour installer un APK de test
- **Statut** : construit et publié (PR [repas-front#67](https://github.com/kcharignon/repas-front/pull/67), 2026-10-17), essais sur téléphone à faire par l'utilisateur : [rapport](../spikes/DRV-011-rapport.md)
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), [`../spikes/DRV-006-installation-favori.md`](../spikes/DRV-006-installation-favori.md) (suite), [`../spikes/DRV-007-requete-ajout-panier.md`](../spikes/DRV-007-requete-ajout-panier.md), `drive-agent/lab/` (le banc d'essai, `published/lab.js`), `CLAUDE.md` du front.

## Contexte

Chrome Android ne lance pas les favoris JavaScript sur le téléphone de l'utilisateur ; il a choisi une application Android. Avant de construire la vraie application, il faut savoir si une **WebView** Android peut afficher Leclerc Drive, laisser l'utilisateur s'y connecter, passer l'anti-robot DataDome, exécuter le banc d'essai et obtenir les mêmes résultats que Chrome de bureau.

## Portée

1. Une petite application Android jetable en Kotlin (une seule activité), sous `drive-agent/android-lab/`, qui n'a que la permission Internet :
   - une `WebView` plein écran, JavaScript et stockage du DOM actifs, cookies conservés entre deux lancements (cookies tiers acceptés dans la WebView), qui ouvre `https://www.leclercdrive.fr/` ;
   - navigation limitée à `https://*.leclercdrive.fr` : tout autre lien est ouvert dans le navigateur du téléphone ; les fenêtres `window.open` sont ouvertes dans la même WebView ;
   - un bouton flottant « Banc » (ou une entrée de menu) qui exécute dans la page courante le **banc d'essai** fourni comme actif de l'application (copie de `drive-agent/lab/published/lab.js` faite à la construction) ; le banc affiche son menu en superposition comme avec le favori ;
   - un réglage « agent utilisateur » avec deux choix, mémorisé : (a) celui de la WebView par défaut, (b) le même sans le marqueur `; wv` ; l'agent utilisateur utilisé est affiché dans l'écran de diagnostic (sans autre donnée) ;
   - l'écran reste allumé tant que l'application est au premier plan ; le bouton « retour » du téléphone navigue en arrière dans la WebView ;
   - un écran « Diagnostic » : version de l'application, version de la WebView, agent utilisateur utilisé, texte copiable (sans cookie ni donnée de compte).
2. Une construction automatisée : cible `make android-lab` (dans Docker, comme le reste du front : image avec JDK, SDK Android et Gradle) et un workflow GitHub Actions qui produit un **APK de débogage signé avec la clé de débogage** et le dépose comme artefact et dans une pré-version GitHub (`android-lab-<date>`), avec l'empreinte SHA-256 du fichier.
3. Une feuille de procédure en français simple, `drive-agent/android-lab/PROCEDURE.md` (une étape par numéro) : télécharger l'APK sur le téléphone, autoriser l'installation depuis cette source, installer, ouvrir ; se connecter à Leclerc **soi-même** ; choisir son magasin ; ouvrir la page de recherche « farine » ; toucher « Banc » ; faire les essais 1, 2, 3 et 3b ; essai de session (fermer et rouvrir l'application : reste-t-on connecté ?) ; essai avec l'autre agent utilisateur ; copier les résultats.
4. Un gabarit de rapport `docs/drive/spikes/DRV-011-rapport.md` dans le dépôt **back** (à ajouter par l'assistant après la PR du front).

## Hors portée

L'application finale. L'envoi de données hors de la page, tout enregistrement des identifiants ou des cookies, tout appel à l'API de Repas, tout lien entre Repas et l'application (plan, retour), toute publication sur Google Play.

## Spécification technique

- Kotlin, `minSdk` 26 (Android 8), une seule activité, aucune bibliothèque tierce au-delà d'AndroidX ; `androidx.webkit` autorisée pour l'injection au chargement du document si utile.
- L'actif `lab.js` est celui de `drive-agent/lab/published/lab.js` (même fichier, vérifié par SHA-384 à la construction : la construction échoue s'il diffère de l'empreinte attendue, notée dans un fichier du dossier). Exécution par `evaluateJavascript` dans la page courante au toucher du bouton ; aucune exécution automatique au chargement dans ce spike.
- Navigation : n'autoriser que `https` et les hôtes `leclercdrive.fr` et leurs sous-domaines (`*.leclercdrive.fr`). Le reste part dans un `Intent` `ACTION_VIEW`.
- Aucune journalisation d'adresse complète, de cookie ou de contenu de page.
- `allowFileAccess` désactivé, `setSafeBrowsingEnabled` laissé actif, débogage de la WebView (`setWebContentsDebuggingEnabled`) actif **uniquement** dans la version de débogage.
- Construction reproductible dans Docker ; version de l'application visible dans le diagnostic.
- Aucun contact avec Leclerc pendant le développement ni la CI : l'agent qui implémente ne lance pas l'application contre le vrai site ; seuls les essais de l'utilisateur le font.

## Critères d'acceptation

- Étant donné la PR fusionnée, quand on lance le workflow, alors un APK de débogage est disponible avec son empreinte SHA-256.
- Étant donné l'APK installé, quand l'utilisateur ouvre l'application, alors `leclercdrive.fr` s'affiche et il peut se connecter lui-même.
- Étant donné une page de magasin ouverte, quand l'utilisateur touche « Banc », alors le menu du banc s'affiche avec ses 13 lignes.
- Étant donné un lien vers un autre site, quand l'utilisateur le touche, alors il s'ouvre hors de l'application.
- `make check` du front reste vert ; la construction de l'APK a son propre job de CI.

## Tests exigés

Tests unitaires Kotlin de la règle de navigation (hôtes autorisés ou non) et du choix d'agent utilisateur. Le reste est vérifié par les essais de l'utilisateur sur son téléphone (DataDome et connexion ne s'automatisent pas).

## Definition of Done

Celle du dépôt front pour ce qui touche à `make check` et à la CI ; PR concise sans pied d'attribution ; fusion si la CI est verte. Rapport final : numéro de PR, lien de l'APK, empreinte, étapes pour l'utilisateur. Cocher la case DRV-011 de la roadmap dans le dépôt back (petite PR docs).

## Pièges connus

- Une WebView n'a pas les mêmes cookies que Chrome : l'utilisateur doit se connecter dans l'application.
- La WebView annonce `wv` dans son agent utilisateur ; DataDome peut y réagir. C'est un des deux points mesurés.
- Le code reçu de Leclerc ne doit jamais avoir accès à des objets natifs (`addJavascriptInterface`) dans ce spike : aucune passerelle native n'est exposée.
