# DRV-504 — Note de vie privée

- **Dépôt** : back + front
- **Jalon** : M5 Intégration et livraison
- **Taille** : S
- **Dépend de** : DRV-204
- **Statut** : partie back faite (PR [#152](https://github.com/kcharignon/repas/pull/152), 2026-10-18) ; partie front faite (note complète dans l'aide : repas-front#101 ; note courte : repas-front#105 ; posée dans les réglages : repas-front#106) ; textes relus par l'utilisateur le 2026-10-24 : **fait**
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 10 ; page de confidentialité ou mentions existantes du front si elles existent.

## Portée

Texte clair en français, affiché dans l'aide du drive et dans les réglages : ce que Repas enregistre (magasin choisi, produits mémorisés par ingrédient, ingrédients « toujours chez moi », statut et date d'envoi d'une liste), ce qu'il n'enregistre jamais (identifiants, mots de passe, cookies, adresse, créneau, paiement, contenu du panier Leclerc), où et combien de temps, comment tout supprimer (suppression du compte ; « Oublier » par produit), et que la fonction agit dans la session Leclerc de l'utilisateur sans passer par nos serveurs. Mettre à jour l'éventuel registre des traitements du projet.

## Critères d'acceptation

Le texte est relu, cohérent avec les invariants, sans promesse inexacte ; il est accessible depuis `/drive/reglages` et `/drive/aide`.

## À dire aussi (constat CGU du 2026-10-17, [DRV-003](../spikes/DRV-003-cgu.md))

Le texte doit prévenir chaque utilisateur que l'envoi au drive agit dans sa propre session Leclerc, que les conditions de Leclerc permettent à l'enseigne de suspendre un compte E.Leclerc (et la cagnotte de Tickets associée) en cas d'usage jugé abusif, que Repas ne voit jamais ses identifiants et que l'envoi se fait à faible cadence, à sa demande, pour sa seule liste.

## Réalisation, partie back (2026-10-18, PR #152)

- [`../note-vie-privee.md`](../note-vie-privee.md) : texte court (réglages du drive) et texte complet (aide du drive), français simple et tutoiement, prêts à intégrer dans `src/i18n/fr.ts` ; ils couvrent tout le périmètre du ticket et le constat CGU (session Leclerc de l'utilisateur, suspension possible du compte E.Leclerc et de la cagnotte de Tickets, identifiants jamais vus, cadence lente à sa demande pour sa seule liste).
- Même fichier : tableau « À garder vrai » (chaque phrase renvoie à sa source), fiche de registre des traitements (le dépôt n'a pas de registre : la fiche en tient lieu), liste pour l'intégration dans le front.
- **Reste à faire (front, autre ticket)** : afficher le texte court dans `/drive/reglages` et le texte complet dans `/drive/aide` (ancre `#vie-privee`), avant la première activation de l'envoi ; test du lien depuis les deux écrans. L'issue #86 reste ouverte pour cette partie. Cases de la roadmap : à cocher quand l'affichage est fait.
- **Écarts avec le ticket** : (1) « Oublier » existe **par ingrédient** (pas par produit) ; retirer un produit passe par `PUT /drive/choices/{slug}` (écran DRV-410, pas encore fait) ; (2) la suppression du compte est faite **par un administrateur** (ADR 0021), il n'y a pas d'auto-suppression ; (3) changer de magasin laisse les anciens choix en base jusqu'à la suppression du compte. Le texte dit ces trois choses telles qu'elles sont.

## Réalisation partie front (2026-10-18, PR repas-front#105)

Composant `DrivePrivacyNote.vue` (texte court, lien « Lisez la note complète » vers `/drive/aide#vie-privee`) ; textes en vouvoiement dans `drivePrivacy.short` / note complète dans l'aide. Reste : poser `<DrivePrivacyNote />` dans les réglages (DRV-501 partie front) ; faire relire les textes par l'utilisateur.

## Suite (audit de cohérence, 2026-10-18)

La note courte est posée en tête des réglages du drive (repas-front#106) et la note complète est dans l'aide (`/drive/aide#vie-privee`, repas-front#101) : le critère « accessible depuis `/drive/reglages` et `/drive/aide` » est rempli. Restent : (1) la **relecture des textes par l'utilisateur** (critère « le texte est relu ») ; (2) le front **vouvoie** (« Votre vie privée ») alors que `note-vie-privee.md` est écrit au tutoiement : choix de ton à confirmer, le fond est identique ; (3) la note du front dit désormais que les pages de Leclerc font appel à leurs propres services (protection contre les robots, mesure d'audience), phrase reprise dans `note-vie-privee.md` ; (4) le front n'a pas encore la phrase sur le **premier écrivain** d'une fiche de produit (DRV-613 partie 1) que porte `note-vie-privee.md`.
