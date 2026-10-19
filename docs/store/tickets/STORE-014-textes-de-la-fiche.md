# STORE-014 — Textes de la fiche versionnés, avec tests de longueur, de voix et de marque

- **Dépôt** : front (`android/store/fr-FR/`, `android/__tests__/store-listing.spec.ts`) ; l'assistant relit chaque phrase avec le propriétaire
- **Jalon** : M3 Fiche du store
- **Taille** : S
- **Dépend de** : STORE-001 (D4)
- **Faisable sans téléphone ni compte Play** : oui
- **Bloquant pour AUTH-002 / la connexion Google** : non
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) §§ 6.c et 7 (brouillon des textes et contraintes) ; `docs/drive/principes-produit.md` (tutoiement, glossaire) ; skills `repas-po` et `repas-android-dev`.

## Contexte

La fiche est lue par des inconnus et par l'équipe de Google, qui exige des métadonnées fidèles à ce que fait l'application. Les textes sont rédigés une fois, versionnés dans le dépôt (publics, sans donnée personnelle), puis copiés dans la console (STORE-017). Un test évite les dérives (longueur, voix, nom de marque).

## Portée

1. Fichiers texte : `android/store/fr-FR/title.txt` (≤ 30 caractères), `short-description.txt` (≤ 80), `full-description.txt` (≤ 4 000), `alt-texts.txt` (un texte alternatif par capture, ≤ 140 caractères, sans « photo de »), `category.txt` (nom attendu, libellé exact lu dans la console au moment de STORE-017). Point de départ : le brouillon du § 7 de l'analyse, **relu phrase par phrase** avec le propriétaire (chaque phrase est vraie).
2. Règles : tutoiement ; glossaire de `principes-produit.md` (envoi, article, produit, panier, liste, « Moi », « drive ») ; phrases courtes ; pas de MAJUSCULES de titre, pas d'émoticône, pas de caractères répétés, pas de superlatif ni de promotion (« meilleur », « gratuit » en accroche, « télécharge ») ; **aucun nom d'enseigne** dans le titre et la description courte ; le nom de l'enseigne **une seule fois** dans la description longue, accompagné de « Repas n'est ni affilié à E.Leclerc, ni approuvé par lui » (si D4 = oui) ; la mention « ou avec Google » seulement si la connexion Google est livrée.
3. Test `store-listing.spec.ts` : longueurs, caractères interdits, absence de « vous / votre / vos » et des impératifs en « -ez », absence de mots interdits du glossaire, nombre d'occurrences du nom de l'enseigne, présence de la phrase de non-affiliation si le nom y est, **aucune adresse e-mail ni numéro de téléphone**.

## Hors portée

Les visuels (STORE-015) ; la saisie dans la console (STORE-017) ; une traduction.

## Critères d'acceptation

- Étant donné les fichiers, quand le test tourne, alors le titre fait 30 caractères au plus, la description courte 80, la longue 4 000.
- Étant donné la description longue, alors elle dit que l'envoi au drive est facultatif, que l'utilisateur se connecte lui-même, que Repas ne valide jamais de commande et ne voit jamais le mot de passe.
- Étant donné le titre, la description courte et les textes alternatifs, alors aucun ne contient le nom d'une enseigne.
- Étant donné le propriétaire, alors il a relu et confirmé chaque phrase (note dans la PR).

## Tests exigés

`store-listing.spec.ts` ; `make check`.

## Definition of Done

Celle du dépôt front ; description de PR : le décompte des caractères et la confirmation des phrases ; `CLAUDE.md` du front (ligne sur `android/store/`).

## Pièges connus

- « Les métadonnées ne doivent pas tromper » : ne pas décrire une fonction non livrée (connexion Google, rangement par rayon) ; vérifier dans l'application.
- La règle 10 de `repas-po` (aucun avertissement dans le parcours) ne s'applique pas à la fiche du store ; la phrase de non-affiliation est un fait, et non une mise en garde.
- Ne pas copier le nom du magasin, ni une capture, ni un texte de l'enseigne.
- Les guillemets typographiques et les apostrophes comptent pour un caractère chacun.
