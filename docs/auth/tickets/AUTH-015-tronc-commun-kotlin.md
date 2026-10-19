# AUTH-015 — Application Android : isoler un tronc commun Kotlin (pour un iPhone plus tard)

- **Dépôt** : front (`android/`)
- **Jalon** : M3 Application Android
- **Taille** : M
- **Dépend de** : — (à faire **avant** AUTH-011 : il en fixe la forme)
- **Faisable sans téléphone** : oui (tests Kotlin purs, `make android-test`)
- **Statut** : à faire (décision D5 du propriétaire, 2026-10-19)
- **Lire d'abord** : [ADR 0024](../../adr/0024-connexion-avec-google.md) (« Décisions du propriétaire »), `android/README.md`, skill `repas-android-dev`.

## Contexte

« Fais juste en sorte que l'application puisse avoir un tronc commun Kotlin le jour où j'ai besoin de sortir sur iPhone. » On n'écrit **aucun code iOS** et on n'ajoute **pas** Kotlin Multiplatform maintenant : on prépare le terrain pour qu'un futur module partagé soit un déplacement de fichiers, pas une réécriture.

## Portée

1. **Inventaire** de ce que fait le Kotlin de `android/app` et classement : (a) logique sans dépendance Android (validation des messages du pont, `DriveEntry`, règles de `NavigationPolicy`, formes d'adresses, version et empreinte, décodage du plan) ; (b) services de plateforme (WebView, Credential Manager, stockage, ouverture d'un lien, écran allumé).
2. **Module Kotlin pur** (par exemple `android/core/`, sans aucun import `android.*` ni `androidx.*`, même dossier de tests) qui reçoit (a), compilé par le même `build.sh` / Gradle, testé par `make android-test`. Les classes d'Android en dépendent, jamais l'inverse.
3. **Interfaces** pour (b), au minimum celles dont AUTH-011 a besoin : `IdentityProvider` (obtenir un jeton d'identité : `signIn(nonce): Result`), `LinkOpener`, `SecureStore` si utilisé. Android les implémente ; une implémentation iOS viendra plus tard (hors portée).
4. Aucun changement de comportement visible : l'application fait exactement la même chose, les scénarios `make android-e2e` passent à l'identique.
5. Une page `android/ARCHITECTURE.md` (courte) : le schéma « cœur sans plateforme / services de plateforme », la règle d'or (« le cœur n'importe rien d'Android »), et ce qu'il faudrait faire pour passer à Kotlin Multiplatform (déplacer `core/` dans un module `shared`, `expect/actual`).

## Hors portée

Kotlin Multiplatform lui-même, tout code iOS, toute connexion Google (AUTH-011), tout changement de texte ou de protocole.

## Critères d'acceptation

- Aucun fichier de `android/core/` n'importe `android.*` ni `androidx.*` (un test ou un contrôle de build l'échoue sinon).
- Les tests Kotlin existants passent, déplacés ou non ; `make android-e2e` identique ; l'APK se construit à l'identique (`make agent-verify`, empreinte de l'agent inchangée).
- AUTH-011 peut s'écrire contre `IdentityProvider` sans toucher à la WebView.

## Tests exigés

`make android-test` ; un test d'architecture qui échoue si `core/` importe Android ; `make android-e2e` (sous le verrou de l'émulateur).

## Definition of Done

Celle du dépôt front ; aucune publication (pas de version d'application à publier pour ce seul ticket) ; description de PR : ce qui a bougé de place, ce qui n'a pas changé.

## Pièges connus

- Ne pas faire de « grand soir » : déplacer par petits morceaux, tests verts à chaque étape.
- `NavigationPolicy` touche la WebView : n'en extraire que les règles (chaînes, adresses), pas les appels d'Android.
