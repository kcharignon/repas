# DRV-756 — Suites de DRV-754 : petits résidus de la version mobile de Leclerc

- **Dépôt** : front (`drive-agent/`, `android/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S à M
- **Statut** : à faire (relevés à la relecture indépendante de la PR #300, aucun n'est bloquant)

## À faire

1. **Émulateur** (`android/emulator/*`, `make android-smoke`, `make android-e2e`, jamais joués par la CI faute de KVM) : les plans et le faux site portent encore l'hôte `courses-test.leclercdrive.fr`, désormais refusé ; migrer vers la variante mobile du faux site (hôte du magasin `fd<N>-courses`, pages sur `m-courses`), réécrire les scénarios « lisibilité » et zoom du menu qui supposaient le mode ordinateur.
2. **Expression des pages `mobile-…aspx`** : Kotlin `mobile-[^/]+\.aspx` est plus large que l'agent `/\/mobile-[a-z0-9_-]{1,40}\.aspx$/i` ; les aligner (une page `…/mobile-x.y.aspx` reçoit le plan mais l'agent prend l'ancien protocole).
3. **Course dans la pause du `Pacer`** : la session est relue avant chaque écriture, mais pas pendant la pause du `Pacer` (0,8 à 1,5 s) ni l'éventuel renvoi après coupure réseau ; une personne qui se déconnecte pile là écrirait dans un panier anonyme. Relire juste avant l'envoi réseau.
4. **Ancrage de la barre « Dernière connexion le… »** : limité au type d'élément qui la porte (`<h3>` seul donne `MEMBER`). Peu réaliste, à durcir.
5. **Retirer l'ancien protocole du site ordinateur** (`GET recherche.aspx`) de l'adaptateur Leclerc, resté sans route de production pour ses fixtures et tests.
6. `StoreManualForm` (site) refuse toujours une adresse `m-courses` collée à la main (DRV-741) : l'accepter.
7. `pacer-origin.spec.ts` utilise son propre prédicat : ajouter au test de `isServerOrigin` les cas `…@evil.com` et `:8443`.
