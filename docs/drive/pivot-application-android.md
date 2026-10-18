# Pivot du 2026-10-17 : une application Android à la place du favori

**Statut** : **appliqué.** Décision G1 du 2026-10-17 : GO, forme A (spike DRV-011 réussi), distribution par APK signé hors Google Play (D26, D27) ; précision du 2026-10-18 : l'application est l'application Repas complète, coquille autour du front (D28, voir la fin du document). Les ADR 0022 et 0023 sont `Accepted`. Le texte qui suit garde le raisonnement d'origine (formes A et B, inconnues du spike) : il est historique.

## Pourquoi

Le principe de l'ADR 0022 est qu'un petit programme (l'**agent**) tourne **dans la page Leclerc**, dans la session de l'utilisateur, sans que Repas voie ses identifiants. Le moyen prévu était un favori JavaScript sous Chrome Android. Le 2026-10-17, les essais sur le téléphone de l'utilisateur ont montré que **Chrome Android ne lance aucun favori JavaScript** : les favoris `t2`, `t8` et `lab2` n'ont aucun effet, et `javascript:alert(1)` tapé dans la barre d'adresse lance une recherche Google ([DRV-006](spikes/DRV-006-installation-favori.md)). Les voies examinées :

1. **Firefox Android** (favori ou gestionnaire de scripts Violentmonkey) : possible, mais l'utilisateur devrait commander dans Firefox. Écartée par l'utilisateur ; ticket [DRV-010](tickets/DRV-010-essai-firefox-android.md) mis de côté.
2. **Mode assisté** (liens de recherche, pas de remplissage) : reste le repli permanent.
3. **Application Android** : **choisie par l'utilisateur** (« je choisis l'application Android »).

Question de l'utilisateur : « ça peut être une web app ? ». Une web app ou PWA seule ne suffit pas : elle reste une page d'un autre site que Leclerc, donc elle ne peut ni afficher Leclerc dans un cadre (`X-Frame-Options: DENY`, essai 5 du banc), ni lire ou piloter la page Leclerc, ni envoyer les cookies de connexion Leclerc. Il faut un **navigateur intégré que l'on contrôle** (une `WebView` Android), donc une application. En revanche l'application peut être **écrite avec des technologies web** (voir B).

## Ce qui ne change pas

- L'agent JavaScript (recherche, mise au panier, choix des produits, interface en Shadow DOM) et le **protocole v1** (messages `plan` et `result`) : l'agent est injecté dans la page Leclerc de la WebView au lieu d'être lancé par un favori.
- Le back (verrou `SENT`, contexte `Drive`, plans, choix, priorités, prix) et les écrans de Repas (liste envoyée, réglages, retour), sauf l'ouverture de Leclerc.
- Les invariants : aucun identifiant ni cookie Leclerc chez nous ni dans le back ; le back ne contacte jamais Leclerc ; l'agent est **embarqué dans l'application** (pas de code distant non vérifié) ; requêtes lentes, en série, à la demande.

## Deux formes d'application (à trancher après le spike)

**A. Application compagnon native (Kotlin), petite.** Une activité avec une WebView limitée à `*.leclercdrive.fr`. Repas (PWA) l'ouvre par un lien d'intention Android (`intent://…` avec le nom du paquet) ou un schéma `repasdrive://open#plan=…` (le plan voyage dans le lien, comme dans le protocole v1). L'agent s'exécute dans la WebView, puis l'application rouvre `https://kantin.eu/drive/retour#result=…` (le protocole v1 inchangé). Avantages : contrôle total de la WebView, application minuscule (permission Internet seule). Inconvénient : un second langage et un second dépôt ou dossier à maintenir.

