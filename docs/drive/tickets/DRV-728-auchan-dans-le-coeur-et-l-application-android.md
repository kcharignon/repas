# DRV-728 — Auchan dans le cœur et l'application Android, éteint (navigation, pont, injection)

- **Dépôt** : front (`android/core/`, `android/app/`, `android/emulator/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-706, DRV-707, DRV-716
- **Statut** : fait (PR front #254, 2026-10-30). Auchan est **inscrite et éteinte** : dans `RetailerSites.SWITCHED_OFF`, absente de `RetailerSites.DEFAULT`, allumée seulement par `ANDROID_EXTRA_RETAILERS=auchan` (variante de **débogage**) ; la publication a le drapeau vide en dur (`verifyReleaseInputs`) et `build.sh` le contrôle. Écarts : aucun scénario d'émulateur d'Auchan ([DRV-731](DRV-731-scenario-emulateur-auchan.md)) ; les bandeaux de l'écran sont ceux de Leclerc ([DRV-733](DRV-733-bandeaux-de-l-ecran-par-enseigne.md)). Effort recommandé : `xhigh` (sécurité : navigation, origines du pont, livraison du plan).
- **Lire d'abord** : [`../spikes/DRV-715-decision-auchan.md`](../spikes/DRV-715-decision-auchan.md) § 2 points 5 à 7 ; [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) ; [`DRV-706`](DRV-706-hotes-autorises-par-enseigne-dans-le-coeur-kotlin.md) ; skill `repas-android-dev` ; [`../architecture.md`](../architecture.md) (sécurité du pont).

## Contexte

DRV-706 et 707 rendent l'application capable de plusieurs enseignes (table `RetailerSite`, origines et mémoires par enseigne, profil de WebView par enseigne). Ce ticket y **inscrit Auchan**, avec une règle plus fine que chez Leclerc : la page de **connexion** d'Auchan est sur un autre hôte (`compte.auchan.fr`) et elle porte le mot de passe.

## Portée

1. **`RetailerSite` d'Auchan** : navigation autorisée sur `https://www.auchan.fr` et `https://compte.auchan.fr` (et leurs chemins), **rien d'autre** ; accueil `https://www.auchan.fr/courses` ; mode du site `AS_IS` (pas de mode ordinateur permanent) ; pas de « page de magasin » à reconnaître (le magasin se lit par l'agent) ; page de connexion `compte.auchan.fr`.
2. **Pont et injection** : l'objet `repasHost` et l'agent n'existent **que** sur `https://www.auchan.fr` ; **jamais** sur `compte.auchan.fr`. Un plan d'Auchan n'est livré qu'à une page de `www.auchan.fr` ; un plan d'une autre enseigne sur cette page est refusé (`STORE_MISMATCH`).
3. **Profil** de WebView propre à Auchan (se déconnecter d'Auchan n'efface pas Leclerc) ; textes `%1$s` (DRV-707) ; entrée de menu seulement si l'enseigne est annoncée.
4. **`app.info.retailers`** annonce `auchan` **uniquement** si la variante embarque l'adaptateur (drapeau de construction) ; en production il reste `["leclerc"]` tant que DRV-726 n'est pas recetté.
5. **Tests d'adresses hostiles** : mêmes tables que celles de Leclerc (DRV-706) appliquées à Auchan : `https://www.auchan.fr.exemple.invalid`, `https://wwwauchan.fr`, `http://www.auchan.fr`, `https://user@www.auchan.fr`, `https://www.auchan.fr:8443`, `https://compte.auchan.fr` (navigation oui, pont **non**), majuscules, point final, IDN, `javascript:`, `intent:` ; **isolement à deux enseignes réelles** : une page de Leclerc n'est pas autorisée dans l'écran d'Auchan et inversement.
6. Scénario de l'émulateur (`android/emulator/`) avec un faux hôte d'Auchan **à venir** (DRV-717) : ici seulement la table et les tests unitaires.

## Hors portée

L'agent d'Auchan (DRV-717, 724, 725), le faux site, allumer Auchan, la recette sur téléphone (DRV-726).

## Critères d'acceptation

- Étant donné Leclerc seul annoncé, alors **aucun comportement ne change** (`make android-test` sans modification d'attente).
- Étant donné les adresses hostiles, alors elles sont refusées exactement comme chez Leclerc ; `compte.auchan.fr` n'obtient **jamais** l'injection ni le pont.

## Tests exigés

`make android-test`, `make android-e2e` (sous le verrou de l'émulateur), `make check`.

## Definition of Done

Celle du dépôt front et du skill `repas-android-dev` ; **aucune étiquette, aucune publication** ; ordre de publication dit dans le rapport (front avant application) ; dire ce qu'il faut cocher dans le back.

## Pièges connus

- Une origine mal écrite dans le pont est une **faille** : une règle large (`*.auchan.fr`) est interdite pour le pont ; seule la navigation connaît `compte.auchan.fr`.
- Les sous-ressources de la page (`api.auchan.fr`, `cdn.auchan.fr`, `dd.ecom.auchan.fr`, `google.com/recaptcha`) ne sont pas des navigations : ne pas les ajouter à la table de navigation ; leur bon fonctionnement en WebView est vérifié à la recette (DRV-726).

## Ce que DRV-706 a posé (2026-10-30)

`RetailerSite` (table `RetailerSites`) distingue, par hôte, la **navigation** et le **pont** (`SiteHost.bridge`) : Auchan = hôtes `www.auchan.fr` (pont) et `compte.auchan.fr` (navigation seule), mode `AS_IS`, sans adresse de page de magasin ni `loginPath` d'adresse de magasin, avec un `storeIdPattern` UUID ; `acceptsPlanOn` livre alors de toute page de `www`. Les noms de site sont un nom exact ou `*.domaine` (jamais le domaine nu, jamais `*.fr`). Gardes : `RetailerSitesArchitectureTest` et `android/__tests__/retailer-sites.spec.ts` (« leclerc » n'est écrit que dans `RetailerSites.kt`, la copie du contrat n'a pas dérivé).

## Ce que DRV-728 a posé (2026-10-30)

`RetailerSites.SWITCHED_OFF`, `RetailerSites.serving(flag)` et `REGISTERED` (interne, pour les tests) ; `ANDROID_EXTRA_RETAILERS` → `BuildConfig.EXTRA_RETAILERS` → `RepasApp.announced` (qui alimente `app.info` et le menu) puis `RepasApp.sites` (règles d'origine du pont, écrans, mémoires) ; `HostileAddresses.kt` (tables d'adresses hostiles partagées) et `android/__tests__/retailer-flag.spec.ts`. Navigation : `www.auchan.fr` et `compte.auchan.fr` (noms exacts) ; **pont et agent seulement sur `https://www.auchan.fr`** ; profil de WebView `drive-auchan`. Limite assumée : l'entrée d'Auchan est inerte dans les classes du cœur de tout APK (le cœur est commun) : « aucun `auchan.fr` dans l'APK » serait faux par construction, `build.sh` contrôle le drapeau à la place.
