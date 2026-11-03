# DRV-765 — La session de Repas saute à la mise à jour de l'application

- **Dépôt** : front (`android/`)
- **Jalon** : M7 Plusieurs enseignes (remontée du propriétaire pendant la recette de 1.6.5)
- **Taille** : S
- **Dépend de** : rien
- **Statut** : livré dans l'application **1.6.6** (front PR #318, 2026-11-02) ; **reste à vérifier sur le Pixel 9 du propriétaire** à la mise à jour suivante (de 1.6.6 vers une version plus récente : la 1.6.5 vers 1.6.6 passe encore par l'ancien code)
- **Lire d'abord** : [ADR 0015](../../adr/0015-refresh-token-du-spa.md) (cookie `repas_refresh` à rotation), `android/app/src/main/kotlin/eu/kantin/repas/drive/MainActivity.kt` (`onPause`), `android/app/src/direct/kotlin/eu/kantin/repas/drive/SelfUpdateDirect.kt` (`openInstaller`)

## Contexte

Le propriétaire doit **retaper son mot de passe de Repas à chaque mise à jour de l'application** (constaté sur son Pixel 9 à la mise à jour vers 1.6.5, 2026-11-02). Sur le téléphone de test (installation par `adb install -r`), la session de Repas survit aux mises à jour 1.6.3, 1.6.4 et 1.6.5.

## Constat mesuré (journal d'accès du serveur, heures UTC, appareil identifié par son agent utilisateur)

| Heure | Requête | Statut |
|---|---|---|
| 10:54:55 | `POST /api/auth/refresh` (lancement de la 1.6.4) | 200 |
| 10:55:02 | `POST /api/auth/refresh` (7 secondes plus tard) | 200 |
| 10:55:06 | `POST /api/auth/refresh` ×2 (l'application est maintenant en 1.6.5) | **401** |
| 10:55:12 | `POST /api/login_check` | 200 (la personne retape son mot de passe) |

Le cookie `repas_refresh` est **à rotation** (ADR 0015) : chaque usage invalide le jeton présenté et en pose un neuf ; un jeton déjà utilisé est refusé en `401`, **le cookie est effacé dans le téléphone**, et au-delà de 10 secondes la famille entière est supprimée. Le site échange son cookie à **chaque chargement de page** (le jeton d'accès n'est que en mémoire). Au redémarrage après l'installation, la WebView a donc présenté un cookie **périmé** : celui du disque était d'avant la dernière rotation.

## Cause probable (déduite du code, à prouver par la reproduction)

L'application n'écrit les cookies de la WebView sur le disque (`CookieManager.getInstance().flush()`) que dans `MainActivity.onPause()`. Une mise à jour lancée depuis l'application ouvre l'installateur d'Android (`ACTION_INSTALL_PACKAGE`), puis le système installe et **tue le processus** quelques secondes plus tard. Une rotation qui survient **entre le dernier `flush()` et l'arrêt du processus** (ici le second chargement de page à 10:55:02, 4 secondes avant l'arrêt) n'arrive jamais sur le disque. Sur le téléphone de test, l'application est en arrière-plan et sans rotation récente : le cas ne se présente pas.

## À faire

1. Reproduire d'abord le défaut dans l'émulateur du dépôt (faux Repas qui fait tourner un cookie persistant à chaque chargement, arrêt forcé quelques secondes après, relance, comparaison du cookie relu avec le dernier émis), comme scénario de `make android-e2e` si c'est faisable ; sinon reproduction à la main.
2. Écrire les cookies sur le disque aux moments utiles : juste **avant** d'ouvrir l'installateur ; dans `onStop` en plus de `onPause` ; peu après la fin du chargement d'une page de Repas (flush retardé et réarmé, un seul en attente) pour couvrir la rotation du démarrage ; au retour des réglages d'Android où l'installation est autorisée.
3. Ne changer ni le site, ni l'agent, ni le pont, ni le back.

## Ce qui a été trouvé en relisant (et corrigé avant la fusion)

La cause du paragraphe « Cause probable » était incomplète. Écrire les cookies avant d'ouvrir l'installateur ne suffit pas : la relecture indépendante a **mesuré** qu'une page cachée par `webView.onPause()` continue d'exécuter ses minuteries et ses requêtes (`fetch`) et **fait encore tourner le cookie**. L'ancien code écrivait déjà à `onPause` ; la rotation perdue arrive donc **après** la pause, pendant que l'installateur est affiché. Le déclencheur le plus probable est le rechargement automatique du service worker du site (`src/pwaUpdate.ts`) après une nouvelle version du site (v1.19.2 la veille) : non prouvé.

La correction livrée (1.6.6) :

- `SessionCookies` (cœur Kotlin pur, `CookieFlusher`) : écriture à la mise en pause **puis toutes les 500 ms tant que l'écran est en pause** (toutes les 5 s au-delà de 2 minutes), arrêtée au retour au premier plan et à la destruction ; en plus, écriture à `onStop`, 2 secondes après la fin de chaque page et avant l'ouverture de l'installateur (l'intention, que la pause qui suit répète) ;
- scénarios 22a (rotation du chargement, arrêt 3 s après) et 22b (rotation faite par la page cachée pendant la pause, arrêt 1 s après) de `make android-e2e` : **22b échoue sans la boucle et réussit avec** (3 sur 3) ; `am force-stop` joue l'arrêt du processus mais n'est pas le vrai installateur d'Android.

## Limite restante

Un processus arrêté entre deux écritures perd la rotation survenue depuis la dernière, au plus une période de la boucle (500 ms, 5 s après deux minutes de pause). Un plantage hors pause n'est couvert que par l'écriture après chaque page. `DriveActivity` (enseignes isolées) écrit à `onPause` et `onStop`, sans boucle.

## Non vérifié

- Sur un vrai téléphone avec une vraie mise à jour depuis l'application : à rejouer sur le Pixel 9 du propriétaire à la mise à jour suivante.
- Pistes laissées de côté, à décider si le défaut persiste : côté back, une réponse `401` « jeton utilisé il y a moins de 10 secondes » **efface quand même le cookie** du client (ADR 0015 § 8), ce qui peut effacer le cookie neuf qu'une requête concurrente vient de poser (course entre deux onglets) ; changer cela demande un amendement de l'ADR.

## Livraison

Front (application seule) : PR, relecture indépendante, fusion si la CI est verte, étiquette `android-vX.Y.Z`, approbation et `APP_LATEST_VERSION` par le propriétaire.