**B. Repas empaqueté en application (Capacitor).** Le front Vue existant est emballé dans une application Android ; un greffon de navigateur intégré ouvre Leclerc et y injecte l'agent. Avantages : une seule application Repas, pas de liens entre applications, technologie du front. Inconvénients : le front entier devient un APK à distribuer ; la qualité du greffon d'injection et de messages est incertaine ; le jeton en mémoire et la CSP de production du front sont à revoir.

Recommandation provisoire : **A**, parce que le cœur du risque (la WebView face à DataDome et à la connexion) est le même dans les deux cas et que A l'éprouve avec le moins de code.

## Architecture cible commune

- **WebView restreinte** : JavaScript et stockage du DOM actifs, cookies de la WebView conservés entre deux ouvertures (l'utilisateur se connecte une fois), navigation limitée à `https://*.leclercdrive.fr` (tout autre lien s'ouvre dans le navigateur du téléphone), aucune permission autre qu'Internet, pas de fichiers ni de contacts.
- **Injection de l'agent** : le fichier `agent.js` construit par le dépôt front est un **actif de l'application** (version et empreinte vérifiées à la construction). Il est injecté au chargement des pages Leclerc par l'API de la WebView, avec un canal de messages réservé aux pages Leclerc.
- **Agent de page** : identique (Shadow DOM, récapitulatif, confirmation, phases A et B). Le message de plan vient de l'application (reçu de Repas), le résultat repart vers l'application, qui ouvre le lien de retour vers Repas.
- **Écran allumé** : drapeau natif de la fenêtre pendant un passage.
- **Finalisation de la commande** : possible dans la WebView (créneau, paiement) ou dans l'application Leclerc officielle : le panier est lié au compte, il suit (vérifié, DRV-002 ligne 13).
- **Mises à jour** : un nouvel APK à chaque évolution de l'agent ou de Leclerc (pour une distribution hors Google Play : mise à jour manuelle). Piste ultérieure : l'application télécharge `agent.js` depuis Repas et vérifie sa signature avant de l'injecter (clé publique dans l'application, clé privée hors du serveur).

## Inconnues (à lever par le spike DRV-011)

1. DataDome accepte-t-il la WebView (agent utilisateur, empreinte) ? Faut-il retirer le marqueur `wv` de l'agent utilisateur ?
2. La connexion Leclerc (identifiant, mot de passe, code reçu par e-mail) fonctionne-t-elle dans la WebView ?
3. Le script de l'agent s'exécute-t-il dans les pages Leclerc, et la recherche et l'ajout au panier répondent-ils comme dans Chrome de bureau (en-tête `zcwb`, adresse du panier sans ville, voir DRV-007) ?
4. La session reste-t-elle ouverte entre deux lancements ?
5. Le passage du plan de Repas vers l'application (lien d'intention depuis la PWA) et le retour fonctionnent-ils ?
6. Distribution : fichier APK installé à la main (sources inconnues, mises à jour manuelles) ou Google Play (test interne, compte développeur payant) ? Google annonce une vérification d'identité des développeurs, même hors Play Store, par étapes à partir de fin 2026, avec un compte gratuit à distribution limitée (environ 20 appareils) pour les amateurs ; la date pour la France et les modalités sont à vérifier avant de choisir.

## Impact sur le découpage

