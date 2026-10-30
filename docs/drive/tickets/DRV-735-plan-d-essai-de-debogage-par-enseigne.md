# DRV-735 — Plan d'essai de la variante de débogage par enseigne

- **Dépôt** : front (`android/core/`, `android/app/src/debug/assets/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-728
- **Statut** : fait (PR front #265, 2026-10-30).
- **Lire d'abord** : [`DRV-728`](DRV-728-auchan-dans-le-coeur-et-l-application-android.md) ; [issue #322](https://github.com/kcharignon/repas/issues/322) (deuxième essai réel).

## Contexte

Le « Plan d'essai (débogage) » jouait le modèle de Leclerc : sur l'écran d'Auchan, l'agent répondait « Ce n'est pas le bon magasin ». Le « Test automatique » ne peut pas écrire au panier d'Auchan (il exige un produit « en stock »).

## Réalisation

`RetailerSite.debugPlanFile` (par défaut `debug-plan.<id>.json`, Leclerc garde `debug-plan.json`, identique à l'octet près) ; `DriveActivity`, `DebugRun` et `DebugPlan` reçoivent le `site` de l'écran (plus de défaut Leclerc) ; `debug-plan.auchan.json` dans `app/src/debug/assets/` (donnée, variante de **débogage** seulement : `build.sh verify-release` refuse tout `assets/debug-plan*.json` dans l'APK de publication) ; exception de `retailer-flag.spec.ts` pour ce seul chemin. Essai réel : `setup` lit le magasin d'Auchan et le propose, puis l'agent s'arrête sur « Tu n'es pas connecté à Auchan » (visiteur) ; l'écriture au panier reste à essayer **connecté** (code de vérification par e-mail, DRV-726).
