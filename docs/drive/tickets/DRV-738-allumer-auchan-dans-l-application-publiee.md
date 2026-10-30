# DRV-738 — Auchan servie par l'application publiée

- **Dépôt** : front (`android/`), plus les docs du back
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-728, DRV-731, DRV-735
- **Statut** : fait (PR front #268 et docs du back #348, fusionnées le 2026-10-30 après la recette réelle DRV-726 et la validation par le propriétaire du texte des conditions d'Auchan). L'application publiée sert Auchan **à partir de la prochaine release de l'application** (`android-vX.Y.Z`) ; tant que `DRIVE_RETAILERS` ne l'allume pas, Repas ne la propose pas.
- **Lire d'abord** : [`DRV-728`](DRV-728-auchan-dans-le-coeur-et-l-application-android.md) ; [`DRV-731`](DRV-731-scenario-emulateur-auchan.md) ; [`DRV-735`](DRV-735-plan-d-essai-de-debogage-par-enseigne.md) ; `android/ARCHITECTURE.md` (registre des enseignes, drapeau de construction) ; [`../multi-enseignes.md`](../multi-enseignes.md) § 5 (R5 : une enseigne que l'application ne sert pas est retirée des candidates).

## Contexte

Depuis DRV-728 Auchan est **inscrite sans être servie** : elle est dans `RetailerSites.SWITCHED_OFF`, absente de `RetailerSites.DEFAULT`, et seule une construction de **débogage** l'allume (`ANDROID_EXTRA_RETAILERS=auchan`) ; `build.sh` refuse qu'une publication embarque une enseigne éteinte. Les essais réels du 2026-10-30 (issue #322, `hypotheses-site-reel-auchan.md` C-13) ont montré que l'agent lit le magasin et la recherche, écrit au panier et ne réécrit pas ce qui y est déjà. Il manque le dernier geste de code : que **l'application publiée** serve Auchan, pour que l'allumage par `DRIVE_RETAILERS=leclerc,auchan` (geste du propriétaire) atteigne les utilisateurs.

## Préalable vérifié : non-régression de Leclerc sur le vrai site (2026-10-30)

Test automatique de l'APK de débogage (agent 0.51.2, tout le chantier M7 fusionné) sur le téléphone de test, connecté à un compte Leclerc, panier réel non vide (24 articles) : diagnostic **OK**, lecture du magasin **OK**, lot de **12 recherches sur 12** (2,2 s en moyenne, prix et contenu net lus), essai au panier **OK** (farine : ajout, mise à 3, mise à 2, retrait ; sucre et sel : ajout, retrait ; chaque écriture `200`, quantité lue égale à celle demandée). Seule la comparaison « panier avant et après » sort KO : même nombre d'articles (24 avant et après) mais un total d'« avant » lu dans la page (`pageCartTotal`) différent du total d'« après » lu dans la dernière réponse. C'est un **défaut de lecture préexistant du test automatique** sur un panier non vide (la fonction n'a pas changé depuis le ticket L, #138), pas une fuite d'article : à corriger dans un ticket mineur (lire les deux totaux à la même source), sans lien avec Auchan. La recherche « courante » avec le suffixe de ville répond 400 chez Leclerc et le repli sans ville (variante e1) répond 200 : c'est le comportement déjà prévu (audit B, #129).

## À faire

1. **Registre** : Auchan passe de `SWITCHED_OFF` à `DEFAULT` (`RetailerSites.kt`, la seule liste) : l'application publiée annonce `["leclerc","auchan"]` dans `app.info`, propose « Ouvrir le drive Auchan » au menu, donne le pont et l'agent **seulement à `https://www.auchan.fr`**, jamais à `compte.auchan.fr` (DRV-728 : règle de sécurité intacte, ses tests aussi). Le mécanisme `ANDROID_EXTRA_RETAILERS` et `SWITCHED_OFF` **restent** pour la prochaine enseigne (vides, testés avec une enseigne fictive).
2. **`build.sh` et la construction** : les contrôles « une publication n'embarque aucune enseigne **éteinte** » restent vrais (le drapeau reste vide) ; ajouter le contrôle inverse : l'APK de publication **annonce** Auchan (`BuildConfig`/registre), sinon la publication échoue ; l'APK de débogage de recette n'a plus besoin du drapeau pour Auchan (il le garde pour les futures enseignes).
3. **Le back reste l'interrupteur** : l'application annonce Auchan, mais tant que `DRIVE_RETAILERS` ne l'allume pas, Repas ne la propose pas (R5, `selection.candidates`). Vérifier par un test du web (Vitest) qu'une enseigne annoncée par l'application mais éteinte par le back n'apparaît ni en tuile, ni en « Configurer un drive », ni dans l'écran d'envoi.
4. **Application plus ancienne** : une application qui n'annonce pas Auchan reste servie comme aujourd'hui (R5, « trop récente pour l'application » : message de mise à jour, jamais d'erreur).
5. **Émulateur** (`make android-e2e`, sous le verrou) : l'APK ordinaire sert Auchan ; le scénario 21 n'a plus besoin d'un second APK ; les scénarios qui affirment « l'APK ordinaire n'a pas Auchan » sont retournés, **sans affaiblir** la preuve du pont (jamais sur `compte`).
6. **Docs** : `android/README.md`, `android/ARCHITECTURE.md`, `docs/drive/multi-enseignes.md` (§ 11.4), `docs/drive/runbook.md` (« Auchan a changé »), skill `repas-ops` (`references/drive.md` : ordre de publication back, site, application, puis allumage).

## Critères d'acceptation

- `make check` et `make android-test` verts ; `make android-e2e` complet vert ; `build.sh` vérifie que la publication annonce Auchan.
- La PR n'est **pas** fusionnée avant la décision « allumer » de DRV-726 ; aucune étiquette, aucune publication, aucun déploiement par l'agent.

## Après la fusion (geste de l'assistant et du propriétaire)

Release du back puis du site (avec `DRIVE_RETAILERS=leclerc`), release de l'application (`android-vX.Y.Z`, à la demande du propriétaire), `APP_LATEST_VERSION` écrit par le propriétaire (`ecrire-app-version.sh`), puis **le propriétaire allume** `DRIVE_RETAILERS=leclerc,auchan` (`ecrire-drive-retailers.sh`). Retour arrière : `bash -s -- leclerc` (Auchan disparaît des candidates sans toucher à l'application).