- **Remplacés** : DRV-310 (publication du favori), DRV-403 (installation du favori dans les réglages), DRV-408 (aide du favori) deviennent « application Android ». DRV-402 (`driveBridge`) ouvre l'application au lieu de Leclerc dans Chrome.
- **Inchangés** : DRV-101 à DRV-105, DRV-201 à DRV-208, DRV-302 à DRV-309 (l'agent), DRV-401, DRV-404, DRV-405, DRV-410.
- **Nouveaux**, à écrire une fois le spike réussi : squelette et construction de l'application, hôte WebView et injection, passage du plan et retour, publication et mise à jour (APK ou Play), page d'aide d'installation, tests (faux site Leclerc pour la partie JavaScript, essais sur téléphone pour la partie native).
- **Spike** : [DRV-011](tickets/DRV-011-spike-application-android.md).

## Premiers résultats du spike (2026-10-17, soir)

Détail : [`spikes/DRV-011-rapport.md`](spikes/DRV-011-rapport.md). Sur un Pixel 9 (WebView 155) :

- L'application s'installe, l'utilisateur se connecte, le banc d'essai s'exécute dans les pages Leclerc.
- **Leclerc sert au téléphone son site mobile** (`m-courses.leclercdrive.fr`, adresses `/magasin-…/recherche/<mot>`) : la recherche du banc n'y trouve rien, ses routes sont autres que celles du site PC.
- **En mode « Ordinateur »** (agent utilisateur Linux de bureau et indications client cohérentes, fenêtre large, comme la « version pour ordinateur » de Chrome), Leclerc sert le site PC (`fdN-courses…`) et le banc obtient les mêmes résultats que sur ordinateur : 26 produits reconnus, ajout HTTP 200, quantité absolue (3 obtenu). Aucun blocage anti-robot.
- Décision D25 (proposée) : l'application fait tourner Leclerc **en mode ordinateur** pour réutiliser le contrat déjà mesuré (recherche, panier, en-tête `zcwb`). Le contrat du site mobile n'est relevé que si ce mode cesse de fonctionner (relevé par câble USB et `chrome://inspect`).
- Reste à mesurer : persistance de la session, lot de 30 recherches sur téléphone, finalisation de la commande sur le site PC affiché sur un écran de téléphone (ergonomie), lancement depuis la PWA Repas.

## Précision du 2026-10-18 : l'application est l'application Repas complète

L'utilisateur a précisé le but final : une seule application Android pour **tout** Repas (recettes, listes, ingrédients, envoi au drive…), sans jamais passer d'une application à l'autre. La « petite application compagnon » prévue n'est donc plus la forme visée (décision D28).

**Forme retenue : une coquille native autour du front Repas** (variante B du plan, mais sans Capacitor).
- L'application « Repas » est une activité Kotlin avec une WebView qui charge le front Repas (`https://kantin.eu/`, l'origine est une constante de construction). Le front reste la seule source de vérité de l'interface : une mise à jour du site est visible tout de suite, sans nouvel APK ; l'APK ne change que pour la coquille ou l'agent.
- Le **drive** est un second écran de la même application (`DriveActivity` : l'hôte WebView en mode ordinateur de DRV-312/313, avec l'agent injecté). Repas lui parle par un **pont natif** (`repasNative`, `addWebMessageListener`, origine `https://kantin.eu` seulement, aucun `addJavascriptInterface`) : `drive.start` avec le plan, réponse `drive.result`. Plus de lien `repasdrive://` ni de fragment d'adresse : DRV-314 et DRV-402 sont révisés en conséquence.
- Hors de l'application (navigateur ordinaire), le front détecte l'absence du pont et propose d'installer l'application ou le mode assisté.
- Conséquences : distribution plus importante (toute la famille l'utilise au quotidien) → mises à jour signalées (DRV-315) et option Google Play à reconsidérer ; liens `https://kantin.eu/…` reçus par e-mail (confirmation d'adresse) à ouvrir dans l'application (App Links : fichier `/.well-known/assetlinks.json` à publier par le front, étape humaine) ; fichiers et photos si le front en envoie (sélecteur de fichiers de la WebView).
- Nouveaux tickets : [DRV-317](tickets/DRV-317-coque-application-repas.md) (coquille), [DRV-318](tickets/DRV-318-test-automatique-application.md) (test automatique en un geste pour la recette sur téléphone). Révisés : [DRV-314](tickets/DRV-314-passage-du-plan-et-retour.md), [DRV-402](tickets/DRV-402-module-drive-bridge.md), [DRV-316](tickets/DRV-316-aide-installation-application.md), [DRV-403](tickets/DRV-403-reglages-drive-ui.md).
