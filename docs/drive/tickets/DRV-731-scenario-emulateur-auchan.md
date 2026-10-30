# DRV-731 — Scénario d'émulateur pour la lecture du magasin d'Auchan

- **Dépôt** : front (`android/emulator/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-717 (faux site d'Auchan), DRV-729 (scénarios réalignés), DRV-728 (Auchan dans l'application, variante de débogage)
- **Statut** : fait (PR front #266, 2026-10-30) : scénario 21 de `make android-e2e` (un second APK de débogage `ANDROID_EXTRA_RETAILERS=auchan` est construit par le banc ; DNS, certificat et faux Auchan dans l'émulateur) ; `make android-e2e` complet vert en 993 s. Prouvé : menu et `app.info` (`["leclerc","auchan"]`), écran sur `www.auchan.fr/courses` du faux site, **pont seulement sur `www`** (jamais sur le faux `compte.auchan.fr`), diagnostic de l'agent (magasin lu ; sans magasin : `STORE_NOT_CHOSEN`), aucune requête vers Internet. Non prouvable dans l'émulateur : le profil de WebView `drive-auchan` (WebView 113 sans `MULTI_PROFILE`) : jugé par `ScreenRetailerTest` et par la recette sur téléphone.
- **Lire d'abord** : [`DRV-717`](DRV-717-adaptateur-auchan-squelette.md) (volet émulateur laissé de côté) ; skill `repas-android-dev` (verrou `/tmp/repas-android-e2e.lock`) ; `android/emulator/` (faux site en HTTPS, DNS, certificat).

## Contexte

DRV-717 a livré le faux site d'Auchan et prouvé la lecture du magasin dans l'agent (e2e Node) mais n'a pas touché à `android/emulator/e2e.sh`, que DRV-729 réaligne. L'application, elle, ne sert Auchan que dans la **variante de débogage** (DRV-728) : jamais dans l'APK de publication.

## Portée

Ajouter à l'émulateur l'hôte d'Auchan (DNS, certificat du faux site), puis **un scénario** : l'écran du drive s'ouvre sur Auchan (extra d'intention), « Choisir mon magasin » lit le drive par `GET /journey` contre le faux site, le libellé s'affiche, rien n'est écrit au panier ; et le **cas hostile** : la page de connexion (`compte.auchan.fr` du faux site) est naviguée **sans** pont ni agent. Aucun contact avec le vrai site.

## Critères d'acceptation

`make android-e2e` complet vert sous le verrou, avec le nouveau scénario ; l'APK de publication ne contient ni l'hôte ni le scénario d'Auchan.
