# DRV-731 — Scénario d'émulateur pour la lecture du magasin d'Auchan

- **Dépôt** : front (`android/emulator/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-717 (faux site d'Auchan), DRV-729 (scénarios réalignés), DRV-728 (Auchan dans l'application, variante de débogage)
- **Statut** : à faire. Effort recommandé : `medium`.
- **Lire d'abord** : [`DRV-717`](DRV-717-adaptateur-auchan-squelette.md) (volet émulateur laissé de côté) ; skill `repas-android-dev` (verrou `/tmp/repas-android-e2e.lock`) ; `android/emulator/` (faux site en HTTPS, DNS, certificat).

## Contexte

DRV-717 a livré le faux site d'Auchan et prouvé la lecture du magasin dans l'agent (e2e Node) mais n'a pas touché à `android/emulator/e2e.sh`, que DRV-729 réaligne. L'application, elle, ne sert Auchan que dans la **variante de débogage** (DRV-728) : jamais dans l'APK de publication.

## Portée

Ajouter à l'émulateur l'hôte d'Auchan (DNS, certificat du faux site), puis **un scénario** : l'écran du drive s'ouvre sur Auchan (extra d'intention), « Choisir mon magasin » lit le drive par `GET /journey` contre le faux site, le libellé s'affiche, rien n'est écrit au panier ; et le **cas hostile** : la page de connexion (`compte.auchan.fr` du faux site) est naviguée **sans** pont ni agent. Aucun contact avec le vrai site.

## Critères d'acceptation

`make android-e2e` complet vert sous le verrou, avec le nouveau scénario ; l'APK de publication ne contient ni l'hôte ni le scénario d'Auchan.
