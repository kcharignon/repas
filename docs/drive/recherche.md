# Recherche : remplir un panier de drive (E.Leclerc)

Faits trouvés le **2026-10-17** par recherche web et lecture du code. Chaque fait porte sa source. Ce qui n'a pas pu être vérifié est dit comme tel (§ 9). À re-vérifier avant de s'appuyer dessus : le site de Leclerc change sans préavis.

## 1. Existe-t-il une API publique ?

**Non.** Aucun portail développeur ni API partenaire d'E.Leclerc Drive n'a été trouvé.
- [Pepesto – Leclerc](https://www.pepesto.com/supermarkets/leclerc/) : « Leclerc does not offer a public developer API » (page d'un produit tiers non lancé, source secondaire).
- [KFMichael/site-anniversaire PR #18](https://github.com/KFMichael/site-anniversaire/pull/18) : « Carrefour et Leclerc n'ont pas d'API publique ». Ce projet a choisi de **ne pas automatiser** : un lien de recherche par produit, mémorisation du lien et du prix, l'utilisateur ajoute lui-même au panier.
- Existent : des scrapers payants ([Apify studio-amba](https://apify.com/studio-amba/leclerc-scraper), [Apify abotapi](https://apify.com/abotapi/leclerc-fr-scraper/api), [Piloterr](https://www.piloterr.com/library/eleclerc-search)) qui lisent le catalogue, sans remplir le panier d'un utilisateur.

## 2. Routes internes du site (rétro-ingénierie par des tiers)

Source : [skunkobi/mcp-leclerc-drive, docs/api-capture.md](https://github.com/skunkobi/mcp-leclerc-drive/blob/main/docs/api-capture.md), validé le **2026-06-13** sur un magasin (`053701`).

- **Hôte par magasin** : `fdN-courses.leclercdrive.fr` (`fd9`, `fd8`, `fd14`…) et chemin `magasin-{id}-{id}`. Le slug de ville dans les URL est cosmétique. La session est liée à **un seul drive**.
- **Annuaire des magasins** : `api-recherchemagasins.leclercdrive.fr/API_RechercheMagasins/api/v1` avec `/autocomplete`, `/autocomplete/coordinates`, `/MapPoint/nearby` (champs `noPL`, `serviceType`, `distance`, hôte du magasin).
- **Recherche** : `GET …/recherche.aspx?TexteRecherche={requête}`. Page HTML rendue côté serveur (ASP.NET), **sans appel XHR de recherche** : les produits sont dans le JSON d'initialisation de widgets. Champs : `iIdProduit` (identifiant utilisé pour le panier), `sLibelleLigne1`, `sLibelleLigne2`, `nrPVUnitaireTTC` / `sPrixUnitaire`, `sPrixPromo`, `nrPVParUniteDeMesureTTC` / `sPrixParUniteDeMesure`, `iQteDisponible` (0 = indisponible), `iQuantitePanier`, `sUrlVignetteProduit`, `iIdRayon`, `iIdFamille`, `niIdSousFamille`. Images : `fd9-photos.leclercdrive.fr/image.ashx?id={photoId}&use=l&cat=p` (l'identifiant de photo diffère de `iIdProduit`). **Pas d'EAN** dans la recherche.
- **Panier** : `POST …/panier.aspx?op=1`, un champ de formulaire `d` contenant du JSON encodé : `eTypeAction` (1 ajoute ou augmente, 2 diminue ou retire), `iIdProduit`, `iQuantite` (**quantité absolue cible, pas un delta** ; retrait = `eTypeAction: 2` avec `iQuantite: 0`), `sNoPointLivraison`, et un objet d'analytique facultatif (`objContexteProvenanceArticle`, vérifié seulement pour le retrait). Réponse : un tableau d'évènements dont `Panier{magasin}` (`eTypeEvenement` 1) porte les totaux.
- **Constat du 2026-10-17 (banc d'essai, ordinateur, hôte `fd7`)** : cette requête, envoyée telle quelle depuis la page du magasin (en-têtes `X-Requested-With` et `Content-Type` comme ci-dessus, sans objet d'analytique), répond **HTTP 404** avec la page d'erreur « ce service est momentanément indisponible » et n'ajoute rien. Ce n'est pas un 403 d'anti-bot. La source tierce date de juin 2026 et a été validée sur un autre magasin : la route a pu changer ou exiger un élément qu'elle ne décrit pas. À lever par DRV-007 ([rapport](spikes/DRV-007-requete-ajout-panier.md)). La recherche, elle, répond comme décrit (200, JSON embarqué, sans EAN).
- **Lecture du panier** : pas de GET propre (`panier.aspx` sans `op` renvoie 404) ; le panier est embarqué dans le HTML des pages du magasin (`lstProduits`, `lstProduitsLight`, `sTotalAPayer`).
- Points ouverts de la source : omission de l'objet d'analytique à l'ajout, lecture propre du panier, durée de vie du cookie DataDome, changement de drive côté serveur, réservation de créneau et validation (hors périmètre).

## 3. Anti-bot DataDome

- Les requêtes HTTP « nues » sont bloquées. Constaté pendant cette recherche : une lecture automatique de `https://www.leclercdrive.fr/` a reçu un **HTTP 403** ; `robots.txt` est passé.
- Source (même document) : les requêtes dépendent d'une session de navigateur avec cookies dont `datadome` ; **le rejeu de cookies a cessé de fonctionner** quand DataDome est passé en mode actif ; des **écritures parallèles** ont déclenché un blocage (403 en lecture et en écriture pour ce client, levé en rechargeant le site dans Chrome). Recommandations : sérialiser, espacer, cadence « humaine ».
- [ncleton/leclerc-drive-mcp](https://github.com/ncleton-petitmaker/leclerc-drive-mcp) : le site « bloque les requêtes HTTP nues » ; le projet pilote un vrai navigateur local (Camoufox) dans la session de l'utilisateur, **qui se connecte lui-même** (identifiants et codes saisis à la main). Les modifications du panier passent par une étape de confirmation (jeton valable 5 minutes). L'outil ne choisit jamais de créneau, ne valide ni ne paie. Il est présenté comme non officiel, sous licence MIT, l'utilisateur étant responsable du respect des conditions du service.
- Intermarché est aussi derrière DataDome (pages client-side, prix après choix du magasin) : [Apify Intermarché](https://apify.com/studio-amba/intermarche-fr-scraper), [scrape.do](https://scrape.do/industries/ecommerce/intermarche-scraper/). Auchan : rien de documenté pour le panier ([Spider](https://spider.cloud/scrapers/auchan-scraper/), `auchand-check` sur npm = créneaux seulement).

## 4. Conditions d'utilisation et droit

- [robots.txt de leclercdrive.fr](https://www.leclercdrive.fr/robots.txt) (lu le 2026-10-17) : `Allow: /` ; exclus : `/Statistiques/`, `/.well-known/`, `/_res/release/`, `/mobile/conseils-drive/` ; sitemaps `sitemap.aspx` et `sitemap-contenu-editoral.aspx`. Indicatif, **pas une autorisation**.
- **CGU/CGV non lues** : la [page légale](https://www.e.leclerc/e/legal) n'est pas lisible par un outil automatique. À lire par l'utilisateur (ticket DRV-003). Les [CGU du Compte E.Leclerc](https://media.e.leclerc/LEN/document/CGU%20Compte%20E.Leclerc%20converg%C3%A9%2023032026.docx?vh=beaf54&func=proxy) citent `leclercdrive.fr` pour « Mon Compte E.Leclerc » sans rien dire de l'automatisation d'après l'extrait vu.
- **Droit** : CJUE, *Ryanair c. PR Aviation*, C-30/14, 15 janvier 2015 ([résumé SCL](https://www.scl.org/3280-the-cjeu-takes-flight-databases-and-air-fares/)) : pour une base de données non protégée par le droit d'auteur ni par le droit sui generis, l'exploitant peut limiter par contrat, via ses conditions d'utilisation, l'extraction automatisée. Les CGU comptent donc même si `robots.txt` autorise. Ce n'est pas un avis juridique.

## 5. Ce que font les autres

- **Jow** : recette vers panier chez Leclerc, Auchan, Carrefour, Intermarché, Chronodrive, Monoprix ; il faut « associer une enseigne à son compte » ; les prix sont ceux du magasin ; frais de service rapportés par des avis ; pannes de connexion Leclerc signalées en 2026 ([App Store](https://apps.apple.com/fr/app/jow-recettes-menus-courses/id1301257625), [PC Astuces](https://www.pcastuces.com/apps/jow.htm), [signalements](https://www.totalbug.com/jow/)). L'intégration technique n'est pas documentée.
- **Mealz** : recettes personnalisées et ajout en un clic dans le parcours d'achat des sites de drive, vendu aux enseignes ([Welcome to the Jungle](https://welcometothejungle.com/fr/companies/miam/culture-1)). **MealMe** (autre société, États-Unis) : API de panier ([API Evangelist](https://apis.apievangelist.com/store/mealme-carts-api/)).
- Programme d'affiliation Awin « E.Leclerc FR » repéré (page `ui.awin.com/merchant-profile-terms/15135`, inaccessible le jour de la recherche) : périmètre non vérifié.

## 6. Mobile et navigateurs

- **Chrome Android** : toujours pas d'extensions ([ExtensionBooster](https://extensionbooster.net/blog/chrome-extensions-android-2026-what-works-guide/), guide commercial). Une version de bureau de Chrome pour Android est en test (2025).
- **Firefox Android** : extensions oui ([KultureGeek](https://kulturegeek.fr/news-301700/firefox-android-supporte-desormais-plus-450-extensions)), mais la version stable n'installe que depuis le catalogue officiel ; l'installation d'un fichier signé hors catalogue n'existe qu'en Nightly, à re-vérifier ([forum Mozilla](https://discourse.mozilla.org/t/install-an-unlisted-extension-in-firefox-android/137802)). Publier une extension = listing public.
- **Safari iPhone** : extensions web depuis iOS 15 via une app enveloppe construite avec Xcode ([Apple](https://developer.apple.com/safari/resources)) ; compte développeur payant.
- **Favori JavaScript** : sur Chrome Android, on le lance en tapant son nom dans la barre d'adresse et en choisissant l'entrée « javascript: » ([LabNol](https://labnol.org/software/google-chrome-bookmarklets/27894)) ; Safari iOS est bricolé ([schieb.de](https://www.schieb.de/?p=719396)). Une politique de sécurité (CSP) sans script en ligne peut bloquer un favori : Firefox le fait ([bug Mozilla 866522](https://bugzilla.mozilla.org/show_bug.cgi?id=866522), ancien) ; WebKit applique `connect-src` à ses requêtes ([bug 156106](https://bugs.webkit.org/show_bug.cgi?id=156106)). **Aucune source récente ne tranche : à tester sur le téléphone réel.**
- **Pont page ↔ extension** (si extension un jour) : Chrome offre `externally_connectable`, Firefox non ([MDN](https://developer.mozilla.org/docs/Mozilla/Add-ons/WebExtensions/manifest.json/externally_connectable), bug 1319168) : relais par script de contenu et `window.postMessage`. En Manifest V3, les requêtes inter-origines des scripts de contenu sont restreintes ([Chromium](https://new.chromium.org/Home/chromium-security/extension-content-script-fetches/)).
- **Distribution Chrome Web Store** : visibilité « non répertoriée » possible ; but unique, permissions minimales, champs de confidentialité ([Chrome](https://developer.chrome.com/docs/webstore/cws-dashboard-distribution)).

## 7. Données ouvertes (non retenues pour l'instant)

[Open Prices](https://github.com/openfoodfacts/open-prices) / [data.gouv.fr](https://data.gouv.fr/fr/datasets/open-prices) et Open Food Facts : ni identifiants Leclerc ni panier. Utiles plus tard pour un EAN ou la nutrition.

## 8. Constats sur notre code (lus le 2026-10-17)

- Lignes de liste : `ShoppingListRow` = `ingredientSlug`, quantité (float) **dans l'unité d'achat par défaut de l'ingrédient**, `unitSlug`, `checked`. Sur 256 ingrédients communs : grammes 99, unité 59, paquet 42. `ShoppingListRowDTO` n'expose ni slug d'ingrédient ni slug d'unité.
- `Unit` : `slug`, `name`, `symbol` seulement (25 unités dans `src/Repas/Infrastructure/DataFixture/Data/unit.json`) ; aucune notion de dimension. Aucun concept d'EAN, de marque, de prix ou de produit nulle part.
- Pas de chiffrement au repos (ni Defuse, ni coffre, ni type Doctrine chiffré, ni `config/secrets`) ; secrets en variables d'environnement (`/opt/repas/.env`, mode 600) ; `pg_dump` complets sur l'hôte (14 conservés) ; `ext-sodium` et `ext-openssl` présents dans l'image.
- Pas de worker, pas de tâche planifiée applicative ; Messenger synchrone (`command.bus` transactionnel, `query.bus`) ; serveur de 1 Go de RAM, `max_execution_time` de 30 s. Un appel HTTP dans un handler garde la transaction ouverte.
- Le front : jeton d'accès en mémoire uniquement, cookie de rafraîchissement `repas_refresh` (`HttpOnly`, `Secure`, `SameSite=Strict`, `Path=/api/auth`) ; CSP de production `default-src 'self'; script-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'…` (aucune image externe) ; service worker de production qui recharge la page quand il détecte une nouvelle version ; lecture hors-ligne de la liste en cours.
- Le front documente (ADR 0003, 0006) « téléphone = consulter et cocher en magasin, bureau = planifier » : cette fonction va à l'encontre, à amender.

## 9. Non vérifié, à lever par les spikes

DataDome face à un favori sur téléphone · CGU de Leclerc · CSP, `X-Frame-Options` et COOP des pages recherche et panier · lancement d'un favori dans le Chrome de l'utilisateur · ouverture dans Chrome complet depuis la PWA installée · repérage fiable du produit ajouté dans un cadre · identifiants de produit stables entre magasins · EAN sur la fiche produit · contenu net déductible · persistance du panier entre site et appli · adresse de la page panier · qualité de la recherche texte sur nos ingrédients · stabilité du site dans le temps · canal partenaire éventuel.
